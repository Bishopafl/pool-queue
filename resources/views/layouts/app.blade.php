<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#12332c">
    <title>@yield('title', 'Pool Queue')</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anton&family=JetBrains+Mono:wght@400;700&family=Space+Grotesk:wght@400;500;700&display=swap">
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<div class="shell">

    <header class="masthead">
        <h1 class="masthead__title"><a href="{{ route('queue.index') }}">Pool Queue</a></h1>
        <nav class="masthead__nav" aria-label="Main">
            <a href="{{ route('queue.index') }}" @if (request()->routeIs('queue.*')) aria-current="page" @endif>Table</a>
            <a href="{{ route('games.index') }}" @if (request()->routeIs('games.index')) aria-current="page" @endif>History</a>
            <a href="{{ route('leaderboard.index') }}" @if (request()->routeIs('leaderboard.*')) aria-current="page" @endif>Records</a>
            <a href="{{ route('players.index') }}" @if (request()->routeIs('players.*')) aria-current="page" @endif>Players</a>
        </nav>
    </header>

    @if (session('status'))
        <p class="flash" role="status">{{ session('status') }}</p>
    @endif

    @if ($errors->any())
        <ul class="errors" role="alert">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    @yield('content')

</div>
</body>
</html>
