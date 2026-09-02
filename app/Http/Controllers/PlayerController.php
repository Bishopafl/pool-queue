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
            'side_a_color' => ['nullable', Rule::in(Player::COLOR_SWATCHES)],
            'side_b_color' => ['nullable', Rule::in(Player::COLOR_SWATCHES)],
        ]);

        $defaults = Player::defaultColorsFor(Player::count());

        Player::create([
            'name' => $data['name'],
            'nickname' => $data['nickname'] ?? null,
            'is_active' => true,
            'side_a_color' => $data['side_a_color'] ?? $defaults['side_a_color'],
            'side_b_color' => $data['side_b_color'] ?? $defaults['side_b_color'],
        ]);

        return back()->with('status', $data['name'] . ' added.');
    }

    public function update(Request $request, Player $player): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('players', 'name')->ignore($player)],
            'nickname' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
            'side_a_color' => ['nullable', Rule::in(Player::COLOR_SWATCHES)],
            'side_b_color' => ['nullable', Rule::in(Player::COLOR_SWATCHES)],
        ]);

        $player->update([
            'name' => $data['name'],
            'nickname' => $data['nickname'] ?? null,
            'is_active' => (bool) ($data['is_active'] ?? false),
            'side_a_color' => $data['side_a_color'] ?? $player->side_a_color,
            'side_b_color' => $data['side_b_color'] ?? $player->side_b_color,
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
