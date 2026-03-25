<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_list_all_users()
    {
        // Arrange
        User::factory()->count(3)->create();

        // Act
        $response = $this->getJson('/api/users');

        // Assert
        $response->assertStatus(200);
        $response->assertJsonCount(3);
    }

    public function test_can_create_user()
    {
        // Arrange
        $userData = [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'password' => 'password123'
        ];

        // Act
        $response = $this->postJson('/api/users', $userData);

        // Assert
        $response->assertStatus(201);
        $this->assertDatabaseHas('users', [
            'email' => 'john@example.com'
        ]);
    }

    public function test_can_show_user()
    {
        // Arrange
        $user = User::factory()->create();

        // Act
        $response = $this->getJson("/api/users/{$user->id}");

        // Assert
        $response->assertStatus(200);
        $response->assertJson([
            'id' => $user->id,
            'email' => $user->email
        ]);
    }

    public function test_can_delete_user()
    {
        // Arrange
        $user = User::factory()->create();

        // Act
        $response = $this->deleteJson("/api/users/{$user->id}");

        // Assert
        $response->assertStatus(200);
        $this->assertDatabaseMissing('users', [
            'id' => $user->id
        ]);
    }
}
