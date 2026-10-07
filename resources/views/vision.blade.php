<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#12332c">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Table Camera — Pool Queue</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Anton&family=JetBrains+Mono:wght@400;700&family=Space+Grotesk:wght@400;500;700&display=swap">
    <link rel="stylesheet" href="{{ asset('vision-app/vision.css') }}?v={{ filemtime(public_path('vision-app/vision.css')) }}">
</head>
<body>
<div class="vx" id="app"
     data-pair-url="{{ route('vision.pair') }}"
     data-pairing="{{ $pairingEnabled ? 'on' : 'off' }}"
     data-api="{{ url('/api/vision') }}"
     data-board="{{ route('queue.index') }}"
     data-assets="{{ asset('vision-app') }}/"
     data-version="{{ filemtime(public_path('vision-app/engine.js')) }}">

    <header class="vx-bar">
        <a class="vx-brand" href="{{ route('queue.index') }}">Pool Queue <span>Camera</span></a>
        <span class="vx-pill" id="status">Loading…</span>
        <span class="vx-spacer"></span>
        <button class="vx-btn vx-btn--ghost" id="btn-full" type="button">Full screen</button>
    </header>

    {{-- 0. loading --}}
    <section class="vx-screen" id="screen-loading">
        <div class="vx-card vx-card--center">
            <h1 class="vx-h">Getting the camera ready</h1>
            <p id="loading-msg">Loading the vision engine (about 10 MB, only the first time)…</p>
            <div class="vx-progress"><span></span></div>
        </div>
    </section>

    {{-- 1. pair this phone --}}
    <section class="vx-screen" id="screen-pair" hidden>
        <form class="vx-card" id="pair-form">
            <h1 class="vx-h">Pair this phone</h1>
            <p>Once per phone. This lets it post pots to the scoreboard.</p>
            <label class="vx-field">Camera name
                <input name="name" value="table-1" maxlength="40" pattern="[A-Za-z0-9 _.\-]+" required>
            </label>
            <label class="vx-field">Pairing password
                <input name="password" type="password" autocomplete="current-password" required>
            </label>
            @unless ($pairingEnabled)
                <p class="vx-warn">Pairing is off on the server. Set <code>VISION_PAIR_PASSWORD</code> in its <code>.env</code> first.</p>
            @endunless
            <p class="vx-msg" id="pair-msg" role="status"></p>
            <button class="vx-btn" type="submit">Pair</button>
        </form>
    </section>

    {{-- 2. which table --}}
    <section class="vx-screen" id="screen-table" hidden>
        <div class="vx-card vx-card--wide">
            <h1 class="vx-h">Which table is this?</h1>
            <p>The size tells the camera how big the balls look, and where every pocket sits from the ones it can see.</p>
            <div class="vx-tables" role="radiogroup" aria-label="Table size">
                <button class="vx-table" type="button" data-size="7ft" role="radio">
                    <span class="vx-table__art" style="--ratio: 2"></span>
                    <strong>7 ft</strong><small>Bar box · play area 78 × 39 in</small>
                </button>
                <button class="vx-table" type="button" data-size="8ft" role="radio">
                    <span class="vx-table__art" style="--ratio: 2"></span>
                    <strong>8 ft</strong><small>Home · play area 88 × 44 in</small>
                </button>
                <button class="vx-table" type="button" data-size="9ft" role="radio">
                    <span class="vx-table__art" style="--ratio: 2"></span>
                    <strong>9 ft</strong><small>Tournament · play area 100 × 50 in</small>
                </button>
            </div>
            <label class="vx-field vx-field--inline">Table label <small>(optional: match the game's table label when there's more than one table)</small>
                <input id="table-label" maxlength="40" placeholder="e.g. Front">
            </label>
            <div class="vx-actions">
                <button class="vx-btn" id="btn-table-next" type="button" disabled>Next: calibrate</button>
            </div>
        </div>
    </section>

    {{-- 3. calibrate --}}
    <section class="vx-screen vx-screen--work" id="screen-calibrate" hidden>
        <div class="vx-tools">
            <span class="vx-pill vx-pill--size" id="cal-size"></span>
            <button class="vx-btn" id="btn-auto" type="button">Find the table</button>
            <button class="vx-btn vx-btn--ghost" id="btn-corners" type="button">Tap 4 corners</button>
            <button class="vx-btn vx-btn--ghost" id="btn-change-table" type="button">Change table</button>
            <span class="vx-spacer"></span>
            <button class="vx-btn vx-btn--go" id="btn-use" type="button" disabled>Looks right: start</button>
        </div>
        <p class="vx-msg" id="cal-msg" role="status">Point the camera at the whole table, then press <b>Find the table</b>.</p>
        <div class="vx-panes">
            <figure class="vx-pane">
                <figcaption>Camera <small>the yellow outline should hug the felt</small></figcaption>
                <div class="vx-stage"><video id="video" playsinline muted></video><canvas id="cam-overlay"></canvas></div>
            </figure>
            <figure class="vx-pane">
                <figcaption>Table view <small>green rings: pockets seen · amber: placed from the table's shape · tap to move</small></figcaption>
                <div class="vx-stage"><canvas id="cal-view" width="1100" height="600"></canvas></div>
            </figure>
        </div>
    </section>

    {{-- 4. play --}}
    <section class="vx-screen vx-screen--work" id="screen-play" hidden>
        <div class="vx-tools">
            <span class="vx-pill" id="play-fps">– fps</span>
            <span class="vx-pill" id="play-sync">Scoreboard: –</span>
            <span class="vx-spacer"></span>
            <button class="vx-btn vx-btn--ghost" id="btn-pause" type="button">Pause</button>
            <button class="vx-btn vx-btn--ghost" id="btn-recal" type="button">Recalibrate</button>
        </div>
        <div class="vx-play">
            <div class="vx-stage vx-stage--play"><canvas id="play-view" width="1100" height="600"></canvas>
                <div class="vx-banner" id="banner" hidden></div></div>
            <aside class="vx-side">
                <div class="vx-counts">
                    <div><small>Solids</small><b id="n-solid">–</b></div>
                    <div><small>Stripes</small><b id="n-stripe">–</b></div>
                    <div><small>8</small><b id="n-eight">–</b></div>
                    <div><small>Cue</small><b id="n-cue">–</b></div>
                </div>
                <div class="vx-game" id="game">No live game yet.</div>
                <ol class="vx-log" id="pot-log"><li class="vx-empty">Nothing potted yet.</li></ol>
            </aside>
        </div>
    </section>
</div>
<script type="module" src="{{ asset('vision-app/app.js') }}?v={{ filemtime(public_path('vision-app/app.js')) }}"></script>
</body>
</html>
