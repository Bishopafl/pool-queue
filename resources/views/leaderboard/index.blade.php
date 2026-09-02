@extends('layouts.app')

@section('title', 'Records — Pool Queue')

@section('content')

    <section class="card">
        <div class="card__head">
            <h2 class="card__title">Records</h2>
            <span class="card__meta">Completed games only</span>
        </div>

        @if ($rows->isEmpty())
            <div class="empty"><p>No players yet.</p></div>
        @else
            <div class="data-table__scroll">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th scope="col">Player</th>
                            <th scope="col" class="num">W</th>
                            <th scope="col" class="num">L</th>
                            <th scope="col" class="num">Win %</th>
                            <th scope="col" class="num">Racks</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>
                                    <span class="name-cell {{ $row->is_active ? '' : 'is-benched' }}">
                                        @include('partials.ball', ['number' => $loop->iteration])
                                        {{ $row->nickname ?: $row->name }}
                                    </span>
                                </td>
                                <td class="num">{{ $row->wins }}</td>
                                <td class="num">{{ $row->losses }}</td>
                                <td class="num">{{ $row->win_rate === null ? '—' : $row->win_rate . '%' }}</td>
                                <td class="num">{{ $row->racks_won }}–{{ $row->racks_lost }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="hint">Racks are the running score inside a match; W and L count matches won.</p>
        @endif
    </section>

@endsection
