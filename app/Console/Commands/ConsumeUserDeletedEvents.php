<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\RabbitMQService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

class ConsumeUserDeletedEvents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:consume-user-deleted';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consume user deleted events from RabbitMQ and sync with user service database';

    protected $rabbitMQService;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct(RabbitMQService $rabbitMQService)
    {
        parent::__construct();
        $this->rabbitMQService = $rabbitMQService;
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Starting RabbitMQ consumer for user.deleted events...');
        Log::info('RabbitMQ user.deleted consumer started');

        $callback = function (AMQPMessage $msg) {
            try {
                $data = json_decode($msg->body, true);
                
                $this->info('Received user deleted event: ' . json_encode($data));
                Log::info('Processing user deleted event', $data);

                // Chercher l'utilisateur par email
                $user = User::where('email', $data['email'])->first();
                
                if (!$user) {
                    $this->warn("User with email {$data['email']} not found. Already deleted?");
                    Log::warning("User with email {$data['email']} not found");
                    $msg->ack();
                    return;
                }

                // Supprimer l'utilisateur
                $userId = $user->id;
                $user->delete();

                $this->info("User deleted successfully: {$data['email']} (ID: {$userId})");
                Log::info("User deleted successfully", [
                    'user_id' => $userId,
                    'email' => $data['email'],
                    'auth_service_user_id' => $data['userId']
                ]);

                // Acknowledge le message
                $msg->ack();

            } catch (\Exception $e) {
                $this->error('Error processing message: ' . $e->getMessage());
                Log::error('Error processing user deleted event', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                $msg->ack();
            }
        };

        try {
            $this->rabbitMQService->consumeUserDeleted($callback);
        } catch (\Exception $e) {
            $this->error('Fatal error: ' . $e->getMessage());
            Log::error('RabbitMQ user.deleted consumer fatal error: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
