<?php

namespace App\Services;

use App\Models\Game;
use App\Models\VisionEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns pot events from the pool-vision camera into score changes.
 *
 * The camera can be wrong, so it only does what is safe to undo with the
 * scoreboard's -/+ buttons, and records everything else for the operator:
 *
 * - solid / stripe on an open table: the shooter takes that group, +1
 * - solid / stripe with groups set: +1 to the side whose group it is
 *   (the shooter keeps the table; the camera can't see the shot end yet)
 * - cue (a scratch): the table passes to the other side
 * - the 8: recorded only. A misread would end the rack, so the operator calls it.
 *
 * Unsure reads (kind_conf below MIN_CONFIDENCE), unknown balls, non-eight-ball
 * games, and games with no shooter marked are recorded but not applied.
 */
class VisionEventService
{
    public const MIN_CONFIDENCE = 0.6;

    public function __construct(private readonly GameScoringService $scoring) {}

    /**
     * The live game at a table: the most recently started one, or the one with
     * this table label when given.
     */
    public function currentGame(?string $tableLabel = null): ?Game
    {
        return Game::live()
            ->when($tableLabel, fn ($query, $label) => $query->where('table_label', $label))
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Record one event and apply it. Sending the same event_id again returns the
     * first result without applying it twice, so the camera can retry freely.
     *
     * @param  array{event_id: string, type: string, kind: string, pocket?: ?string, kind_conf?: ?float, t?: ?float, table?: ?string}  $data
     */
    public function record(array $data): VisionEvent
    {
        if ($existing = VisionEvent::where('event_id', $data['event_id'])->first()) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($data) {
                $game = $this->currentGame($data['table'] ?? null);

                if ($game) {
                    $game = Game::whereKey($game->id)->lockForUpdate()->first(); // one score change at a time
                }

                [$applied, $outcome] = $this->apply($game, $data['kind'], $data['kind_conf'] ?? null);

                return VisionEvent::create([
                    'event_id' => $data['event_id'],
                    'game_id' => $game?->id,
                    'table_label' => $data['table'] ?? null,
                    'type' => $data['type'],
                    'kind' => $data['kind'],
                    'pocket' => $data['pocket'] ?? null,
                    'kind_conf' => $data['kind_conf'] ?? null,
                    'video_t' => $data['t'] ?? null,
                    'applied' => $applied,
                    'outcome' => $outcome,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            // the same event arrived twice at once; the other request recorded it
            return VisionEvent::where('event_id', $data['event_id'])->firstOrFail();
        }
    }

    /**
     * @return array{0: bool, 1: string} whether the score changed, and what happened
     */
    private function apply(?Game $game, string $kind, ?float $confidence): array
    {
        if (! $game || ! $game->isLive()) {
            return [false, 'No live game at this table.'];
        }

        if ($kind === 'eight') {
            return [false, '8 ball potted. Call the game on the scoreboard.'];
        }

        $shooter = $game->shooting_side;

        if ($shooter === null) {
            return [false, 'Nobody is marked as shooting. Tap the shooter on the scoreboard.'];
        }

        if ($kind === 'cue') {
            $next = Game::otherSide($shooter);
            $this->scoring->passTurn($game, $next);

            return [true, 'Scratch: the table passes to side '.strtoupper($next).'.'];
        }

        if (! in_array($kind, ['solid', 'stripe'], true)) {
            return [false, 'A ball went in, but the camera could not tell which kind.'];
        }

        if ($game->game_type !== 'eight_ball') {
            return [false, 'Only eight-ball games are scored from the camera.'];
        }

        if ($confidence !== null && $confidence < self::MIN_CONFIDENCE) {
            return [false, "A {$kind} went in, but the camera wasn't sure. Check the scoreboard."];
        }

        $group = $kind === 'solid' ? 'solids' : 'stripes';

        if ($game->tableIsOpen()) {
            $this->scoring->addPot($game, $shooter, 1, $group);

            return [true, 'Side '.strtoupper($shooter)." takes {$group}."];
        }

        $owner = $game->side_a_ball_group === $group ? 'a' : ($game->side_b_ball_group === $group ? 'b' : null);

        if ($owner === null) {
            return [false, "Nobody has {$group}. Check the groups on the scoreboard."];
        }

        // In eight-ball the last point is the 8 itself; group balls alone never finish the rack.
        if ($game->score($owner) >= (int) $game->target_score - 1) {
            return [false, 'Side '.strtoupper($owner)." already has all its {$group}. Check the scoreboard."];
        }

        $this->scoring->addPot($game, $owner, 1, takeTable: false);

        return [true, '+1 to side '.strtoupper($owner)." ({$group})."];
    }
}
