<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\User;
use App\Models\VisionEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VisionApiTest extends TestCase
{
    use RefreshDatabase;

    private function game(array $attributes = []): Game
    {
        return Game::create($attributes + [
            'format' => '1v1',
            'game_type' => 'eight_ball',
            'target_score' => 8,
            'status' => 'in_progress',
            'shooting_side' => 'a',
            'started_at' => now(),
        ]);
    }

    private function asCamera(array $abilities = ['vision']): void
    {
        Sanctum::actingAs(User::factory()->create(), $abilities);
    }

    private function pot(string $kind, array $extra = []): TestResponse
    {
        return $this->postJson('/api/vision/events', $extra + [
            'event_id' => (string) Str::uuid(),
            'type' => 'pot',
            'kind' => $kind,
            'pocket' => 'top side',
            'kind_conf' => 0.9,
            't' => 12.5,
        ]);
    }

    public function test_requests_need_a_token_with_the_vision_ability(): void
    {
        $this->getJson('/api/vision/game')->assertUnauthorized();

        $this->asCamera(['something-else']);
        $this->getJson('/api/vision/game')->assertForbidden();
    }

    public function test_current_game_is_the_latest_live_one_or_the_named_table(): void
    {
        $this->asCamera();
        $this->getJson('/api/vision/game')->assertNotFound();

        $this->game(['table_label' => 'Back', 'started_at' => now()->subHour()]);
        $front = $this->game(['table_label' => 'Front']);

        $this->getJson('/api/vision/game')->assertOk()->assertJsonPath('game.id', $front->id);
        $this->getJson('/api/vision/game?table=Back')->assertOk()->assertJsonPath('game.table_label', 'Back');
    }

    public function test_first_pot_on_an_open_table_gives_the_shooter_that_group(): void
    {
        $this->asCamera();
        $game = $this->game();

        $this->pot('stripe')->assertCreated()->assertJsonPath('applied', true);

        $game->refresh();
        $this->assertSame('stripes', $game->side_a_ball_group);
        $this->assertSame('solids', $game->side_b_ball_group);
        $this->assertSame(1, $game->side_a_score);
    }

    public function test_opponents_ball_scores_for_the_opponent_and_the_shooter_keeps_the_table(): void
    {
        $this->asCamera();
        $game = $this->game(['side_a_ball_group' => 'stripes', 'side_b_ball_group' => 'solids']);

        $this->pot('solid')->assertCreated()->assertJsonPath('applied', true);

        $game->refresh();
        $this->assertSame(1, $game->side_b_score);
        $this->assertSame(0, $game->side_a_score);
        $this->assertSame('a', $game->shooting_side);
    }

    public function test_a_retried_event_is_not_counted_twice(): void
    {
        $this->asCamera();
        $game = $this->game();
        $id = (string) Str::uuid();

        $this->pot('solid', ['event_id' => $id])->assertCreated();
        $this->pot('solid', ['event_id' => $id])->assertOk()->assertJsonPath('applied', true);

        $this->assertSame(1, $game->refresh()->side_a_score);
        $this->assertSame(1, VisionEvent::count());
    }

    public function test_scratch_passes_the_table(): void
    {
        $this->asCamera();
        $game = $this->game();

        $this->pot('cue')->assertCreated()->assertJsonPath('applied', true);

        $this->assertSame('b', $game->refresh()->shooting_side);
    }

    public function test_the_eight_and_unsure_reads_are_recorded_but_not_applied(): void
    {
        $this->asCamera();
        $game = $this->game(['side_a_ball_group' => 'stripes', 'side_b_ball_group' => 'solids', 'side_a_score' => 7]);

        $this->pot('eight')->assertCreated()->assertJsonPath('applied', false);
        $this->pot('stripe', ['kind_conf' => 0.4])->assertCreated()->assertJsonPath('applied', false);
        // a side with all 7 of its group can only finish on the 8
        $this->pot('stripe')->assertCreated()->assertJsonPath('applied', false);

        $game->refresh();
        $this->assertTrue($game->isLive());
        $this->assertSame(7, $game->side_a_score);
        $this->assertSame(3, VisionEvent::where('applied', false)->count());
    }

    public function test_no_live_game_or_no_shooter_is_recorded_not_applied(): void
    {
        $this->asCamera();
        $this->pot('solid')->assertCreated()->assertJsonPath('applied', false)->assertJsonPath('game', null);

        $game = $this->game(['shooting_side' => null]);
        $this->pot('solid')->assertCreated()->assertJsonPath('applied', false);
        $this->assertSame(0, $game->refresh()->side_a_score);
    }

    public function test_bad_events_are_rejected(): void
    {
        $this->asCamera();
        $this->pot('purple')->assertUnprocessable();
        $this->pot('solid', ['event_id' => 'not-a-uuid'])->assertUnprocessable();
    }

    public function test_token_command_issues_a_vision_token(): void
    {
        $this->artisan('pool-vision:token', ['name' => 'table-1'])->assertSuccessful();

        $device = User::where('email', 'pool-vision@devices.invalid')->firstOrFail();
        $this->assertSame(['vision'], $device->tokens()->first()->abilities);

        $this->artisan('pool-vision:token', ['name' => 'table-1', '--revoke' => true])->assertSuccessful();
        $this->assertSame(0, $device->tokens()->count());
    }
}
