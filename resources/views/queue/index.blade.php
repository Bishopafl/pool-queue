@extends('layouts.app')

@section('title', 'Table — Pool Queue')

@section('content')

    @forelse ($liveGames as $game)
        @include('partials.game-card', ['game' => $game, 'interactive' => false])
    @empty
        <div class="card">
            <div class="empty">
                <p>Nothing on the table.</p>
                <a class="btn btn--primary btn--lg" href="{{ route('games.create') }}">Rack a game</a>
            </div>
        </div>
    @endforelse

    @if ($liveGames->isNotEmpty())
        <div class="btn-row" style="margin-bottom: var(--gap)">
            <a class="btn" href="{{ route('games.create') }}">Rack another game</a>
        </div>
    @endif

    <section class="card">
        <div class="card__head">
            <h2 class="card__title">Up next</h2>
            <span class="card__meta">{{ $entries->count() }} waiting</span>
        </div>

        @if ($entries->isEmpty())
            <div class="empty">
                <p>Nobody is waiting. Add a player or a pair below.</p>
            </div>
        @else
            <ol class="rack">
                @foreach ($entries as $entry)
                    <li class="rack__row {{ $loop->first ? 'rack__row--next' : '' }}">
                        @include('partials.ball', ['number' => $loop->iteration])

                        <span class="rack__name">
                            {{ $entry->displayName() }}
                            @if ($loop->first)
                                <span class="rack__sub">First up</span>
                            @elseif ($entry->players->count() === 2)
                                <span class="rack__sub">Pair</span>
                            @endif
                        </span>

                        <span class="rack__actions">
                            <form class="inline-form" method="POST" action="{{ route('queue.move', $entry) }}">
                                @csrf
                                <input type="hidden" name="direction" value="up">
                                <button type="submit" class="btn btn--icon btn--ghost"
                                        aria-label="Move {{ $entry->displayName() }} up" @disabled($loop->first)>&uarr;</button>
                            </form>
                            <form class="inline-form" method="POST" action="{{ route('queue.move', $entry) }}">
                                @csrf
                                <input type="hidden" name="direction" value="down">
                                <button type="submit" class="btn btn--icon btn--ghost"
                                        aria-label="Move {{ $entry->displayName() }} down" @disabled($loop->last)>&darr;</button>
                            </form>
                            <form class="inline-form" method="POST" action="{{ route('queue.start', $entry) }}">
                                @csrf
                                <button type="submit" class="btn">Rack up</button>
                            </form>
                            <form class="inline-form" method="POST" action="{{ route('queue.destroy', $entry) }}">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn btn--icon btn--danger"
                                        aria-label="Remove {{ $entry->displayName() }} from the queue">&times;</button>
                            </form>
                        </span>
                    </li>
                @endforeach
            </ol>
        @endif
    </section>

    <section class="card">
        <div class="card__head">
            <h2 class="card__title">Add to the queue</h2>
        </div>

        @if ($players->isEmpty())
            <div class="empty">
                <p>No players yet.</p>
                <a class="btn btn--primary" href="{{ route('players.index') }}">Add players</a>
            </div>
        @else
            <form method="POST" action="{{ route('queue.store') }}">
                @csrf

                <div class="field">
                    <span class="label">Who's waiting</span>
                    <div class="segment">
                        @foreach ($players as $player)
                            <input type="checkbox" id="wait-{{ $player->id }}" name="player_ids[]" value="{{ $player->id }}">
                            <label for="wait-{{ $player->id }}">{{ $player->short_name }}</label>
                        @endforeach
                    </div>
                    <p class="hint">Tick one player, or two to hold the slot as a pair.</p>
                </div>

                <div class="field-row">
                    <div class="field">
                        <label class="label" for="label">Team name (optional)</label>
                        <input class="input" type="text" id="label" name="label" maxlength="60" placeholder="e.g. The Chalk Dusters">
                    </div>
                    <button type="submit" class="btn btn--primary">Add to queue</button>
                </div>
            </form>
        @endif
    </section>

    @if ($recentGames->isNotEmpty())
        <section class="card">
            <div class="card__head">
                <h2 class="card__title">Last five</h2>
                <a class="card__meta" href="{{ route('games.index') }}">All history</a>
            </div>

            <div class="data-table__scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Winner</th>
                            <th scope="col">Beat</th>
                            <th scope="col" class="num">Score</th>
                            <th scope="col">When</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentGames as $game)
                            @php $loser = $game->loserSide(); @endphp
                            <tr>
                                <td>{{ $game->winner_side ? $game->sideLabel($game->winner_side) : '—' }}</td>
                                <td>{{ $loser ? $game->sideLabel($loser) : '—' }}</td>
                                <td class="num">
                                    {{ $game->winner_side ? $game->score($game->winner_side) : 0 }}–{{ $loser ? $game->score($loser) : 0 }}
                                </td>
                                <td>{{ optional($game->completed_at)->diffForHumans() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

@endsection
