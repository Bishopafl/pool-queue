<?php

namespace App\Http\Controllers;

use App\Models\Player;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PlayerController extends Controller
{
    public function index(): View
    {
        return view('players.index', [
            'players' => Player::query()
                ->withCount('participations')
                ->orderByDesc('is_active')
                ->orderBy('name')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', 'unique:players,name'],
            'nickname' => ['nullable', 'string', 'max:40'],
        ]);

        Player::create($data + ['is_active' => true]);

        return back()->with('status', $data['name'] . ' added.');
    }

    public function update(Request $request, Player $player): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('players', 'name')->ignore($player)],
            'nickname' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $player->update([
            'name' => $data['name'],
            'nickname' => $data['nickname'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
        ]);

        return back()->with('status', 'Saved.');
    }

    public function destroy(Player $player): RedirectResponse
    {
        // Keep history intact: benching removes them from pickers without
        // deleting the games they played.
        if ($player->participations()->exists()) {
            $player->update(['is_active' => false]);

            return back()->with('status', $player->name . ' has game history, so they were benched instead of deleted.');
        }

        $player->queueEntries()->detach();
        $player->delete();

        return back()->with('status', 'Player deleted.');
    }
}
