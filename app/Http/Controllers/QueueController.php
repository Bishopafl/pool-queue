<?php

namespace App\Http\Controllers;

use App\Models\Game;
use App\Models\Player;
use App\Models\QueueEntry;
use App\Services\QueueService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class QueueController extends Controller
{
    public function __construct(private readonly QueueService $queue)
    {
    }

    public function index(): View
    {
        return view('queue.index', [
            'liveGames' => Game::live()->with('players')->orderBy('started_at')->get(),
            'entries' => QueueEntry::ordered()->with('players')->get(),
            'players' => Player::active()->orderBy('name')->get(),
            'recentGames' => Game::completed()->with('players')->latest('completed_at')->limit(5)->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'player_ids' => ['required', 'array', 'min:1', 'max:2'],
            'player_ids.*' => ['integer', 'exists:players,id'],
            'label' => ['nullable', 'string', 'max:60'],
        ], [
            'player_ids.required' => 'Pick one or two players to add to the queue.',
            'player_ids.max' => 'A queue slot holds at most two players.',
        ]);

        $this->queue->enqueue($data['player_ids'], $data['label'] ?? null);

        return back()->with('status', 'Added to the queue.');
    }

    public function move(Request $request, QueueEntry $entry): RedirectResponse
    {
        $data = $request->validate([
            'direction' => ['required', 'in:up,down'],
        ]);

        $this->queue->move($entry, $data['direction'] === 'up' ? -1 : 1);

        return back();
    }

    public function destroy(QueueEntry $entry): RedirectResponse
    {
        $this->queue->remove($entry);

        return back()->with('status', 'Removed from the queue.');
    }

    /**
     * Send a queue slot straight into the new-game form, pre-assigned to a side.
     */
    public function start(Request $request, QueueEntry $entry): RedirectResponse
    {
        $data = $request->validate([
            'side' => ['nullable', 'in:a,b'],
        ]);

        $entry->load('players');

        return redirect()->route('games.create', [
            $data['side'] ?? 'a' => $entry->players->pluck('id')->join(','),
        ]);
    }
}
