<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Player;
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
        return view('games.create', [
            'players' => Player::active()->orderBy('name')->get(),
            'preassigned' => [
                'a' => $this->parseIdList($request->query('a')),
                'b' => $this->parseIdList($request->query('b')),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'format' => ['required', 'in:1v1,2v1,2v2'],
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

        $game = DB::transaction(function () use ($data, $sideA, $sideB) {
            $game = Game::create([
                'format' => $data['format'],
                'status' => 'in_progress',
                'table_label' => $data['table_label'] ?? null,
                'break_side' => $this->normalizeSide($data['break_side'] ?? null),
                'started_at' => now(),
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

    public function show(Game $game): View
    {
        $game->load('players');

        return view('games.show', ['game' => $game]);
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
     * Bump a side's rack count up or down.
     */
    public function score(Request $request, Game $game): RedirectResponse
    {
        $data = $request->validate([
            'side' => ['required', 'in:a,b'],
            'delta' => ['required', 'integer', 'between:-1,1'],
        ]);

        $column = 'side_' . $data['side'] . '_score';
        $game->{$column} = max(0, (int) $game->{$column} + (int) $data['delta']);
        $game->save();

        return back();
    }

    public function finish(Request $request, Game $game): RedirectResponse
    {
        $data = $request->validate([
            'winner_side' => ['required', 'in:a,b'],
            'winner_stays' => ['nullable', 'boolean'],
            'requeue_loser' => ['nullable', 'boolean'],
        ]);

        if ($game->isCompleted()) {
            return back()->withErrors(['winner_side' => 'That game is already in the books.']);
        }

        $winnerSide = $data['winner_side'];
        $winnerStays = (bool) ($data['winner_stays'] ?? false);

        // If nobody tracked racks, record the match itself as one rack.
        if ($game->side_a_score === 0 && $game->side_b_score === 0) {
            $game->forceFill(['side_' . $winnerSide . '_score' => 1])->save();
        }

        $this->queue->finishGame(
            game: $game,
            winnerSide: $winnerSide,
            requeueLoser: (bool) ($data['requeue_loser'] ?? true),
            requeueWinner: ! $winnerStays,
        );

        if ($winnerStays) {
            $game->load('players');

            return redirect()
                ->route('games.create', ['a' => $game->sidePlayers($winnerSide)->pluck('id')->join(',')])
                ->with('status', 'Winner stays on. Pick their next challenger.');
        }

        return redirect()
            ->route('queue.index')
            ->with('status', 'Game recorded.');
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
