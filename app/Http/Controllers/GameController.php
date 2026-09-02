<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Player;
use App\Models\QueueEntry;
use App\Services\QueueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GameController extends Controller
{
    public function __construct(private readonly QueueService $queue)
    {
    }

    public function index(Request $request): View
    {
        $games = Game::query()
            ->whereIn('status', ['completed', 'abandoned'])
            ->with('players')
            ->when($request->string('format')->toString(), fn ($query, $format) => $query->where('format', $format))
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return view('games.index', [
            'games' => $games,
            'format' => $request->string('format')->toString(),
        ]);
    }

    public function create(Request $request): View
    {
        $gameType = $request->query('game_type');
        $gameType = array_key_exists($gameType, Game::GAME_TYPES) ? $gameType : 'eight_ball';

        $ballsToWin = (int) $request->query('balls_to_win');
        if ($ballsToWin < 1 || $ballsToWin > 15) {
            $ballsToWin = Game::defaultTargetFor($gameType);
        }

        $format = $request->query('format');

        return view('games.create', [
            'players' => Player::active()->orderBy('name')->get(),
            'preassigned' => [
                'a' => $this->parseIdList($request->query('a')),
                'b' => $this->parseIdList($request->query('b')),
            ],
            'gameType' => $gameType,
            'ballsToWin' => $ballsToWin,
            'formatChoice' => in_array($format, ['1v1', '2v1', '2v2'], true) ? $format : '1v1',
            'carryFrom' => $this->parseIdList($request->query('carry_from'))[0] ?? null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'format' => ['required', 'in:1v1,2v1,2v2'],
            'game_type' => ['required', 'in:' . implode(',', array_keys(Game::GAME_TYPES))],
            'balls_to_win' => ['required', 'integer', 'between:1,15'],
            'carry_from' => ['nullable', 'integer', 'exists:games,id'],
            'table_label' => ['nullable', 'string', 'max:40'],
            'break_side' => ['nullable', 'in:a,b,none'],
            'assign' => ['required', 'array'],
            'assign.*' => ['nullable', 'in:a,b,out'],
        ]);

        $sideA = [];
        $sideB = [];

        foreach ($data['assign'] as $playerId => $side) {
            if ($side === 'a') {
                $sideA[] = (int) $playerId;
            } elseif ($side === 'b') {
                $sideB[] = (int) $playerId;
            }
        }

        $this->assertLineupMatchesFormat($data['format'], $sideA, $sideB);
        $this->assertPlayersAreFree(array_merge($sideA, $sideB));

        $carry = $this->resolveCarry($data['carry_from'] ?? null, $sideA, $sideB);

        $game = DB::transaction(function () use ($data, $sideA, $sideB, $carry) {
            $game = Game::create([
                'format' => $data['format'],
                'game_type' => $data['game_type'],
                'target_score' => $data['balls_to_win'],
                'status' => 'in_progress',
                'table_label' => $data['table_label'] ?? null,
                'break_side' => $this->normalizeSide($data['break_side'] ?? null),
                'started_at' => now(),
                'previous_game_id' => $carry['previous_game_id'],
                'champion_side' => $carry['champion_side'],
                'win_streak' => $carry['win_streak'],
            ]);

            $attach = [];

            foreach ($sideA as $id) {
                $attach[$id] = ['side' => 'a'];
            }

            foreach ($sideB as $id) {
                $attach[$id] = ['side' => 'b'];
            }

            $game->players()->attach($attach);

            // Anyone racking up is no longer waiting.
            $this->queue->removePlayers(array_merge($sideA, $sideB));

            return $game;
        });

        return redirect()
            ->route('games.show', $game)
            ->with('status', 'Game started. Table is open until someone pockets a ball.');
    }

    public function show(Request $request, Game $game): View
    {
        $game->load('players', 'previousGame');

        // Who the modal's "winner stays on" button lines up as the next challenger.
        $nextChallengerIds = '';

        if ($request->boolean('finished') && $game->isCompleted() && $game->winner_side) {
            $winnerIds = $game->sidePlayers($game->winner_side)->pluck('id')->all();

            $next = QueueEntry::ordered()->with('players')->get()
                ->first(fn (QueueEntry $entry) => $entry->players->isNotEmpty()
                    && $entry->players->pluck('id')->intersect($winnerIds)->isEmpty());

            $nextChallengerIds = $next ? $next->players->pluck('id')->join(',') : '';
        }

        return view('games.show', [
            'game' => $game,
            'nextChallengerIds' => $nextChallengerIds,
        ]);
    }

    /**
     * Table details: who broke, which group each side is shooting, notes.
     */
    public function update(Request $request, Game $game): RedirectResponse
    {
        $data = $request->validate([
            'table_label' => ['nullable', 'string', 'max:40'],
            'break_side' => ['nullable', 'in:a,b,none'],
            'ball_group_side' => ['nullable', 'in:a,b'],
            'ball_group' => ['nullable', 'in:stripes,solids,open'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if ($request->has('table_label')) {
            $game->table_label = $data['table_label'] ?? null;
        }

        if ($request->has('break_side')) {
            $game->break_side = $this->normalizeSide($data['break_side'] ?? null);
        }

        if ($request->has('notes')) {
            $game->notes = $data['notes'] ?? null;
        }

        // Assigning a group to one side always assigns the complement to the other.
        if (! empty($data['ball_group_side']) && ! empty($data['ball_group'])) {
            $side = $data['ball_group_side'];
            $group = $data['ball_group'] === 'open' ? null : $data['ball_group'];

            $game->{'side_' . $side . '_ball_group'} = $group;
            $game->{'side_' . Game::otherSide($side) . '_ball_group'} = Game::otherBallGroup($group);
        }

        $game->save();

        return back()->with('status', 'Table updated.');
    }

    /**
     * Bump a side's pocketed-ball count. Hitting the target ends the rack and
     * sends the scoreboard to its "game over" state.
     */
    public function score(Request $request, Game $game): RedirectResponse
    {
        $data = $request->validate([
            'side' => ['required', 'in:a,b'],
            'delta' => ['required', 'integer', 'between:-1,1'],
        ]);

        if (! $game->isLive()) {
            return back();
        }

        $side = $data['side'];
        $column = 'side_' . $side . '_score';
        $target = (int) $game->target_score;

        $game->{$column} = max(0, min($target, (int) $game->{$column} + (int) $data['delta']));
        $game->save();

        if ($game->{$column} >= $target) {
            $this->queue->finishGame(game: $game, winnerSide: $side, requeueLoser: true);

            return redirect()->route('games.show', [$game, 'finished' => 1]);
        }

        return back();
    }

    /**
     * Call the game manually (early 8-ball, scratch on the 8, conceded rack).
     * Ends on the same "game over" screen as an auto-finish.
     */
    public function finish(Request $request, Game $game): RedirectResponse
    {
        $data = $request->validate([
            'winner_side' => ['required', 'in:a,b'],
            'requeue_loser' => ['nullable', 'boolean'],
        ]);

        if ($game->isCompleted()) {
            return redirect()->route('games.show', [$game, 'finished' => 1]);
        }

        $winnerSide = $data['winner_side'];

        // A called game with no ball count recorded still shows a 1-0 line.
        if ($game->side_a_score === 0 && $game->side_b_score === 0) {
            $game->forceFill(['side_' . $winnerSide . '_score' => 1])->save();
        }

        $this->queue->finishGame(
            game: $game,
            winnerSide: $winnerSide,
            requeueLoser: (bool) ($data['requeue_loser'] ?? true),
        );

        return redirect()->route('games.show', [$game, 'finished' => 1]);
    }

    public function destroy(Game $game): RedirectResponse
    {
        $game->players()->detach();
        $game->delete();

        return redirect()->route('queue.index')->with('status', 'Game deleted.');
    }

    /**
     * 2v1 may be racked either way round, so counts are compared unordered.
     *
     * @param  array<int, int>  $sideA
     * @param  array<int, int>  $sideB
     */
    private function assertLineupMatchesFormat(string $format, array $sideA, array $sideB): void
    {
        $expected = Game::FORMATS[$format];
        $actual = [count($sideA), count($sideB)];

        sort($expected);
        sort($actual);

        if ($expected !== $actual) {
            throw ValidationException::withMessages([
                'assign' => sprintf(
                    'A %s game needs %d and %d players. You picked %d and %d.',
                    $format,
                    Game::FORMATS[$format][0],
                    Game::FORMATS[$format][1],
                    count($sideA),
                    count($sideB),
                ),
            ]);
        }
    }

    /**
     * @param  array<int, int>  $playerIds
     */
    private function assertPlayersAreFree(array $playerIds): void
    {
        $known = Player::query()->whereIn('id', $playerIds)->pluck('id');

        if ($known->count() !== count($playerIds)) {
            throw ValidationException::withMessages([
                'assign' => 'One of the players in that lineup no longer exists. Reload and try again.',
            ]);
        }

        $busy = Player::query()
            ->whereIn('players.id', $playerIds)
            ->whereHas('games', fn ($query) => $query->where('games.status', 'in_progress'))
            ->pluck('name');

        if ($busy->isNotEmpty()) {
            throw ValidationException::withMessages([
                'assign' => $busy->join(', ') . ' ' . ($busy->count() === 1 ? 'is' : 'are') . ' already in a live game.',
            ]);
        }
    }

    private function normalizeSide(?string $side): ?string
    {
        return in_array($side, ['a', 'b'], true) ? $side : null;
    }

    /**
     * Work out whether this new game continues a winner-stays streak, and on
     * which side the crown now sits.
     *
     * @param  array<int, int>  $sideA
     * @param  array<int, int>  $sideB
     * @return array{previous_game_id: int|null, champion_side: string|null, win_streak: int}
     */
    private function resolveCarry(?int $carryFrom, array $sideA, array $sideB): array
    {
        $none = ['previous_game_id' => null, 'champion_side' => null, 'win_streak' => 0];

        if (! $carryFrom) {
            return $none;
        }

        $previous = Game::with('players')->find($carryFrom);

        if (! $previous || ! $previous->isCompleted() || ! $previous->winner_side) {
            return $none;
        }

        $championIds = $previous->sidePlayers($previous->winner_side)->pluck('id')->all();

        if ($championIds === []) {
            return $none;
        }

        $championSide = match (true) {
            array_diff($championIds, $sideA) === [] => 'a',
            array_diff($championIds, $sideB) === [] => 'b',
            default => null,
        };

        if ($championSide === null) {
            return $none;
        }

        return [
            'previous_game_id' => $previous->id,
            'champion_side' => $championSide,
            'win_streak' => $previous->championDefended() ? (int) $previous->win_streak + 1 : 1,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function parseIdList(?string $value): array
    {
        if (! $value) {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $value))));
    }
}
