<?php

namespace Tests\Feature;

use App\Models\Game;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The scoreboard's +/- and turn buttons (GameController), which share
 * GameScoringService with the camera API.
 */
class ScoreboardTest extends TestCase
{
    use RefreshDatabase;

    private function game(array $attributes = []): Game
    {
        return Game::create($attributes + [
            'format' => '1v1',
            'game_type' => 'eight_ball',
            'target_score' => 8,
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    public function test_first_pot_on_an_open_table_asks_which_group(): void
    {
        $game = $this->game();

        $this->post(route('games.score', $game), ['side' => 'b', 'delta' => 1])
            ->assertRedirect(route('games.show', [$game, 'call_group' => 'b']));

        $this->assertSame(0, $game->refresh()->side_b_score);
    }

    public function test_answering_the_group_sets_both_groups_and_scores(): void
    {
        $game = $this->game();

        $this->post(route('games.score', $game), ['side' => 'b', 'delta' => 1, 'group' => 'solids'])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $this->assertSame(['stripes', 'solids', 1, 'b'],
            [$game->side_a_ball_group, $game->side_b_ball_group, $game->side_b_score, $game->shooting_side]);
    }

    public function test_minus_never_goes_below_zero_and_reaching_the_target_finishes(): void
    {
        $game = $this->game(['side_a_ball_group' => 'solids', 'side_b_ball_group' => 'stripes', 'side_a_score' => 7]);

        $this->post(route('games.score', $game), ['side' => 'b', 'delta' => -1]);
        $this->assertSame(0, $game->refresh()->side_b_score);

        $this->post(route('games.score', $game), ['side' => 'a', 'delta' => 1])
            ->assertRedirect(route('games.show', [$game, 'finished' => 1]));

        $game->refresh();
        $this->assertSame(['completed', 'a', 8], [$game->status, $game->winner_side, $game->side_a_score]);
    }

    public function test_turn_hands_the_table_over(): void
    {
        $game = $this->game(['shooting_side' => 'a']);

        $this->post(route('games.turn', $game), ['side' => 'b']);

        $this->assertSame('b', $game->refresh()->shooting_side);
    }
}
