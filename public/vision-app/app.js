// Pool Queue table camera (/vision). The phone overlooking the table runs the whole
// pool-vision pipeline in its browser: worker.js does the vision (engine.js is a port of
// the Python package), this file runs the screens, the camera and the scoreboard sync.
//
// engine.js, worker.js and opencv.js are copied in from the pool-vision repo
// (scripts/sync_web.sh there); edit them in pool-vision, not here.

const app = document.getElementById("app");
const $ = (id) => document.getElementById(id);
const PAIR_URL = app.dataset.pairUrl;
const API = app.dataset.api;
const ASSETS = app.dataset.assets;
const VERSION = app.dataset.version;
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

// ---------------------------------------------------------------- per-phone storage
// The token, table and calibration belong to this phone. Storage can be blocked
// (private mode): then everything still works for this visit.
const store = {
  get(key, fallback = null) {
    try { const v = localStorage.getItem(`pv.${key}`); return v === null ? fallback : JSON.parse(v); } catch { return fallback; }
  },
  set(key, value) {
    try { localStorage.setItem(`pv.${key}`, JSON.stringify(value)); } catch { /* blocked: this visit only */ }
  },
};
const state = {
  token: store.get("token"),
  device: store.get("device"),
  tableSize: store.get("tableSize"),
  tableLabel: store.get("tableLabel", ""),
  cal: store.get("cal"),
};

// ---------------------------------------------------------------- screens + status
function show(name) {
  for (const s of document.querySelectorAll(".vx-screen")) s.hidden = s.id !== `screen-${name}`;
}
function status(text, tone = "") {
  const el = $("status");
  el.textContent = text;
  el.className = `vx-pill${tone ? ` vx-pill--${tone}` : ""}`;
}
function msg(id, text, tone = "") {
  const el = $(id);
  el.innerHTML = text;
  el.className = `vx-msg${tone ? ` is-${tone}` : ""}`;
}

// ---------------------------------------------------------------- the vision worker
let worker = null;
let nextId = 1;
const waiting = new Map(); // request id -> {resolve, reject}: replies echo the id

function startWorker() {
  return new Promise((resolve, reject) => {
    worker = new Worker(`${ASSETS}worker.js?v=${VERSION}`);
    waiting.set(0, { resolve: (d) => resolve(d.version), reject });
    worker.onmessage = ({ data }) => {
      const w = waiting.get(data.id);
      if (!w) return;
      waiting.delete(data.id);
      if (data.type === "error") w.reject(new Error(data.message));
      else w.resolve(data);
    };
    worker.onerror = (e) => reject(new Error(e.message || "The vision engine failed to load."));
    worker.postMessage({ id: 0, type: "init" });
  });
}
function ask(message, transfer = []) {
  const id = nextId++;
  return new Promise((resolve, reject) => {
    waiting.set(id, { resolve, reject });
    worker.postMessage({ ...message, id }, transfer);
  });
}

// ---------------------------------------------------------------- camera
const video = $("video");
let stream = null;

async function startCamera() {
  if (stream) return;
  if (!navigator.mediaDevices?.getUserMedia) throw new Error("This browser can't use the camera here (it needs https).");
  stream = await navigator.mediaDevices.getUserMedia({
    audio: false,
    video: { facingMode: { ideal: "environment" }, width: { ideal: 1280 }, height: { ideal: 720 }, frameRate: { ideal: 30 } },
  });
  video.srcObject = stream;
  await video.play();
  await new Promise((r) => (video.videoWidth ? r() : video.addEventListener("loadedmetadata", r, { once: true })));
  keepAwake();
}
const grab = () => createImageBitmap(video);

let wakeLock = null;
async function keepAwake() { // the phone must not dim and lock mid-game
  try { wakeLock = await navigator.wakeLock?.request("screen"); } catch { /* not supported: fine */ }
}
document.addEventListener("visibilitychange", () => { if (document.visibilityState === "visible" && stream) keepAwake(); });

// ---------------------------------------------------------------- drawing
const KIND_COLOR = { cue: "#f4efe2", eight: "#14100d", solid: "#f0c14b", stripe: "#ff8ad8", unknown: "#a9b8ae" };
const r = () => 1.125 / ({ "7ft": 78, "8ft": 88, "9ft": 100 }[state.tableSize || "7ft"] + 4) * 1000; // engine.ballRadiusPx

