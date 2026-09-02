<?php

namespace App\Services;

use App\Models\Game;
use App\Models\QueueEntry;
use Illuminate\Support\Facades\DB;

class QueueService
{
    /**
     * Add a player or pair to the queue.
     *
     * @param  array<int, int|string>  $playerIds
     */
    public function enqueue(array $playerIds, ?string $label = null, bool $front = false): ?QueueEntry
    {
        $playerIds = $this->normalizeIds($playerIds);

        if ($playerIds === []) {
            return null;
        }

        return DB::transaction(function () use ($playerIds, $label, $front) {
            // A player can only wait in one place at a time.
            $this->removePlayers($playerIds, resequence: false);

            if ($front) {
                QueueEntry::query()->increment('position');
                $position = 1;
            } else {
                $position = (int) QueueEntry::max('position') + 1;
            }

            $entry = QueueEntry::create([
                'position' => $position,
                'label' => $label,
            ]);

            $entry->players()->sync($playerIds);

            $this->resequence();

            return $entry;
        });
    }

    public function remove(QueueEntry $entry): void
    {
        DB::transaction(function () use ($entry) {
            $entry->players()->detach();
            $entry->delete();
            $this->resequence();
        });
    }

    /**
     * Pull specific players out of the queue, dropping any entry left empty.
     *
     * @param  array<int, int|string>  $playerIds
     */
    public function removePlayers(array $playerIds, bool $resequence = true): void
    {
        $playerIds = $this->normalizeIds($playerIds);

        if ($playerIds === []) {
            return;
        }

        DB::transaction(function () use ($playerIds, $resequence) {
            DB::table('queue_entry_players')->whereIn('player_id', $playerIds)->delete();

            QueueEntry::query()->doesntHave('players')->get()->each->delete();

            if ($resequence) {
                $this->resequence();
            }
        });
    }

    /**
     * Swap an entry with its neighbour. Negative delta moves it up the list.
     */
    public function move(QueueEntry $entry, int $delta): void
    {
        DB::transaction(function () use ($entry, $delta) {
            $neighbour = $delta < 0
                ? QueueEntry::query()->where('position', '<', $entry->position)->orderByDesc('position')->first()
                : QueueEntry::query()->where('position', '>', $entry->position)->orderBy('position')->first();

            if (! $neighbour) {
                return;
            }

            $original = $entry->position;

            $entry->forceFill(['position' => $neighbour->position])->save();
            $neighbour->forceFill(['position' => $original])->save();
        });
    }

    public function resequence(): void
    {
        $position = 1;

        foreach (QueueEntry::ordered()->get() as $entry) {
            if ($entry->position !== $position) {
                $entry->forceFill(['position' => $position])->save();
            }

            $position++;
        }
    }

    /**
     * Close out a game and, by default, put the losing side back in line. The
     * winner's next move (stay on, or step off) is chosen from the game-over
     * screen, so they are never requeued automatically here.
     */
    public function finishGame(Game $game, string $winnerSide, bool $requeueLoser = true): void
    {
        DB::transaction(function () use ($game, $winnerSide, $requeueLoser) {
            $game->forceFill([
                'winner_side' => $winnerSide,
                'status' => 'completed',
                'completed_at' => now(),
            ])->save();

            $game->load('players');

            if ($requeueLoser) {
                $this->enqueue($game->sidePlayers(Game::otherSide($winnerSide))->pluck('id')->all());
            }
        });
    }

    /**
     * @param  array<int, int|string>  $playerIds
     * @return array<int, int>
     */
    private function normalizeIds(array $playerIds): array
    {
        return array_values(array_unique(array_filter(array_map('intval', $playerIds))));
    }
}
