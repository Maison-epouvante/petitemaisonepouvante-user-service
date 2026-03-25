<?php

namespace App\Services;

use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Illuminate\Support\Facades\Log;

class RabbitMQService
{
    protected $connection;
    protected $channel;

    public function __construct()
    {
        $this->connect();
    }

    protected function connect()
    {
        // Ne pas tenter la connexion si RabbitMQ est désactivé (ex: CI/tests)
        if (env('RABBITMQ_ENABLED', 'true') === 'false') {
            Log::info('RabbitMQ désactivé via RABBITMQ_ENABLED=false');
            return;
        }

        try {
            $this->connection = new AMQPStreamConnection(
                env('RABBITMQ_HOST', 'rabbitmq'),
                env('RABBITMQ_PORT', 5672),
                env('RABBITMQ_USER', 'admin'),
                env('RABBITMQ_PASSWORD', 'admin'),
                env('RABBITMQ_VHOST', '/')
            );
            $this->channel = $this->connection->channel();
            Log::info('RabbitMQ connection established successfully');
        } catch (\Exception $e) {
            // Ne pas crasher l'app si RabbitMQ est indisponible (CI, tests, démarrage)
            Log::warning('RabbitMQ indisponible : ' . $e->getMessage());
            $this->connection = null;
            $this->channel = null;
        }
    }

    public function declareUserCreatedQueue()
    {
        // Déclarer l'échange (exchange)
        $this->channel->exchange_declare(
            'user.exchange',    // nom de l'échange
            'topic',            // type
            false,              // passive
            true,               // durable
            false               // auto_delete
        );

        // Déclarer la queue
        $this->channel->queue_declare(
            'user.created.queue',  // nom de la queue
            false,                 // passive
            true,                  // durable
            false,                 // exclusive
            false                  // auto_delete
        );

        // Lier la queue à l'échange
        $this->channel->queue_bind(
            'user.created.queue',  // nom de la queue
            'user.exchange',       // nom de l'échange
            'user.created'         // routing key
        );

        Log::info('RabbitMQ queue and exchange declared successfully');
    }

    public function consume($callback)
    {
        $this->declareUserCreatedQueue();

        Log::info('Waiting for messages on user.created.queue');

        $this->channel->basic_consume(
            'user.created.queue',  // queue
            '',                    // consumer tag
            false,                 // no local
            false,                 // no ack (manuel acknowledgment)
            false,                 // exclusive
            false,                 // no wait
            $callback              // callback
        );

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    public function declareUserDeletedQueue()
    {
        // Déclarer l'échange (exchange) - même que pour created
        $this->channel->exchange_declare(
            'user.exchange',
            'topic',
            false,
            true,
            false
        );

        // Déclarer la queue pour les suppressions
        $this->channel->queue_declare(
            'user.deleted.queue',
            false,
            true,
            false,
            false
        );

        // Lier la queue à l'échange
        $this->channel->queue_bind(
            'user.deleted.queue',
            'user.exchange',
            'user.deleted'
        );

        Log::info('RabbitMQ user.deleted queue declared successfully');
    }

    public function consumeUserDeleted($callback)
    {
        $this->declareUserDeletedQueue();

        Log::info('Waiting for messages on user.deleted.queue');

        $this->channel->basic_consume(
            'user.deleted.queue',
            '',
            false,
            false,
            false,
            false,
            $callback
        );

        while ($this->channel->is_consuming()) {
            $this->channel->wait();
        }
    }

    /**
     * Publish user deleted event to RabbitMQ
     * This is used when a user is deleted from user-service
     * to notify auth-service
     */
    public function publishUserDeletedFromUserService($userId, $email)
    {
        if ($this->channel === null) {
            Log::warning("RabbitMQ indisponible, événement user.deleted non publié pour : $email");
            return;
        }

        try {
            // Déclarer l'échange
            $this->channel->exchange_declare(
                'user.exchange',
                'topic',
                false,
                true,
                false
            );

            $messageBody = json_encode([
                'userId' => $userId,
                'email' => $email
            ]);

            $message = new AMQPMessage($messageBody, [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
            ]);

            // Publier avec routing key différente pour identifier la source
            $this->channel->basic_publish(
                $message,
                'user.exchange',
                'user_service.deleted' // routing key différente
            );

            Log::info("Published user deleted event from user-service: $email (ID: $userId)");
        } catch (\Exception $e) {
            Log::error("Failed to publish user deleted event: " . $e->getMessage());
            throw $e;
        }
    }

    public function close()
    {
        if ($this->channel) {
            $this->channel->close();
        }
        if ($this->connection) {
            $this->connection->close();
        }
        Log::info('RabbitMQ connection closed');
    }

    public function __destruct()
    {
        $this->close();
    }
}