function ring(ctx, x, y, radius, color, width = 3, dash = []) {
  ctx.save();
  ctx.setLineDash(dash);
  ctx.lineWidth = width;
  ctx.strokeStyle = color;
  ctx.beginPath(); ctx.arc(x, y, radius, 0, 2 * Math.PI); ctx.stroke();
  ctx.restore();
}
function label(ctx, text, x, y, color = "#fff", size = 16) {
  ctx.save();
  ctx.font = `700 ${size}px "JetBrains Mono", monospace`;
  ctx.lineWidth = 4; ctx.strokeStyle = "#000"; ctx.strokeText(text, x, y);
  ctx.fillStyle = color; ctx.fillText(text, x, y);
  ctx.restore();
}

function drawPockets(ctx, cal, zone) {
  cal.pockets.forEach(([x, y], i) => {
    const seen = cal.pockets_found?.[i] ?? true;
    ring(ctx, x, y, zone, seen ? "#7be0a6" : "#f0c14b", 3, seen ? [] : [8, 6]);
    ctx.fillStyle = seen ? "#7be0a6" : "#f0c14b";
    ctx.beginPath(); ctx.arc(x, y, 3, 0, 2 * Math.PI); ctx.fill();
  });
}

function drawBalls(ctx, balls) {
  for (const b of balls) {
    if (b.confirmed === false) { ring(ctx, b.x, b.y, r(), "#888", 1); continue; }
    if (b.kind === "eight") {
      ring(ctx, b.x, b.y, r() + 7, "#fff", 3); ring(ctx, b.x, b.y, r() + 3, "#c1483f", 2);
      label(ctx, "8-BALL", b.x + r() + 6, b.y - r(), "#ff6b5e");
      continue;
    }
    ring(ctx, b.x, b.y, r() + 3, KIND_COLOR[b.kind] || "#aaa", b.missing ? 1 : 2.5);
    if (b.kind !== "unknown") label(ctx, b.kind, b.x + r() + 2, b.y - r(), KIND_COLOR[b.kind], 14);
  }
}

// ---------------------------------------------------------------- 1. pairing
$("pair-form").addEventListener("submit", async (e) => {
  e.preventDefault();
  const form = new FormData(e.target);
  msg("pair-msg", "Pairing…");
  try {
    const res = await fetch(PAIR_URL, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json", "X-CSRF-TOKEN": CSRF },
      body: JSON.stringify({ name: form.get("name"), password: form.get("password") }),
    });
    const body = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(body.message || `The server said ${res.status}.`);
    state.token = body.token; state.device = body.name;
    store.set("token", state.token); store.set("device", state.device);
    msg("pair-msg", "Paired.", "ok");
    next();
  } catch (err) {
    msg("pair-msg", err.message, "bad");
  }
});

// ---------------------------------------------------------------- 2. table size
const tableButtons = [...document.querySelectorAll(".vx-table")];
function pickTable(size) {
  state.tableSize = size;
  for (const b of tableButtons) b.setAttribute("aria-checked", String(b.dataset.size === size));
  $("btn-table-next").disabled = !size;
}
tableButtons.forEach((b) => b.addEventListener("click", () => pickTable(b.dataset.size)));
$("table-label").value = state.tableLabel;
$("btn-table-next").addEventListener("click", () => {
  store.set("tableSize", state.tableSize);
  state.tableLabel = $("table-label").value.trim();
  store.set("tableLabel", state.tableLabel);
  openCalibrate();
});

// ---------------------------------------------------------------- 3. calibrate
let draft = null; // the calibration being set up
let tapping = false, taps = [];
const camOverlay = $("cam-overlay"), calView = $("cal-view");
let calBitmap = null;

async function openCalibrate() {
  show("calibrate");
  $("cal-size").textContent = `${state.tableSize} table`;
  try {
    await startCamera();
    status("Camera on", "ok");
    msg("cal-msg", "Point the camera at the whole table, then press <b>Find the table</b>.");
  } catch (err) {
    msg("cal-msg", `Camera: ${err.message}`, "bad");
    status("No camera", "bad");
    return;
  }
  camOverlay.width = video.videoWidth; camOverlay.height = video.videoHeight;
  draft = state.cal && state.cal.table_size === state.tableSize ? state.cal : null;
  if (draft) await calibrate({ corners: draft.corners, keepPockets: draft });
  else await calibrate({});
}

