@extends('layouts.app')

@section('title', 'Players — Pool Queue')

@section('content')

    <section class="card">
        <div class="card__head">
            <h2 class="card__title">Add a player</h2>
        </div>

        @php $nextColors = \App\Models\Player::defaultColorsFor($players->count()); @endphp
        <form method="POST" action="{{ route('players.store') }}">
            @csrf
            <div class="field-row">
                <div class="field">
                    <label class="label" for="name">Name</label>
                    <input class="input" type="text" id="name" name="name" maxlength="80" required
                           value="{{ old('name') }}" placeholder="Full name">
                </div>
                <div class="field">
                    <label class="label" for="nickname">Board name (optional)</label>
                    <input class="input" type="text" id="nickname" name="nickname" maxlength="40"
                           value="{{ old('nickname') }}" placeholder="What the board shows">
                </div>
                <button type="submit" class="btn btn--primary">Add player</button>
            </div>

            <div class="swatch-row">
                @include('partials.swatch-picker', [
                    'name' => 'side_a_color', 'label' => 'Side A colour', 'id' => 'new-a',
                    'selected' => old('side_a_color', $nextColors['side_a_color']),
                ])
                @include('partials.swatch-picker', [
                    'name' => 'side_b_color', 'label' => 'Side B colour', 'id' => 'new-b',
                    'selected' => old('side_b_color', $nextColors['side_b_color']),
                ])
            </div>
        </form>
    </section>

    <section class="card">
        <div class="card__head">
            <h2 class="card__title">Roster</h2>
            <span class="card__meta">{{ $players->count() }} on file</span>
        </div>

        @if ($players->isEmpty())
            <div class="empty"><p>No players yet. Add the first one above.</p></div>
        @else
            @foreach ($players as $player)
                <form method="POST" action="{{ route('players.update', $player) }}" class="lineup__row" style="margin-bottom:.5rem">
                    @csrf
                    @method('PATCH')

                    <input class="input" type="text" name="name" maxlength="80" required
                           value="{{ $player->name }}" aria-label="Name" style="flex:1 1 9rem">
                    <input class="input" type="text" name="nickname" maxlength="40"
                           value="{{ $player->nickname }}" aria-label="Board name" placeholder="Board name"
                           style="flex:1 1 7rem">

                    <span class="segment">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" id="active-{{ $player->id }}" name="is_active" value="1"
                               @checked($player->is_active)>
                        <label for="active-{{ $player->id }}">In rotation</label>
                    </span>

                    <span class="tag">{{ $player->participations_count }} {{ \Illuminate\Support\Str::plural('game', $player->participations_count) }}</span>

                    <span class="swatch-row">
                        @include('partials.swatch-picker', [
                            'name' => 'side_a_color', 'label' => 'Side A colour', 'id' => 'p' . $player->id . '-a',
                            'selected' => $player->side_a_color,
                        ])
                        @include('partials.swatch-picker', [
                            'name' => 'side_b_color', 'label' => 'Side B colour', 'id' => 'p' . $player->id . '-b',
                            'selected' => $player->side_b_color,
                        ])
                    </span>

                    <button type="submit" class="btn">Save</button>
                </form>

                <form method="POST" action="{{ route('players.destroy', $player) }}"
                      style="margin:-.35rem 0 1rem; text-align:right"
                      onsubmit="return confirm('Remove {{ addslashes($player->name) }}?');">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="btn btn--danger btn--icon" aria-label="Remove {{ $player->name }}">&times;</button>
                </form>
            @endforeach

            <p class="hint">Removing a player who has played keeps their games and benches them instead.</p>
        @endif
    </section>

@endsection
