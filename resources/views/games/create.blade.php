@extends('layouts.app')

@section('title', 'Rack a game — Pool Queue')

@section('content')

    <section class="card">
        <div class="card__head">
            <h2 class="card__title">Rack a game</h2>
        </div>

        @if ($players->isEmpty())
            <div class="empty">
                <p>You need players before you can rack a game.</p>
                <a class="btn btn--primary" href="{{ route('players.index') }}">Add players</a>
            </div>
        @else
            <form method="POST" action="{{ route('games.store') }}" id="game-form">
                @csrf

                @if ($carryFrom)
                    <input type="hidden" name="carry_from" value="{{ $carryFrom }}">
                @endif

                <div class="field">
                    <span class="label">Game</span>
                    <div class="segment">
                        @foreach (\App\Models\Game::GAME_TYPES as $value => $meta)
                            <input type="radio" id="type-{{ $value }}" name="game_type" value="{{ $value }}"
                                   data-target="{{ $meta['target'] }}"
                                   @checked(old('game_type', $gameType) === $value)>
                            <label for="type-{{ $value }}">{{ $meta['label'] }}</label>
                        @endforeach
                    </div>
                </div>

                <div class="field">
                    <label class="label" for="balls_to_win">Balls to win</label>
                    <input class="input input--num" type="number" id="balls_to_win" name="balls_to_win"
                           min="1" max="15" inputmode="numeric"
                           value="{{ old('balls_to_win', $ballsToWin) }}">
                    <p class="hint">First side to pocket this many balls takes the rack. Eight-ball fills in 8, nine-ball 9 — change it for a shorter race.</p>
                </div>

                <div class="field">
                    <span class="label">Format</span>
                    <div class="segment">
                        @foreach (['1v1' => 'Singles', '2v1' => 'Two on one', '2v2' => 'Doubles'] as $value => $caption)
                            <input type="radio" id="format-{{ $value }}" name="format" value="{{ $value }}"
                                   @checked(old('format', $formatChoice) === $value)>
                            <label for="format-{{ $value }}">{{ $value }} · {{ $caption }}</label>
                        @endforeach
                    </div>
                    <p class="hint" id="lineup-count" aria-live="polite">Assign each player to a side below.</p>
                </div>

                <div class="field">
                    <span class="label">Lineup</span>
                    <div class="lineup">
                        @foreach ($players as $player)
                            @php
                                $default = in_array($player->id, $preassigned['a'], true) ? 'a'
                                    : (in_array($player->id, $preassigned['b'], true) ? 'b' : 'out');
                                $current = old('assign.' . $player->id, $default);
                            @endphp

                            <div class="lineup__row">
                                <span class="lineup__name">
                                    {{ $player->name }}
                                    @if ($player->nickname)
                                        <span class="rack__sub">“{{ $player->nickname }}”</span>
                                    @endif
                                </span>

                                <span class="lineup__choices" role="group" aria-label="Side for {{ $player->name }}">
                                    <input type="radio" id="p{{ $player->id }}-out" name="assign[{{ $player->id }}]"
                                           value="out" @checked($current === 'out')>
                                    <label class="is-out" for="p{{ $player->id }}-out">Out</label>

                                    <input type="radio" id="p{{ $player->id }}-a" name="assign[{{ $player->id }}]"
                                           value="a" @checked($current === 'a')>
                                    <label for="p{{ $player->id }}-a">Side A</label>

                                    <input type="radio" id="p{{ $player->id }}-b" name="assign[{{ $player->id }}]"
                                           value="b" @checked($current === 'b')>
                                    <label for="p{{ $player->id }}-b">Side B</label>
                                </span>
                            </div>
                        @endforeach
                    </div>
                    <p class="hint">For two on one, either side can hold the pair.</p>
                </div>

                <div class="field">
                    <span class="label">Who breaks</span>
                    <div class="segment">
                        <input type="radio" id="break-none" name="break_side" value="none" @checked(old('break_side', 'none') === 'none')>
                        <label for="break-none">Decide at the table</label>
                        <input type="radio" id="break-a" name="break_side" value="a" @checked(old('break_side') === 'a')>
                        <label for="break-a">Side A</label>
                        <input type="radio" id="break-b" name="break_side" value="b" @checked(old('break_side') === 'b')>
                        <label for="break-b">Side B</label>
                    </div>
                </div>

                <div class="field">
                    <label class="label" for="table_label">Table (optional)</label>
                    <input class="input" type="text" id="table_label" name="table_label" maxlength="40"
                           value="{{ old('table_label') }}" placeholder="e.g. Garage table">
                </div>

                <div class="btn-row">
                    <button type="submit" class="btn btn--primary btn--lg">Break</button>
                    <a class="btn btn--ghost" href="{{ route('queue.index') }}">Cancel</a>
                </div>
            </form>

            <script>
                (function () {
                    var form = document.getElementById('game-form');
                    var readout = document.getElementById('lineup-count');
                    if (!form) return;

                    // Swap the balls-to-win default when the ruleset changes,
                    // unless it has been hand-edited to something non-standard.
                    var ballsInput = document.getElementById('balls_to_win');
                    var standardTargets = ['8', '9'];
                    form.querySelectorAll('input[name="game_type"]').forEach(function (radio) {
                        radio.addEventListener('change', function () {
                            if (ballsInput && standardTargets.indexOf(ballsInput.value) !== -1) {
                                ballsInput.value = radio.getAttribute('data-target');
                            }
                        });
                    });

                    if (!readout) return;

                    function update() {
                        var a = form.querySelectorAll('input[value="a"][name^="assign"]:checked').length;
                        var b = form.querySelectorAll('input[value="b"][name^="assign"]:checked').length;
                        var format = (form.querySelector('input[name="format"]:checked') || {}).value;

                        var wanted = { '1v1': [1, 1], '2v1': [2, 1], '2v2': [2, 2] }[format] || [];
                        var picked = [a, b].slice().sort();
                        var ok = wanted.slice().sort().join() === picked.join();

                        readout.textContent = ok
                            ? 'Ready: ' + a + ' v ' + b + '.'
                            : 'Currently ' + a + ' v ' + b + ' — a ' + format + ' game needs ' + wanted.join(' and ') + '.';
                    }

                    form.addEventListener('change', update);
                    update();
                })();
            </script>
        @endif
    </section>

@endsection