async function calibrate({ corners = null, keepPockets = null }) {
  setCalBusy(true);
  msg("cal-msg", corners ? "Fitting the table to those corners…" : "Looking for the table (hold still a second)…");
  try {
    // auto: 5 frames over about a second, so someone walking past doesn't skew the outline
    const frames = [];
    for (let i = 0; i < (corners ? 1 : 5); i++) {
      if (i) await sleep(220);
      frames.push(await grab());
    }
    const res = await ask({ type: "calibrate", frames, tableSize: state.tableSize, corners }, frames);
    if (!res.cal) {
      draft = null;
      msg("cal-msg", "Couldn't find the felt. Is the whole table in view and lit? Or press <b>Tap 4 corners</b>.", "bad");
      drawCalibration(null);
      return;
    }
    draft = res.cal;
    if (keepPockets && keepPockets.pockets) { // reopening a saved calibration: keep hand-placed pockets
      draft.pockets = keepPockets.pockets; draft.pockets_found = keepPockets.pockets_found;
    }
    if (calBitmap) calBitmap.close();
    calBitmap = res.view;
    drawCalibration(res.balls);
    const seen = draft.pockets_found.filter(Boolean).length;
    const placed = 6 - seen;
    msg("cal-msg", `Found the table and ${seen} of 6 pockets${placed ? `; placed the other ${placed} from the ${state.tableSize} table's shape` : ""}. `
      + `${res.balls.length} balls in view. Check the rings, tap a pocket to move it, then <b>Looks right: start</b>.`, "ok");
  } catch (err) {
    msg("cal-msg", `Calibration failed: ${err.message}`, "bad");
  } finally {
    setCalBusy(false);
  }
}

let lastBalls = [];
function drawCalibration(balls) {
  if (balls) lastBalls = balls;
  const cctx = camOverlay.getContext("2d");
  cctx.clearRect(0, 0, camOverlay.width, camOverlay.height);
  const k = draft ? camOverlay.width / draft.frame_size[0] : 1;
  if (draft && !tapping) {
    cctx.lineWidth = 4; cctx.strokeStyle = "#f0c14b";
    cctx.beginPath();
    draft.corners.forEach(([x, y], i) => (i ? cctx.lineTo(x * k, y * k) : cctx.moveTo(x * k, y * k)));
    cctx.closePath(); cctx.stroke();
  }
  for (const [x, y] of taps) { cctx.fillStyle = "#c1483f"; cctx.beginPath(); cctx.arc(x, y, 10, 0, 2 * Math.PI); cctx.fill(); }
  const vctx = calView.getContext("2d");
  vctx.fillStyle = "#000"; vctx.fillRect(0, 0, calView.width, calView.height);
  if (draft && calBitmap) {
    vctx.drawImage(calBitmap, 0, 0);
    drawPockets(vctx, draft, r() * 1.8);
    drawBalls(vctx, lastBalls);
  }
  $("btn-use").disabled = !draft;
}

function setCalBusy(busy) {
  for (const id of ["btn-auto", "btn-corners", "btn-use", "btn-change-table"]) $(id).disabled = busy || (id === "btn-use" && !draft);
}

function canvasPoint(canvas, e) {
  const box = canvas.getBoundingClientRect();
  return [(e.clientX - box.left) * (canvas.width / box.width), (e.clientY - box.top) * (canvas.height / box.height)];
}

$("btn-auto").addEventListener("click", () => { tapping = false; taps = []; $("btn-corners").classList.remove("is-on"); calibrate({}); });
$("btn-corners").addEventListener("click", () => {
  tapping = !tapping; taps = [];
  $("btn-corners").classList.toggle("is-on", tapping);
  msg("cal-msg", tapping ? "Tap the 4 corners of the felt on the camera picture, in any order." : "");
  drawCalibration();
});
camOverlay.addEventListener("pointerdown", (e) => {
  if (!tapping) return;
  taps.push(canvasPoint(camOverlay, e));
  drawCalibration();
  if (taps.length === 4) {
    const k = video.videoWidth / camOverlay.width;
    const corners = taps.map(([x, y]) => [x * k, y * k]);
    tapping = false; taps = [];
    $("btn-corners").classList.remove("is-on");
    calibrate({ corners });
  }
});
calView.addEventListener("pointerdown", (e) => { // move the nearest pocket here
  if (!draft) return;
  const [x, y] = canvasPoint(calView, e);
  let best = 0;
  draft.pockets.forEach(([px, py], i) => { if (Math.hypot(px - x, py - y) < Math.hypot(draft.pockets[best][0] - x, draft.pockets[best][1] - y)) best = i; });
  draft.pockets[best] = [x, y];
  draft.pockets_found[best] = true;
  drawCalibration();
  msg("cal-msg", "Pocket moved. Start when all six rings sit on the pockets.", "ok");
});
$("btn-change-table").addEventListener("click", () => { pickTable(state.tableSize); show("table"); });
$("btn-use").addEventListener("click", () => {
  state.cal = draft;
  store.set("cal", draft);
  startPlay();
});

