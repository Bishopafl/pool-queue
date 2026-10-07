<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Services\VisionEventService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The pool-vision camera's API. Every route needs a Sanctum token with the
 * "vision" ability (php artisan pool-vision:token).
 */
class VisionController extends Controller
{
    public function __construct(private readonly VisionEventService $vision) {}

    /**
     * GET /api/vision/game?table=Table+1 — the live game the camera is scoring.
     */
    public function game(Request $request): JsonResponse
    {
        $data = $request->validate(['table' => ['nullable', 'string', 'max:40']]);
        $game = $this->vision->currentGame($data['table'] ?? null);

        if (! $game) {
            return response()->json(['game' => null, 'message' => 'No live game.'], 404);
        }

        return response()->json(['game' => $this->gamePayload($game->load('players'))]);
    }

    /**
     * POST /api/vision/events — one pot seen by the camera. Safe to retry with the same event_id.
     */
    public function storeEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_id' => ['required', 'uuid'],
            'type' => ['required', 'in:pot'],
            'kind' => ['required', 'in:cue,eight,solid,stripe,unknown'],
            'pocket' => ['nullable', 'string', 'max:40'],
            'kind_conf' => ['nullable', 'numeric', 'between:0,1'],
            't' => ['nullable', 'numeric', 'min:0'],
            'table' => ['nullable', 'string', 'max:40'],
        ]);

        $event = $this->vision->record($data);
        $game = $event->game?->fresh('players');

        return response()->json([
            'event_id' => $event->event_id,
            'applied' => $event->applied,
            'outcome' => $event->outcome,
            'game' => $game ? $this->gamePayload($game) : null,
        ], $event->wasRecentlyCreated ? 201 : 200);
    }

    private function gamePayload(Game $game): array
    {
        $side = fn (string $s) => [
            'players' => $game->sidePlayers($s)->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values(),
            'ball_group' => $game->ballGroup($s),
            'score' => $game->score($s),
        ];

        return [
            'id' => $game->id,
            'status' => $game->status,
            'game_type' => $game->game_type,
            'target_score' => (int) $game->target_score,
            'table_label' => $game->table_label,
            'shooting_side' => $game->shooting_side,
            'winner_side' => $game->winner_side,
            'sides' => ['a' => $side('a'), 'b' => $side('b')],
        ];
    }
}
