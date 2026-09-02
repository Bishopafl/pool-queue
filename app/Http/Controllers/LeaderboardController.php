<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class LeaderboardController extends Controller
{
    public function index(): View
    {
        $rows = DB::table('players as p')
            ->leftJoin('game_players as gp', 'gp.player_id', '=', 'p.id')
            ->leftJoin('games as g', function ($join) {
                $join->on('g.id', '=', 'gp.game_id')->where('g.status', '=', 'completed');
            })
            ->groupBy('p.id', 'p.name', 'p.nickname', 'p.is_active')
            ->select([
                'p.id',
                'p.name',
                'p.nickname',
                'p.is_active',
                DB::raw('COUNT(g.id) as games_played'),
                DB::raw('SUM(CASE WHEN g.winner_side = gp.side THEN 1 ELSE 0 END) as wins'),
                DB::raw('SUM(CASE WHEN g.winner_side IS NOT NULL AND g.winner_side <> gp.side THEN 1 ELSE 0 END) as losses'),
                DB::raw("SUM(CASE WHEN gp.side = 'a' THEN g.side_a_score ELSE g.side_b_score END) as racks_won"),
                DB::raw("SUM(CASE WHEN gp.side = 'a' THEN g.side_b_score ELSE g.side_a_score END) as racks_lost"),
            ])
            ->get()
            ->map(function ($row) {
                $row->games_played = (int) $row->games_played;
                $row->wins = (int) $row->wins;
                $row->losses = (int) $row->losses;
                $row->racks_won = (int) $row->racks_won;
                $row->racks_lost = (int) $row->racks_lost;
                $row->win_rate = $row->games_played > 0
                    ? round($row->wins / $row->games_played * 100)
                    : null;

                return $row;
            })
            ->sort(fn ($x, $y) => [$y->wins, $y->win_rate ?? -1, $x->losses]
                <=> [$x->wins, $x->win_rate ?? -1, $y->losses])
            ->values();

        return view('leaderboard.index', ['rows' => $rows]);
    }
}
