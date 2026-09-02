@extends('layouts.app')

@section('title', 'History — Pool Queue')

@section('content')

    <section class="card">
        <div class="card__head">
            <h2 class="card__title">Game history</h2>
            <span class="card__meta">{{ $games->total() }} played</span>
        </div>

        <div class="segment" style="margin-bottom: 1rem">
            <a class="btn {{ $format === '' ? 'btn--primary' : 'btn--ghost' }}" href="{{ route('games.index') }}">All</a>
            @foreach (['1v1', '2v1', '2v2'] as $option)
                <a class="btn {{ $format === $option ? 'btn--primary' : 'btn--ghost' }}"
                   href="{{ route('games.index', ['format' => $option]) }}">{{ $option }}</a>
            @endforeach
        </div>

        @if ($games->isEmpty())
            <div class="empty">
                <p>No finished games yet.</p>
                <a class="btn btn--primary" href="{{ route('games.create') }}">Rack a game</a>
            </div>
        @else
            <div class="data-table__scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Winner</th>
                            <th scope="col">Beat</th>
                            <th scope="col" class="num">Score</th>
                            <th scope="col">Format</th>
                            <th scope="col">Groups</th>
                            <th scope="col">Finished</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($games as $game)
                            @php $loser = $game->loserSide(); @endphp
                            <tr>
                                <td>
                                    <a href="{{ route('games.show', $game) }}">
                                        {{ $game->winner_side ? $game->sideLabel($game->winner_side) : 'No result' }}
                                    </a>
                                </td>
                                <td>{{ $loser ? $game->sideLabel($loser) : '—' }}</td>
                                <td class="num">
                                    {{ $game->winner_side ? $game->score($game->winner_side) : 0 }}–{{ $loser ? $game->score($loser) : 0 }}
                                </td>
                                <td>{{ $game->format }}</td>
                                <td>
                                    @if ($game->side_a_ball_group)
                                        {{ ucfirst($game->side_a_ball_group) }} / {{ ucfirst((string) $game->side_b_ball_group) }}
                                    @else
                                        <span class="is-benched">Not called</span>
                                    @endif
                                </td>
                                <td>{{ optional($game->completed_at)->format('j M Y, g:ia') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="pagination btn-row" style="align-items:center">
                @if ($games->onFirstPage())
                    <span class="btn btn--ghost" aria-disabled="true">Newer</span>
                @else
                    <a class="btn" href="{{ $games->previousPageUrl() }}" rel="prev">Newer</a>
                @endif

                <span class="card__meta" style="margin:0">Page {{ $games->currentPage() }} of {{ $games->lastPage() }}</span>

                @if ($games->hasMorePages())
                    <a class="btn" href="{{ $games->nextPageUrl() }}" rel="next">Older</a>
                @else
                    <span class="btn btn--ghost" aria-disabled="true">Older</span>
                @endif
            </div>
        @endif
    </section>

@endsection
