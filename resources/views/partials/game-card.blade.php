@php
    /** @var \App\Models\Game $game */
    $interactive = $interactive ?? false;
@endphp

<article class="table-card">

    <div class="table-card__top">
        <span class="tag {{ $game->isLive() ? 'tag--live' : '' }}">{{ $game->isLive() ? 'On the table' : $game->status }}</span>
        <span>{{ $game->gameTypeLabel() }}</span>
        <span>{{ $game->format }}</span>
        <span>race to {{ $game->target_score }}</span>
        @if ($game->table_label)
            <span>{{ $game->table_label }}</span>
        @endif
        @if ($game->break_side)
            <span>{{ $game->sideLabel($game->break_side) }} broke</span>
        @endif
        <span style="margin-left:auto">
            {{ optional($game->completed_at ?? $game->started_at)->format('D j M, g:ia') }}
        </span>
    </div>

    <div class="table-card__sides">
        @foreach (['a', 'b'] as $side)
            @php $group = $game->ballGroup($side); @endphp

            <div class="side {{ $game->winner_side === $side ? 'side--winner' : '' }} {{ $interactive && $game->isLive() && $game->isShooting($side) ? 'side--shooting' : '' }}">
                <div class="side__group">
                    @if ($group)
                        @include('partials.ball', ['group' => $group, 'color' => '#f0c14b'])
                        <span class="side__group-label">{{ $group }}</span>
                    @else
                        @include('partials.ball', ['color' => 'var(--cue)'])
                        <span class="side__group-label">Table open</span>
                    @endif
                </div>

                <p class="side__names">
                    @if ($game->champion_side === $side && $game->win_streak >= 1)
                        <span class="side__crown" title="{{ $game->win_streak }} straight win{{ $game->win_streak === 1 ? '' : 's' }}">👑</span>
                    @endif
                    {{ $game->sideLabel($side) }}
                </p>

                @if ($interactive && $game->isLive())
                    <div class="side__turn-row">
                        @if ($game->isShooting($side))
                            <span class="side__turn side__turn--on">▸ At the table</span>
                        @else
                            <form method="POST" action="{{ route('games.turn', $game) }}">
                                @csrf
                                <input type="hidden" name="side" value="{{ $side }}">
                                <button type="submit" class="side__turn side__turn--pass">
                                    {{ $game->shooting_side === null ? 'Take the shot' : 'Hand over →' }}
                                </button>
                            </form>
                        @endif
                    </div>
                @endif

                <strong class="side__score">{{ $game->score($side) }}<span class="side__target">/{{ $game->target_score }}</span></strong>

                @if ($interactive && $game->isLive())
                    <div class="side__scorebar">
                        <form method="POST" action="{{ route('games.score', $game) }}">
                            @csrf
                            <input type="hidden" name="side" value="{{ $side }}">
                            <input type="hidden" name="delta" value="-1">
                            <button type="submit" class="btn btn--icon btn--ghost"
                                    aria-label="Take a ball back from {{ $game->sideLabel($side) }}"
                                    @disabled($game->score($side) <= 0)>&minus;</button>
                        </form>
                        <form method="POST" action="{{ route('games.score', $game) }}">
                            @csrf
                            <input type="hidden" name="side" value="{{ $side }}">
                            <input type="hidden" name="delta" value="1">
                            <button type="submit" class="btn btn--icon"
                                    aria-label="Pocket a ball for {{ $game->sideLabel($side) }}"
                                    @disabled($game->sideHasReachedTarget($side))>+</button>
                        </form>
                    </div>
                @endif

                @if ($game->winner_side === $side)
                    <span class="side__flag">Won</span>
                @endif
            </div>

            @if ($side === 'a')
                <div class="rail-strip" aria-hidden="true"></div>
            @endif
        @endforeach
    </div>

    @if (! $interactive && $game->isLive())
        <div class="table-card__foot">
            <a class="btn btn--primary btn--block" href="{{ route('games.show', $game) }}">Open scoreboard</a>
        </div>
    @endif

    @if ($game->notes)
        <div class="table-card__foot">
            <p class="hint" style="margin:0">{{ $game->notes }}</p>
        </div>
    @endif

</article>
