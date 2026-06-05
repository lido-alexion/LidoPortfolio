<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RequestCorrelationTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_echoes_and_preserves_x_request_id(): void
    {
        $user = User::query()->create([
            'name' => 'Correlation User',
            'email' => 'corr-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $this->actingAs($user);

        $requestId = (string) Str::uuid();

        $response = $this->getJson('/api/auth/me', [
            'X-Request-ID' => $requestId,
        ]);

        $response->assertOk();
        $response->assertHeader('X-Request-ID', $requestId);
    }

    public function test_api_generates_request_id_when_missing(): void
    {
        $user = User::query()->create([
            'name' => 'Correlation User 2',
            'email' => 'corr2-'.Str::random(8).'@example.com',
            'password' => 'password123',
        ]);

        $this->actingAs($user);

        $response = $this->getJson('/api/auth/me');

        $response->assertOk();
        $this->assertNotEmpty($response->headers->get('X-Request-ID'));
    }
}
