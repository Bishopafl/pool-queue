<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\VisionDevices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Pairing a Pool Vision phone (poolvision.adamlopez.co) with the camera API, and the
 * cross-origin rules that let that site call it.
 */
class VisionPairTest extends TestCase
{
    use RefreshDatabase;

    private const ORIGIN = 'https://poolvision.adamlopez.co';

    public function test_the_camera_page_is_not_served_here_any_more(): void
    {
        $this->get('/vision')->assertNotFound();
    }

    public function test_pairing_is_off_without_a_password_in_the_env(): void
    {
        config(['services.vision.pair_password' => null]);

        $this->postJson('/api/vision/pair', ['name' => 'table-1', 'password' => 'anything'])->assertStatus(503);
    }

    public function test_a_wrong_password_gets_no_token(): void
    {
        config(['services.vision.pair_password' => 'chalk-it-up']);

        $this->postJson('/api/vision/pair', ['name' => 'table-1', 'password' => 'nope'])->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_the_right_password_pairs_the_phone_with_a_vision_token(): void
    {
        config(['services.vision.pair_password' => 'chalk-it-up']);

        $token = $this->postJson('/api/vision/pair', ['name' => 'table-1', 'password' => 'chalk-it-up'])
            ->assertOk()
            ->json('token');

        $this->withToken($token)->getJson('/api/vision/game')->assertNotFound(); // authorized, just no live game
        $device = User::where('email', VisionDevices::DEVICE_EMAIL)->firstOrFail();
        $this->assertSame(['vision'], $device->tokens()->first()->abilities);
    }

    public function test_camera_names_are_checked(): void
    {
        config(['services.vision.pair_password' => 'chalk-it-up']);

        $this->postJson('/api/vision/pair', ['name' => '<script>', 'password' => 'chalk-it-up'])->assertUnprocessable();
    }

    public function test_the_pool_vision_site_may_call_the_camera_api_from_the_browser(): void
    {
        $this->call('OPTIONS', '/api/vision/events', server: [
            'HTTP_ORIGIN' => self::ORIGIN,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', self::ORIGIN);

        $this->getJson('/api/vision/game', ['Origin' => self::ORIGIN])
            ->assertUnauthorized()
            ->assertHeader('Access-Control-Allow-Origin', self::ORIGIN);
    }

    public function test_other_sites_may_not(): void
    {
        $allowed = $this->call('OPTIONS', '/api/vision/pair', server: [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->headers->get('Access-Control-Allow-Origin');

        // the browser refuses any origin but the one named (one allowed origin is always sent back)
        $this->assertNotContains($allowed, ['https://evil.example', '*']);
    }
}
