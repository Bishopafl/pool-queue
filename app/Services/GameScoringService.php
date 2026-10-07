<?php

namespace App\Services;

use App\Models\Game;

/**
 * Score changes shared by the scoreboard buttons (GameController) and the
 * pool-vision camera (VisionEventService), so both follow the same rules.
 */
class GameScoringService
{
    public function __construct(private readonly QueueService $queue) {}

    /**
     * Bump a side's pocketed-ball count. A group named here ("stripes" / "solids")
     * is assigned to that side, and its complement to the other. Reaching the
     * target finishes the rack.
     *
     * $takeTable: the pot also makes that side the shooter. The scoreboard
     * assumes so (whoever pocketed is at the table); the camera turns it off
     * when a shooter pockets the opponent's ball.
     *
     * @return bool whether the rack is now finished
     */
    public function addPot(Game $game, string $side, int $delta = 1, ?string $group = null, bool $takeTable = true): bool
    {
        if ($group !== null && $group !== 'open') {
            $game->{'side_'.$side.'_ball_group'} = $group;
            $game->{'side_'.Game::otherSide($side).'_ball_group'} = Game::otherBallGroup($group);
        }

        if ($delta > 0 && $takeTable) {
            $game->shooting_side = $side;
        }

        $column = 'side_'.$side.'_score';
        $target = (int) $game->target_score;
        $game->{$column} = max(0, min($target, (int) $game->{$column} + $delta));
        $game->save();

        if ($game->{$column} >= $target) {
            $this->queue->finishGame(game: $game, winnerSide: $side, requeueLoser: true);

            return true;
        }

        return false;
    }

    /**
     * Hand the table to a side.
     */
    public function passTurn(Game $game, string $toSide): void
    {
        if ($game->isLive()) {
            $game->update(['shooting_side' => $toSide]);
        }
    }
}
