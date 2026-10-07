// Copied from pool-vision web/worker.js at ad9505e by scripts/sync_web.sh. Edit it there, not here.
// pool-vision Web Worker: does all the vision work off the page's main thread, so the
// camera preview and buttons stay smooth. A classic worker (so OpenCV.js can load with
// importScripts) that pulls in engine.js with import().
//
// Every request carries an `id`, and its reply (or error) echoes it.
// Messages in (from the page):
//   {type: "init"}
//   {type: "calibrate", frames: [ImageBitmap], tableSize, corners?: [[x, y] x4]}
//   {type: "start", cal}                        start tracking with this calibration (reply: started)
//   {type: "frame", frame: ImageBitmap, t}      one camera frame (t in seconds)
//   {type: "stop"}                             (reply: stopped)
// Messages out:
//   {type: "ready", version} | {type: "error", message}
//   {type: "calibrated", cal | null, view: ImageBitmap | null, balls}
//   {type: "tracked", view: ImageBitmap, tracks, counts, pots, log, ms}

/* global importScripts, cv */

let engine = null;
let tracker = null;
let cal = null;
let canvas = null; // OffscreenCanvas for reading frames and drawing the table view

function post(msg, transfer = []) {
  self.postMessage(msg, transfer);
}

async function init() {
  importScripts("opencv.js");
  await new Promise((resolve) => {
    if (cv.Mat) resolve();
    else cv.onRuntimeInitialized = resolve;
  });
  // the page loads this worker as worker.js?v=<version>: pass it on so a phone never
  // pairs a fresh worker with a stale cached engine
  const v = new URL(self.location.href).searchParams.get("v");
  engine = await import(`./engine.js${v ? `?v=${encodeURIComponent(v)}` : ""}`);
  engine.useOpenCV(cv);
  post({ id: 0, type: "ready", version: cv.getBuildInformation().match(/Version control:\s*(\S+)/)?.[1] || "?" });
}

/** An ImageBitmap as an RGBA cv.Mat. */
function toRGBA(bitmap) {
  if (!canvas || canvas.width !== bitmap.width || canvas.height !== bitmap.height) {
    canvas = new OffscreenCanvas(bitmap.width, bitmap.height);
  }
  const ctx = canvas.getContext("2d", { willReadFrequently: true });
  ctx.drawImage(bitmap, 0, 0);
  bitmap.close();
  return cv.matFromImageData(ctx.getImageData(0, 0, canvas.width, canvas.height));
}

/** An ImageBitmap as a BGR cv.Mat (as Python sees camera frames). */
function toBGR(bitmap) {
  const rgba = toRGBA(bitmap);
  const bgr = new cv.Mat();
  cv.cvtColor(rgba, bgr, cv.COLOR_RGBA2BGR);
  rgba.delete();
  return bgr;
}

/** A BGR cv.Mat as an ImageBitmap the page can draw. */
function toBitmap(bgr) {
  const rgba = new cv.Mat();
  cv.cvtColor(bgr, rgba, cv.COLOR_BGR2RGBA);
  const img = new ImageData(new Uint8ClampedArray(rgba.data), rgba.cols, rgba.rows);
  rgba.delete();
  return createImageBitmap(img);
}

/** The felt corners each frame shows, then their median: a player walking past one frame
 * (or leaning over the table) doesn't drag the outline with them. */
function medianCorners(bgrs) {
  const quads = bgrs.map((m) => engine.detectFeltQuad(m)).filter(Boolean);
  if (!quads.length) return null;
  const mid = (vals) => vals.sort((a, b) => a - b)[Math.floor((vals.length - 1) / 2)];
  return [0, 1, 2, 3].map((i) => [mid(quads.map((q) => q[i][0])), mid(quads.map((q) => q[i][1]))]);
}

async function calibrate({ id, frames, tableSize, corners }) {
  const bgrs = frames.map(toBGR);
  const bgr = bgrs[bgrs.length - 1];
  try {
    if (!corners) corners = medianCorners(bgrs);
    const found = corners ? engine.calibrateFromCorners(bgr, corners, tableSize) : null;
    if (!found) {
      post({ id, type: "calibrated", cal: null, view: null, balls: [] });
      return;
    }
    const view = engine.warp(bgr, found);
    const { dets, occ } = engine.detectFrame(view, found);
    occ.delete();
    const bitmap = await toBitmap(view);
    view.delete();
    post({ id, type: "calibrated", cal: found, view: bitmap, balls: dets }, [bitmap]);
  } finally {
    bgrs.forEach((m) => m.delete());
  }
}

async function track({ id, frame, t }) {
  if (!tracker) {
    frame.close();
    post({ id, type: "stopped" });
    return;
  }
  const t0 = performance.now();
  // Warp first, then convert the (smaller) table view to BGR. Warping interpolates each
  // channel on its own, so this gives exactly the pixels of converting first.
  const rgba = toRGBA(frame);
  const view4 = engine.warp(rgba, cal);
  rgba.delete();
  const view = new cv.Mat();
  cv.cvtColor(view4, view, cv.COLOR_RGBA2BGR);
  view4.delete();
  const { dets, occ } = engine.detectFrame(view, cal);
  const occluder = new engine.Occluder(occ.data, occ.cols, occ.rows);
  const pots = tracker.update(t, dets, occluder.any ? occluder : null).map((e) => ({
    ...engine.potToDict(e), text: engine.describePot(e),
  }));
  occ.delete();
  const log = tracker.log.splice(0);
  const tracks = tracker.tracks.map((tr) => ({
    x: tr.x, y: tr.y, kind: tr.kind, confirmed: tr.confirmed, missing: tr.misses > 0, hidden: tr.hidden,
  }));
  const counts = tracker.onTable().toObject();
  const bitmap = await toBitmap(view);
  view.delete();
  post({ id, type: "tracked", view: bitmap, tracks, counts, pots, log, ms: performance.now() - t0 }, [bitmap]);
}

self.onmessage = async ({ data }) => {
  try {
    if (data.type === "init") await init();
    else if (data.type === "calibrate") await calibrate(data);
    else if (data.type === "start") {
      cal = data.cal;
      tracker = new engine.Tracker(engine.ballRadiusPx(cal.table_size), cal.pockets);
      post({ id: data.id, type: "started" });
    } else if (data.type === "frame") await track(data);
    else if (data.type === "stop") {
      tracker = null;
      post({ id: data.id, type: "stopped" });
    }
  } catch (e) {
    // OpenCV.js throws plain numbers (pointers to C++ exceptions); turn them into words
    const ocv = typeof cv === "undefined" ? null : cv;
    const text = typeof e === "number" && ocv?.exceptionFromPtr ? ocv.exceptionFromPtr(e).msg : String(e?.message ?? e);
    post({ id: data.id, type: "error", message: text });
  }
};
