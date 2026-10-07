<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\VisionDevices;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The in-browser camera page (/vision) and phone pairing.
 */
class VisionAppTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_camera_page_loads_with_the_table_picker(): void
    {
        $this->get('/vision')
            ->assertOk()
            ->assertSee('Which table is this?')
            ->assertSee('data-size="7ft"', false)
            ->assertSee('data-size="8ft"', false)
            ->assertSee('data-size="9ft"', false)
            ->assertSee('vision-app/app.js', false);
    }

    public function test_pairing_is_off_without_a_password_in_the_env(): void
    {
        config(['services.vision.pair_password' => null]);

        $this->get('/vision')->assertSee('Pairing is off on the server');
        $this->postJson('/vision/pair', ['name' => 'table-1', 'password' => 'anything'])->assertStatus(503);
    }

    public function test_a_wrong_password_gets_no_token(): void
    {
        config(['services.vision.pair_password' => 'chalk-it-up']);

        $this->postJson('/vision/pair', ['name' => 'table-1', 'password' => 'nope'])->assertForbidden();
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_the_right_password_pairs_the_phone_with_a_vision_token(): void
    {
        config(['services.vision.pair_password' => 'chalk-it-up']);

        $token = $this->postJson('/vision/pair', ['name' => 'table-1', 'password' => 'chalk-it-up'])
            ->assertOk()
            ->json('token');

        $this->withToken($token)->getJson('/api/vision/game')->assertNotFound(); // authorized, just no live game
        $device = User::where('email', VisionDevices::DEVICE_EMAIL)->firstOrFail();
        $this->assertSame(['vision'], $device->tokens()->first()->abilities);
    }

    public function test_camera_names_are_checked(): void
    {
        config(['services.vision.pair_password' => 'chalk-it-up']);

        $this->postJson('/vision/pair', ['name' => '<script>', 'password' => 'chalk-it-up'])->assertUnprocessable();
    }
}
