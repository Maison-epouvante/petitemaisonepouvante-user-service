<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\RabbitMQService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Message\AMQPMessage;

class ConsumeUserCreatedEvents extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'rabbitmq:consume-user-created';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consume user created events from RabbitMQ and sync with user service database';

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
        $this->info('Starting RabbitMQ consumer for user.created events...');
        Log::info('RabbitMQ consumer started');

        $callback = function (AMQPMessage $msg) {
            try {
                $data = json_decode($msg->body, true);
                
                $this->info('Received user created event: ' . json_encode($data));
                Log::info('Processing user created event', $data);

                // Vérifier si l'utilisateur existe déjà
                $existingUser = User::where('email', $data['email'])->first();
                
                if ($existingUser) {
                    $this->warn("User with email {$data['email']} already exists. Skipping.");
                    Log::warning("User with email {$data['email']} already exists");
                    $msg->ack(); // Acknowledge le message pour ne pas le retraiter
                    return;
                }

                // Créer l'utilisateur dans le user-service
                $user = User::create([
                    'name' => $data['username'],
                    'email' => $data['email'],
                    'password' => $data['password'], // Le mot de passe est déjà encodé par le auth-service
                    'email_verified_at' => now(), // Optionnel: marquer l'email comme vérifié
                ]);

                $this->info("User created successfully: {$user->email} (ID: {$user->id})");
                Log::info("User synchronized successfully", [
                    'user_id' => $user->id,
                    'email' => $user->email,
                    'auth_service_user_id' => $data['userId']
                ]);

                // Acknowledge le message
                $msg->ack();

            } catch (\Exception $e) {
                $this->error('Error processing message: ' . $e->getMessage());
                Log::error('Error processing user created event', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
                
                // Rejeter le message et le remettre dans la queue si c'est une erreur temporaire
                // $msg->nack(true); // requeue = true
                
                // Ou rejeter définitivement si c'est une erreur permanente
                $msg->ack();
            }
        };

        try {
            $this->rabbitMQService->consume($callback);
        } catch (\Exception $e) {
            $this->error('Fatal error: ' . $e->getMessage());
            Log::error('RabbitMQ consumer fatal error: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
