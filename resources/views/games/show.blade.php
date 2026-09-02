@extends('layouts.app')

@section('title', 'Scoreboard — Pool Queue')

@section('content')

    @include('partials.game-card', ['game' => $game, 'interactive' => true])

    @if ($game->isLive())

        <section class="card">
            <div class="card__head">
                <h2 class="card__title">Call the group</h2>
            </div>

            <form method="POST" action="{{ route('games.update', $game) }}">
                @csrf
                @method('PATCH')
                <input type="hidden" name="ball_group_side" value="a">

                <div class="field">
                    <span class="label">{{ $game->sideLabel('a') }} is shooting</span>
                    <div class="segment">
                        <input type="radio" id="group-stripes" name="ball_group" value="stripes"
                               @checked($game->side_a_ball_group === 'stripes')>
                        <label for="group-stripes">
                            @include('partials.ball', ['group' => 'stripes', 'color' => '#f0c14b', 'size' => 'sm'])
                            Stripes
                        </label>

                        <input type="radio" id="group-solids" name="ball_group" value="solids"
                               @checked($game->side_a_ball_group === 'solids')>
                        <label for="group-solids">
                            @include('partials.ball', ['group' => 'solids', 'color' => '#f0c14b', 'size' => 'sm'])
                            Solids
                        </label>

                        <input type="radio" id="group-open" name="ball_group" value="open"
                               @checked($game->side_a_ball_group === null)>
                        <label for="group-open">
                            @include('partials.ball', ['color' => 'var(--cue)', 'size' => 'sm'])
                            Still open
                        </label>
                    </div>
                    <p class="hint">{{ $game->sideLabel('b') }} takes the other group automatically.</p>
                </div>

                <button type="submit" class="btn btn--primary">Save group</button>
            </form>
        </section>

        <section class="card">
            <div class="card__head">
                <h2 class="card__title">Record the result</h2>
            </div>

            <form method="POST" action="{{ route('games.finish', $game) }}">
                @csrf

                <div class="field">
                    <span class="label">Winner</span>
                    <div class="segment">
                        <input type="radio" id="win-a" name="winner_side" value="a" required>
                        <label for="win-a">{{ $game->sideLabel('a') }}</label>
                        <input type="radio" id="win-b" name="winner_side" value="b" required>
                        <label for="win-b">{{ $game->sideLabel('b') }}</label>
                    </div>
                </div>

                <div class="field">
                    <span class="label">Then what</span>
                    <div class="segment">
                        <input type="hidden" name="winner_stays" value="0">
                        <input type="checkbox" id="winner-stays" name="winner_stays" value="1" checked>
                        <label for="winner-stays">Winner stays on</label>

                        <input type="hidden" name="requeue_loser" value="0">
                        <input type="checkbox" id="requeue-loser" name="requeue_loser" value="1" checked>
                        <label for="requeue-loser">Loser back in line</label>
                    </div>
                    <p class="hint">If nobody tracked racks, the win counts as one.</p>
                </div>

                <button type="submit" class="btn btn--gold btn--lg">Record result</button>
            </form>
        </section>

        <section class="card">
            <div class="card__head">
                <h2 class="card__title">Table details</h2>
            </div>

            <form method="POST" action="{{ route('games.update', $game) }}">
                @csrf
                @method('PATCH')

                <div class="field">
                    <span class="label">Who broke</span>
                    <div class="segment">
                        <input type="radio" id="brk-none" name="break_side" value="none" @checked($game->break_side === null)>
                        <label for="brk-none">Not recorded</label>
                        <input type="radio" id="brk-a" name="break_side" value="a" @checked($game->break_side === 'a')>
                        <label for="brk-a">{{ $game->sideLabel('a') }}</label>
                        <input type="radio" id="brk-b" name="break_side" value="b" @checked($game->break_side === 'b')>
                        <label for="brk-b">{{ $game->sideLabel('b') }}</label>
                    </div>
                </div>

                <div class="field">
                    <label class="label" for="table_label">Table</label>
                    <input class="input" type="text" id="table_label" name="table_label" maxlength="40"
                           value="{{ $game->table_label }}" placeholder="e.g. Garage table">
                </div>

                <div class="field">
                    <label class="label" for="notes">Notes</label>
                    <textarea class="textarea" id="notes" name="notes" maxlength="2000"
                              placeholder="Scratched on the eight, house rules, anything worth remembering">{{ $game->notes }}</textarea>
                </div>

                <button type="submit" class="btn">Save details</button>
            </form>
        </section>

    @endif

    <div class="btn-row">
        <a class="btn btn--ghost" href="{{ route('queue.index') }}">Back to the table</a>
        <form class="inline-form" method="POST" action="{{ route('games.destroy', $game) }}"
              onsubmit="return confirm('Delete this game and its result?');">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn btn--danger">Delete game</button>
        </form>
    </div>

@endsection