// ---------------------------------------------------------------- 4. play
let playing = false, paused = false, frameCount = 0, fpsT0 = 0;
const playView = $("play-view");

async function startPlay() {
  show("play");
  await startCamera();
  await ask({ type: "start", cal: state.cal });
  playing = true; paused = false;
  $("btn-pause").textContent = "Pause";
  fpsT0 = performance.now(); frameCount = 0;
  status(`Watching · ${state.tableSize}${state.tableLabel ? ` · ${state.tableLabel}` : ""}`, "ok");
  loop();
  refreshGame();
}

async function loop() {
  while (playing) {
    if (paused || document.visibilityState !== "visible") { await sleep(250); continue; }
    const frame = await grab();
    const t = performance.now() / 1000;
    let res;
    try {
      res = await ask({ type: "frame", frame, t }, [frame]);
    } catch (err) {
      status(`Vision error: ${err.message}`, "bad");
      await sleep(1000);
      continue;
    }
    if (!playing || res.type !== "tracked") { res.view?.close(); break; }
    const ctx = playView.getContext("2d");
    ctx.drawImage(res.view, 0, 0);
    res.view.close();
    drawPockets(ctx, state.cal, r() * 4);
    drawBalls(ctx, res.tracks);
    setCounts(res.counts);
    for (const pot of res.pots) onPot(pot);
    frameCount++;
    const now = performance.now();
    if (now - fpsT0 > 1000) {
      const fps = (frameCount * 1000) / (now - fpsT0);
      $("play-fps").textContent = `${fps.toFixed(1)} fps · ${Math.round(res.ms)} ms`;
      $("play-fps").className = `vx-pill vx-pill--${fps >= 15 ? "ok" : fps >= 8 ? "warn" : "bad"}`;
      fpsT0 = now; frameCount = 0;
    }
  }
}
const sleep = (ms) => new Promise((res) => setTimeout(res, ms));

function setCounts(c) {
  $("n-solid").textContent = c.solid ?? 0;
  $("n-stripe").textContent = c.stripe ?? 0;
  $("n-eight").textContent = c.eight ? "on" : "—";
  $("n-cue").textContent = c.cue ? "on" : "—";
}

const CHIP = { cue: "SCRATCH", eight: "8 BALL", solid: "SOLID", stripe: "STRIPE" };
function onPot(pot) {
  const log = $("pot-log");
  log.querySelector(".vx-empty")?.remove();
  const li = document.createElement("li");
  li.innerHTML = `<span class="vx-chip vx-chip--${pot.kind}"></span><span class="where"></span><small>sending to the scoreboard…</small>`;
  li.querySelector(".vx-chip").textContent = CHIP[pot.kind] || pot.kind.toUpperCase();
  li.querySelector(".where").textContent = `${pot.pocket} pocket`;
  log.prepend(li);
  banner(CHIP[pot.kind] ? `${CHIP[pot.kind]}${pot.kind === "solid" || pot.kind === "stripe" ? " IN" : "!"}` : "POT");
  outbox.push({ li, body: { event_id: crypto.randomUUID(), type: "pot", kind: pot.kind, pocket: pot.pocket,
    kind_conf: pot.kind_conf, t: Math.max(0, pot.t), ...(state.tableLabel ? { table: state.tableLabel } : {}) } });
}

function banner(text) {
  const b = $("banner");
  b.textContent = text; b.hidden = false;
  b.style.animation = "none"; void b.offsetWidth; b.style.animation = "";
  clearTimeout(banner.timer);
  banner.timer = setTimeout(() => { b.hidden = true; }, 2200);
}

$("btn-pause").addEventListener("click", () => {
  paused = !paused;
  $("btn-pause").textContent = paused ? "Resume" : "Pause";
  status(paused ? "Paused" : "Watching", paused ? "warn" : "ok");
});
$("btn-recal").addEventListener("click", async () => {
  playing = false;
  await ask({ type: "stop" });
  openCalibrate();
});

// ---------------------------------------------------------------- scoreboard sync
// Pots wait in an outbox (kept on the phone) until the server has them. Each has its
// own event_id, so a retry after a lost reply is never counted twice.
const outbox = {
  items: store.get("outbox", []).map((body) => ({ body, li: null })),
  sending: false,
  push(item) { this.items.push(item); this.save(); this.flush(); },
  save() { store.set("outbox", this.items.map((i) => i.body)); },
  async flush() {
    if (this.sending) return;
    this.sending = true;
    let backoff = 1000;
    while (this.items.length) {
      const item = this.items[0];
      try {
        const res = await fetch(`${API}/events`, {
          method: "POST",
          headers: { "Content-Type": "application/json", Accept: "application/json", Authorization: `Bearer ${state.token}` },
          body: JSON.stringify(item.body),
        });
        const body = await res.json().catch(() => ({}));
        if (res.status === 401 || res.status === 403) { syncState("Not paired: pair this phone again", "bad"); unpair(); break; }
        if (res.status >= 400 && res.status < 500 && res.status !== 408 && res.status !== 429) {
          if (item.li) item.li.querySelector("small").textContent = `refused: ${body.message || res.status}`;
        } else if (!res.ok) {
          throw new Error(`HTTP ${res.status}`);
        } else {
          if (item.li) item.li.querySelector("small").textContent = body.outcome || "sent";
          if (body.game) showGame(body.game);
        }
        this.items.shift(); this.save(); backoff = 1000;
        syncState(this.items.length ? `${this.items.length} to send` : "up to date", this.items.length ? "warn" : "ok");
      } catch {
        syncState(`offline · ${this.items.length} waiting`, "warn");
        await sleep(backoff);
        backoff = Math.min(30000, backoff * 2);
      }
    }
    this.sending = false;
  },
};
function syncState(text, tone) {
  $("play-sync").textContent = `Scoreboard: ${text}`;
  $("play-sync").className = `vx-pill vx-pill--${tone}`;
}

async function refreshGame() {
  if (!playing) return;
  try {
    const q = state.tableLabel ? `?table=${encodeURIComponent(state.tableLabel)}` : "";
    const res = await fetch(`${API}/game${q}`, { headers: { Accept: "application/json", Authorization: `Bearer ${state.token}` } });
    if (res.status === 401 || res.status === 403) { syncState("not paired", "bad"); unpair(); return; }
    const body = await res.json().catch(() => ({}));
    showGame(body.game);
    if (!outbox.items.length) syncState("up to date", "ok");
  } catch {
    syncState("offline", "warn");
  }
  setTimeout(refreshGame, 10000);
}

function showGame(game) {
  const el = $("game");
  if (!game) { el.textContent = "No live game on the scoreboard. Pots are kept but won't score until one starts."; return; }
  const side = (s) => {
    const d = game.sides[s];
    const names = d.players.map((p) => p.name).join(" & ") || `Side ${s.toUpperCase()}`;
    return `${game.shooting_side === s ? "▶ " : ""}<b></b> ${d.score}${d.ball_group ? ` · ${d.ball_group}` : ""}`.replace("<b></b>", `<b>${escapeHtml(names)}</b>`);
  };
  el.innerHTML = `${side("a")}<br>${side("b")}`;
}
const escapeHtml = (s) => s.replace(/[&<>"']/g, (c) => `&#${c.charCodeAt(0)};`);

function unpair() {
  state.token = null;
  store.set("token", null);
}

$("btn-full").addEventListener("click", () => {
  if (document.fullscreenElement) document.exitFullscreen();
  else document.documentElement.requestFullscreen?.().catch(() => {});
});

// ---------------------------------------------------------------- start
function next() {
  if (!state.token) { show("pair"); status("Not paired", "warn"); return; }
  if (!state.tableSize) { pickTable(null); show("table"); status("Pick the table", "warn"); return; }
  pickTable(state.tableSize);
  if (state.cal && state.cal.table_size === state.tableSize) { startPlay().catch((e) => { show("calibrate"); msg("cal-msg", e.message, "bad"); }); return; }
  openCalibrate();
}

(async () => {
  try {
    if (!window.isSecureContext) throw new Error("Open this page over https: browsers only give the camera to secure pages.");
    const version = await startWorker();
    status(`Engine ready (OpenCV ${version})`, "ok");
    if (outbox.items.length) outbox.flush();
    next();
  } catch (err) {
    $("loading-msg").textContent = err.message;
    status("Couldn't start", "bad");
  }
})();
