// Copied from pool-vision web/engine.js at ad9505e by scripts/sync_web.sh. Edit it there, not here.
// pool-vision engine for the browser: a line-by-line port of pool_vision/table.py,
// balls.py and tracker.py on OpenCV.js, so the phone can watch the table by itself.
//
// The Python package is the reference. Every threshold here is the Python one, and
// scripts/check_js_parity.py checks this file against it on the labelled real-table
// frames and on the recorded games. Change the Python first, then port the change here.
//
// Frames are BGR cv.Mats (convert from the canvas' RGBA first), as in Python. Calibrations
// are the same JSON as calibration.json. Works in a browser, a Web Worker or Node:
// call useOpenCV(cv) once OpenCV.js has loaded.

export const WARP_W = 1000;
export const WARP_H = 500;
export const MARGIN = 50;
export const VIEW_W = WARP_W + 2 * MARGIN;
export const VIEW_H = WARP_H + 2 * MARGIN;
export const PLAY_LENGTH_IN = { "7ft": 78.0, "8ft": 88.0, "9ft": 100.0 };
const CUSHION_IN = 2.0;
const BALL_DIAMETER_IN = 2.25;
export const POCKET_NAMES = [
  "top-left corner", "top side", "top-right corner",
  "bottom-right corner", "bottom side", "bottom-left corner",
];
const SIDE_POCKETS = [1, 4];
export const BALL_NUMBERS = {
  yellow: [1, 9], blue: [2, 10], red: [3, 11], purple: [4, 12],
  orange: [5, 13], green: [6, 14], maroon: [7, 15],
};

let cv = null;
export function useOpenCV(module) {
  cv = module;
}

// ---------------------------------------------------------------- small helpers

const mod = (a, n) => ((a % n) + n) % n; // Python's %
const hypot = Math.hypot;
const PI = Math.PI;

function roundHalfEven(x) { // Python's round()
  const r = Math.round(x);
  return Math.abs(x % 1) === 0.5 && r % 2 !== 0 ? r - 1 : r;
}

function median(values) { // numpy.median
  const a = Float64Array.from(values).sort();
  const n = a.length;
  if (!n) return NaN;
  return n % 2 ? a[(n - 1) / 2] : (a[n / 2 - 1] + a[n / 2]) / 2;
}

function percentile(values, q) { // numpy.percentile, linear
  const a = Float64Array.from(values).sort();
  const pos = (q / 100) * (a.length - 1);
  const lo = Math.floor(pos), hi = Math.ceil(pos);
  return a[lo] + (a[hi] - a[lo]) * (pos - lo);
}

function medianOfChannels(mat3, x0, y0, x1, y1) { // per-channel median of an 8-bit 3-channel region
  const hists = [new Uint32Array(256), new Uint32Array(256), new Uint32Array(256)];
  const d = mat3.data, w = mat3.cols;
  for (let y = y0; y < y1; y++) {
    for (let x = x0; x < x1; x++) {
      const i = (y * w + x) * 3;
      hists[0][d[i]]++; hists[1][d[i + 1]]++; hists[2][d[i + 2]]++;
    }
  }
  const n = (x1 - x0) * (y1 - y0);
  return hists.map((hist) => {
    const kth = (k) => { let c = 0; for (let v = 0; v < 256; v++) { c += hist[v]; if (c > k) return v; } return 255; };
    return n % 2 ? kth((n - 1) / 2) : (kth(n / 2 - 1) + kth(n / 2)) / 2;
  });
}

function ellipseKernel(k) {
  return cv.getStructuringElement(cv.MORPH_ELLIPSE, new cv.Size(k, k));
}

function contourPoints(c) { // a CV_32SC2 contour as [[x, y], ...]
  const d = c.data32S, out = [];
  for (let i = 0; i < d.length; i += 2) out.push([d[i], d[i + 1]]);
  return out;
}

function polygonArea(pts) { // cv2.contourArea of a closed polygon
  let a = 0;
  for (let i = 0; i < pts.length; i++) {
    const [x1, y1] = pts[i], [x2, y2] = pts[(i + 1) % pts.length];
    a += x1 * y2 - x2 * y1;
  }
  return Math.abs(a) / 2;
}

// ---------------------------------------------------------------- table (table.py)

export function ballRadiusPx(tableSize) {
  return (BALL_DIAMETER_IN / 2 / (PLAY_LENGTH_IN[tableSize] + 2 * CUSHION_IN)) * WARP_W;
}

export function expectedPockets() {
  const x0 = MARGIN, y0 = MARGIN, x1 = MARGIN + WARP_W, y1 = MARGIN + WARP_H, xm = MARGIN + WARP_W / 2;
  return [[x0, y0], [xm, y0], [x1, y0], [x1, y1], [xm, y1], [x0, y1]];
}

export function homography(cal) {
  const src = cv.matFromArray(4, 1, cv.CV_32FC2, cal.corners.flat());
  const dst = cv.matFromArray(4, 1, cv.CV_32FC2, [
    MARGIN, MARGIN, MARGIN + WARP_W, MARGIN, MARGIN + WARP_W, MARGIN + WARP_H, MARGIN, MARGIN + WARP_H,
  ]);
  const H = cv.getPerspectiveTransform(src, dst);
  src.delete(); dst.delete();
  return H;
}

/** The top-down table view (VIEW_W x VIEW_H, BGR) of a camera frame. Caller deletes it. */
export function warp(frame, cal) {
  let src = frame, resized = null;
  const [fw, fh] = cal.frame_size;
  if (frame.cols !== fw || frame.rows !== fh) {
    resized = new cv.Mat();
    cv.resize(frame, resized, new cv.Size(fw, fh), 0, 0, cv.INTER_LINEAR);
    src = resized;
  }
  const H = homography(cal);
  const view = new cv.Mat();
  cv.warpPerspective(src, view, H, new cv.Size(VIEW_W, VIEW_H), cv.INTER_LINEAR, cv.BORDER_CONSTANT, new cv.Scalar());
  H.delete();
  if (resized) resized.delete();
  return view;
}

function orderLongEdgeFirst(pts) {
  const cx = pts.reduce((s, p) => s + p[0], 0) / pts.length;
  const cy = pts.reduce((s, p) => s + p[1], 0) / pts.length;
  let sorted = pts.map((p) => [p, Math.atan2(p[1] - cy, p[0] - cx)]).sort((a, b) => a[1] - b[1]).map((e) => e[0]);
  let start = 0;
  sorted.forEach((p, i) => { if (p[0] + p[1] < sorted[start][0] + sorted[start][1]) start = i; });
  sorted = sorted.slice(start).concat(sorted.slice(0, start));
  const len = (a, b) => hypot(b[0] - a[0], b[1] - a[1]);
  if (len(sorted[0], sorted[1]) < len(sorted[1], sorted[2])) sorted = sorted.slice(1).concat(sorted.slice(0, 1));
  return sorted;
}

function lineIntersection(p1, p2, p3, p4) {
  const d1 = [p2[0] - p1[0], p2[1] - p1[1]], d2 = [p4[0] - p3[0], p4[1] - p3[1]];
  const den = d1[0] * d2[1] - d1[1] * d2[0];
  if (Math.abs(den) < 1e-6) return null;
  const s = ((p3[0] - p1[0]) * d2[1] - (p3[1] - p1[1]) * d2[0]) / den;
  return [p1[0] + s * d1[0], p1[1] + s * d1[1]];
}

function edgeTurn(a, b, c, d) { // degrees between edge a->b and edge c->d, folded to [-180, 180)
  const ang = (Math.atan2(b[1] - a[1], b[0] - a[0]) - Math.atan2(d[1] - c[1], d[0] - c[0])) * (180 / PI);
  return Math.abs(mod(ang + 180, 360) - 180);
}

function quadFromHull(hull) {
  const peri = cv.arcLength(hull, true);
  const approx = new cv.Mat();
  cv.approxPolyDP(hull, approx, 0.004 * peri, true);
  const poly = contourPoints(approx);
  approx.delete();
  if (poly.length < 4) return null;
  const edges = [];
  for (let i = 0; i < poly.length; i++) {
    const a = poly[i], b = poly[(i + 1) % poly.length];
    if (edges.length) {
      const [pa, pb] = edges[edges.length - 1];
      if (edgeTurn(pa, pb, a, b) < 8) { edges[edges.length - 1] = [pa, b]; continue; }
    }
    edges.push([a, b]);
  }
  if (edges.length > 1) {
    const [fa, fb] = edges[0], [la, lb] = edges[edges.length - 1];
    if (edgeTurn(la, lb, fa, fb) < 8) { edges[0] = [la, fb]; edges.pop(); }
  }
  if (edges.length < 4) return null;
  const lengths = edges.map(([a, b]) => hypot(b[0] - a[0], b[1] - a[1]));
  const keep = lengths.map((l, i) => [l, i]).sort((x, y) => x[0] - y[0]).slice(-4).map((e) => e[1]).sort((x, y) => x - y);
  const sides = keep.map((i) => edges[i]);
  const corners = [];
  for (let i = 0; i < 4; i++) {
    const [a1, b1] = sides[i], [a2, b2] = sides[(i + 1) % 4];
    const p = lineIntersection(a1, b1, a2, b2);
    if (!p) return null;
    corners.push(p);
  }
  return corners;
}

function quadIsSane(quad, hullArea, h, w) {
  if (!quad.every(([x]) => x > -0.05 * w && x < 1.05 * w)) return false;
  if (!quad.every(([, y]) => y > -0.05 * h && y < 1.05 * h)) return false;
  const ratio = polygonArea(quad) / Math.max(hullArea, 1.0);
  return ratio > 0.85 && ratio < 1.4;
}

/** The felt's 4 corners in camera pixels (ordered long edge first, starting top-left-most), or null. */
export function detectFeltQuad(frame, thresholds = [22, 26, 30, 34]) {
  const h = frame.rows, w = frame.cols;
  const blur = new cv.Mat(), lab = new cv.Mat();
  cv.GaussianBlur(frame, blur, new cv.Size(7, 7), 0, 0, cv.BORDER_DEFAULT);
  cv.cvtColor(blur, lab, cv.COLOR_BGR2Lab);
  blur.delete();
  const [L0, a0, b0] = medianOfChannels(lab, Math.trunc(w * 0.4), Math.trunc(h * 0.4), Math.trunc(w * 0.6), Math.trunc(h * 0.6));
  const ld = lab.data, n = h * w;
  const dist = new Float32Array(n);
  for (let i = 0; i < n; i++) {
    const dl = 0.35 * (ld[3 * i] - L0), da = ld[3 * i + 1] - a0, db = ld[3 * i + 2] - b0;
    dist[i] = Math.sqrt(da * da + db * db + dl * dl);
  }
  lab.delete();
  const kernel = ellipseKernel(Math.max(5, Math.floor(Math.min(h, w) / 60) | 1));
  const mask = new cv.Mat(h, w, cv.CV_8UC1);
  let result = null, fallback = null;
  for (const threshold of thresholds) {
    const md = mask.data;
    for (let i = 0; i < n; i++) md[i] = dist[i] < threshold ? 255 : 0;
    cv.morphologyEx(mask, mask, cv.MORPH_CLOSE, kernel);
    cv.morphologyEx(mask, mask, cv.MORPH_OPEN, kernel);
    const contours = new cv.MatVector(), hier = new cv.Mat();
    cv.findContours(mask, contours, hier, cv.RETR_EXTERNAL, cv.CHAIN_APPROX_SIMPLE);
    let best = -1, bestArea = 0;
    for (let i = 0; i < contours.size(); i++) {
      const a = cv.contourArea(contours.get(i));
      if (best < 0 || a > bestArea) { best = i; bestArea = a; }
    }
    if (best >= 0 && bestArea >= 0.05 * h * w) {
      const biggest = contours.get(best);
      const hull = new cv.Mat();
      cv.convexHull(biggest, hull, false, true);
      const hullArea = cv.contourArea(hull);
      const quad = quadFromHull(hull);
      hull.delete();
      if (quad && quadIsSane(quad, hullArea, h, w)) {
        result = orderLongEdgeFirst(quad);
      } else if (!fallback) {
        const box = cv.RotatedRect.points(cv.minAreaRect(biggest)).map((p) => [p.x, p.y]);
        if (quadIsSane(box, hullArea, h, w)) fallback = orderLongEdgeFirst(box);
      }
    }
    contours.delete(); hier.delete();
    if (result) break;
  }
  mask.delete(); kernel.delete();
  return result || fallback;
}

/**
 * Place the pockets the camera couldn't see from the ones it could: a shift from 1
 * found pocket, shift + scale + turn from 2, an affine fit from 3 or more (see the
 * Python docstring for why).
 */
export function inferMissingPockets(pockets, found, ballR) {
  const expected = expectedPockets();
  let idx = found.map((f, i) => (f ? i : -1)).filter((i) => i >= 0);
  const missing = found.map((f, i) => (f ? -1 : i)).filter((i) => i >= 0);
  const got = pockets.map((p) => [p[0], p[1]]);
  if (!idx.length || !missing.length) return got;
  if (idx.length >= 3 && idx.every((i) => expected[i][1] === expected[idx[0]][1])) {
    // all on one rail: an affine fit can't see across the table, so use the two
    // found pockets furthest apart
    const xs = idx.map((i) => expected[i][0]);
    idx = [idx[xs.indexOf(Math.min(...xs))], idx[xs.indexOf(Math.max(...xs))]];
  }
  let moved;
  if (idx.length === 1) {
    const [i] = idx, dx = got[i][0] - expected[i][0], dy = got[i][1] - expected[i][1];
    moved = missing.map((m) => [expected[m][0] + dx, expected[m][1] + dy]);
  } else if (idx.length === 2) {
    // similarity from two points, as complex numbers: z' = a*z + b
    const [i, j] = idx;
    const s0 = expected[i], s1 = expected[j], d0 = got[i], d1 = got[j];
    const sr = s1[0] - s0[0], si = s1[1] - s0[1], dr = d1[0] - d0[0], di = d1[1] - d0[1];
    const den = sr * sr + si * si;
    const ar = (dr * sr + di * si) / den, ai = (di * sr - dr * si) / den;
    const br = d0[0] - (ar * s0[0] - ai * s0[1]), bi = d0[1] - (ar * s0[1] + ai * s0[0]);
    moved = missing.map((m) => {
      const [x, y] = expected[m];
      return [ar * x - ai * y + br, ar * y + ai * x + bi];
    });
  } else {
    // least squares affine: [x y 1] M = [x' y']
    const A = idx.map((i) => [expected[i][0], expected[i][1], 1]);
    const M = solveAffine(A, idx.map((i) => got[i]));
    const apply = ([x, y]) => [x * M[0][0] + y * M[1][0] + M[2][0], x * M[0][1] + y * M[1][1] + M[2][1]];
    let sq = 0;
    idx.forEach((i, k) => { const [px, py] = apply(A[k]); sq += (px - got[i][0]) ** 2 + (py - got[i][1]) ** 2; });
    if (Math.sqrt(sq / idx.length) > 1.5 * ballR) return got;
    moved = missing.map((m) => apply(expected[m]));
  }
  missing.forEach((m, k) => {
    // a found pocket that is really a shadow can fling the others
    if (hypot(moved[k][0] - expected[m][0], moved[k][1] - expected[m][1]) <= 5 * ballR) got[m] = moved[k];
    else got[m] = [expected[m][0], expected[m][1]];
  });
  return got;
}

function solveAffine(A, B) { // normal equations, 3x3: (A^T A) M = A^T B
  const AtA = [[0, 0, 0], [0, 0, 0], [0, 0, 0]], AtB = [[0, 0], [0, 0], [0, 0]];
  A.forEach((row, k) => {
    for (let i = 0; i < 3; i++) {
      for (let j = 0; j < 3; j++) AtA[i][j] += row[i] * row[j];
      AtB[i][0] += row[i] * B[k][0]; AtB[i][1] += row[i] * B[k][1];
    }
  });
  const m = AtA.map((r, i) => [...r, ...AtB[i]]); // Gauss-Jordan on [AtA | AtB]
  for (let c = 0; c < 3; c++) {
    let p = c;
    for (let r = c + 1; r < 3; r++) if (Math.abs(m[r][c]) > Math.abs(m[p][c])) p = r;
    [m[c], m[p]] = [m[p], m[c]];
    for (let r = 0; r < 3; r++) {
      if (r === c) continue;
      const f = m[r][c] / m[c][c];
      for (let k = c; k < 5; k++) m[r][k] -= f * m[c][k];
    }
  }
  return m.map((r, i) => [r[3] / m[i][i], r[4] / m[i][i]]);
}

/** Snap each expected pocket to the nearest dark blob. Returns {pockets, found}. */
export function findPockets(view, ballR) {
  const hsv = new cv.Mat();
  cv.cvtColor(view, hsv, cv.COLOR_BGR2HSV);
  const n = VIEW_W * VIEW_H;
  const dark = new cv.Mat(VIEW_H, VIEW_W, cv.CV_8UC1);
  { // (mat.data builds a new view on every access: read it once, outside the loop)
    const dd = dark.data, hd = hsv.data;
    for (let i = 0; i < n; i++) dd[i] = hd[3 * i + 2] < 60 ? 255 : 0;
  }
  hsv.delete();
  const ones5 = cv.Mat.ones(5, 5, cv.CV_8U);
  cv.morphologyEx(dark, dark, cv.MORPH_OPEN, ones5);
  ones5.delete();
  const k = ellipseKernel(Math.max(3, Math.trunc(ballR * 0.8) | 1));
  cv.morphologyEx(dark, dark, cv.MORPH_CLOSE, k);
  k.delete();
  const search = Math.trunc(ballR * 5);
  const minArea = PI * (ballR * 0.8) ** 2;
  const inset = 2 * ballR;
  const ix0 = Math.trunc(MARGIN + inset), ix1 = Math.trunc(MARGIN + WARP_W - inset);
  const iy0 = Math.trunc(MARGIN + inset), iy1 = Math.trunc(MARGIN + WARP_H - inset);
  const inner = (x, y) => MARGIN + inset < x && x < MARGIN + WARP_W - inset && MARGIN + inset < y && y < MARGIN + WARP_H - inset;
  const pockets = [], found = [];
  for (const [ex, ey] of expectedPockets()) {
    const x0 = Math.trunc(Math.max(0, ex - search)), y0 = Math.trunc(Math.max(0, ey - search));
    const x1 = Math.trunc(Math.min(VIEW_W, ex + search)), y1 = Math.trunc(Math.min(VIEW_H, ey + search));
    const roi = dark.roi(new cv.Rect(x0, y0, x1 - x0, y1 - y0)).clone();
    const contours = new cv.MatVector(), hier = new cv.Mat();
    cv.findContours(roi, contours, hier, cv.RETR_EXTERNAL, cv.CHAIN_APPROX_SIMPLE);
    let best = null;
    for (let i = 0; i < contours.size(); i++) {
      const cnt = contours.get(i);
      if (cv.contourArea(cnt) < minArea) continue;
      const m = cv.moments(cnt, false);
      const cx = m.m10 / m.m00 + x0, cy = m.m01 / m.m00 + y0;
      if (inner(cx, cy)) continue;
      const d = hypot(cx - ex, cy - ey);
      if (!best || d < best[0]) best = [d, cx, cy];
    }
    contours.delete(); hier.delete(); roi.delete();
    if (!best) {
      // no blob big enough: the centre of all the dark pixels on the rail around here
      let sx = 0, sy = 0, c = 0;
      const dd = dark.data;
      for (let y = y0; y < y1; y++) {
        for (let x = x0; x < x1; x++) {
          const onRim = !(y >= iy0 && y < iy1 && x >= ix0 && x < ix1);
          if (onRim && dd[y * VIEW_W + x]) { sx += x; sy += y; c++; }
        }
      }
      if (c >= 0.5 * minArea) best = [0, sx / c, sy / c];
    }
    pockets.push(best ? [best[1], best[2]] : [ex, ey]);
    found.push(best !== null);
  }
  dark.delete();
  return { pockets: inferMissingPockets(pockets, found, ballR), found };
}

/** Build a calibration from 4 felt corners (camera pixels, any order). */
export function calibrateFromCorners(frame, corners, tableSize = "7ft") {
  const cal = {
    corners: orderLongEdgeFirst(corners.map((p) => [p[0], p[1]])),
    frame_size: [frame.cols, frame.rows],
    table_size: tableSize,
    felt_lab: [0, 0, 0],
    pockets: [],
    pockets_found: [],
  };
  const view = warp(frame, cal);
  const lab = new cv.Mat();
  cv.cvtColor(view, lab, cv.COLOR_BGR2Lab);
  cal.felt_lab = medianOfChannels(lab, MARGIN + 40, MARGIN + 40, MARGIN + WARP_W - 40, MARGIN + WARP_H - 40);
  lab.delete();
  const { pockets, found } = findPockets(view, ballRadiusPx(tableSize));
  view.delete();
  cal.pockets = pockets;
  cal.pockets_found = found;
  return cal;
}

/** Find the table by itself. Returns a calibration, or null if there's no felt in view. */
export function autoCalibrate(frame, tableSize = "7ft") {
  const corners = detectFeltQuad(frame);
  return corners ? calibrateFromCorners(frame, corners, tableSize) : null;
}

// ---------------------------------------------------------------- balls (balls.py)

function feltHsv(cal) {
  const px = cv.matFromArray(1, 1, cv.CV_8UC3, cal.felt_lab.map((v) => Math.trunc(Math.min(255, Math.max(0, v)))));
  const bgr = new cv.Mat(), hsv = new cv.Mat();
  cv.cvtColor(px, bgr, cv.COLOR_Lab2BGR);
  cv.cvtColor(bgr, hsv, cv.COLOR_BGR2HSV);
  const out = [hsv.data[0], hsv.data[1], hsv.data[2]];
  px.delete(); bgr.delete(); hsv.delete();
  return out;
}

/** 255 where the view is not felt (balls, hands, the cue), inside the felt rectangle. */
export function nonfeltMask(view, cal, threshold = 28.0) {
  const blur = new cv.Mat();
  cv.GaussianBlur(view, blur, new cv.Size(5, 5), 0, 0, cv.BORDER_DEFAULT);
  const x0 = MARGIN + 3, y0 = MARGIN + 3, cw = WARP_W - 6, ch = WARP_H - 6;
  const crop = blur.roi(new cv.Rect(x0, y0, cw, ch));
  const lab = new cv.Mat(), hsv = new cv.Mat();
  cv.cvtColor(crop, lab, cv.COLOR_BGR2Lab);
  cv.cvtColor(crop, hsv, cv.COLOR_BGR2HSV);
  crop.delete(); blur.delete();
  const [L0, a0, b0] = cal.felt_lab;
  const [fh, fs, fv] = feltHsv(cal);
  const t2 = threshold * threshold;
  const mask = cv.Mat.zeros(VIEW_H, VIEW_W, cv.CV_8UC1);
  const ld = lab.data, hd = hsv.data, md = mask.data;
  for (let y = 0; y < ch; y++) {
    for (let x = 0; x < cw; x++) {
      const i = 3 * (y * cw + x);
      const dl = 0.35 * (ld[i] - L0), da = ld[i + 1] - a0, db = ld[i + 2] - b0;
      const d2 = da * da + db * db + dl * dl;
      if (d2 <= t2) continue;
      const hh = hd[i], ss = hd[i + 1], vv = hd[i + 2];
      if (vv < 15) continue; // pocket liner
      let dh = Math.abs(hh - Math.trunc(fh));
      dh = Math.min(dh, 180 - dh);
      const shadow = dh < 14 && ss > 0.55 * fs && vv < 1.1 * fv && (d2 < 36 * 36 || vv < 0.6 * fv);
      if (!shadow) md[(y + y0) * VIEW_W + x + x0] = 255;
    }
  }
  lab.delete(); hsv.delete();
  const ones3 = cv.Mat.ones(3, 3, cv.CV_8U), e5 = ellipseKernel(5);
  cv.morphologyEx(mask, mask, cv.MORPH_OPEN, ones3);
  cv.morphologyEx(mask, mask, cv.MORPH_CLOSE, e5);
  ones3.delete(); e5.delete();
  // fill ball-sized holes: a blue/purple ball matching the felt leaves only its white
  const contours = new cv.MatVector(), hier = new cv.Mat();
  cv.findContours(mask, contours, hier, cv.RETR_CCOMP, cv.CHAIN_APPROX_SIMPLE);
  const holeMax = PI * (2 * ballRadiusPx(cal.table_size)) ** 2;
  for (let i = 0; i < contours.size(); i++) {
    if (hier.data32S[4 * i + 3] >= 0 && cv.contourArea(contours.get(i)) < holeMax) {
      cv.drawContours(mask, contours, i, new cv.Scalar(255), -1);
    }
  }
  contours.delete(); hier.delete();
  return mask;
}

function components(mask) { // {n, labels: Int32Array, stats: Int32Array (n x 5)}
  const labels = new cv.Mat(), stats = new cv.Mat(), cents = new cv.Mat();
  const n = cv.connectedComponentsWithStats(mask, labels, stats, cents, 8, cv.CV_32S);
  const out = { n, labels: Int32Array.from(labels.data32S), stats: Int32Array.from(stats.data32S), cents: Float64Array.from(cents.data64F) };
  labels.delete(); stats.delete(); cents.delete();
  return out;
}
const AREA = 4; // cv.CC_STAT_AREA

/** Ball centres: distance-transform peaks, with touching balls split. */
export function findBallCenters(mask, r) {
  const W = mask.cols, H = mask.rows;
  const distM = new cv.Mat();
  cv.distanceTransform(mask, distM, cv.DIST_L2, 5);
  const { n, labels, stats } = components(mask);
  const dist = distM.data32F;
  const fat = new Uint32Array(n);
  for (let i = 0; i < W * H; i++) if (dist[i] > 2.2 * r) fat[labels[i]]++;
  const tooFat = Array.from(fat, (f, l) => f > 0.05 * stats[l * 5 + AREA]);
  const k = Math.max(3, Math.trunc(r * 1.2) | 1);
  const dilM = new cv.Mat(), ker = cv.Mat.ones(k, k, cv.CV_8U);
  cv.dilate(distM, dilM, ker);
  ker.delete();
  const dil = dilM.data32F;
  const cands = [];
  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      const i = y * W + x, d = dist[i];
      if (d >= dil[i] - 1e-3 && d >= 0.55 * r && d <= 2.2 * r && !tooFat[labels[i]]) cands.push([d, x, y]);
    }
  }
  distM.delete(); dilM.delete();
  cands.sort((a, b) => b[0] - a[0] || b[1] - a[1] || b[2] - a[2]); // Python: sort(reverse=True)
  const sameBall = (x, y, h, cx, cy, ch) => {
    if ((x - cx) ** 2 + (y - cy) ** 2 <= (1.4 * r) ** 2) return true;
    const near = ((x - cx) / (1.6 * r)) ** 2 + ((y - cy) / (2.8 * r)) ** 2 <= 1;
    return near && Math.min(h, ch) < 0.85 * r;
  };
  const centers = [];
  for (const [h, x, y] of cands) {
    if (!centers.some(([cx, cy, ch]) => sameBall(x, y, h, cx, cy, ch))) centers.push([x, y, h]);
  }
  return splitClusters(labels, W, stats, n, tooFat, centers.map(([x, y]) => [x, y]), r);
}

function splitClusters(labels, W, stats, n, tooFat, centers, r) {
  const a0 = PI * (1.4 * r) * (2.1 * r);
  const singles = [];
  for (let l = 1; l < n; l++) {
    const a = stats[l * 5 + AREA];
    if (a > 0.35 * a0 && a < 1.4 * a0) singles.push(a);
  }
  if (singles.length < 3) return centers;
  const unit = 1.2 * median(singles);
  let out = centers.slice();
  for (let l = 1; l < n; l++) {
    const area = stats[l * 5 + AREA];
    if (tooFat[l] || area < 1.6 * unit) continue;
    const mine = out.filter(([cx, cy]) => labels[Math.trunc(cy) * W + Math.trunc(cx)] === l);
    const k = Math.min(6, roundHalfEven(area / unit));
    if (mine.length >= k) continue;
    const pts = [];
    for (let i = 0; i < labels.length; i++) if (labels[i] === l) pts.push(i % W, Math.floor(i / W) / 1.45);
    const data = cv.matFromArray(pts.length / 2, 2, cv.CV_32F, pts);
    const best = new cv.Mat(), cc = new cv.Mat();
    cv.setRNGSeed(0);
    const crit = new cv.TermCriteria(cv.TermCriteria_EPS + cv.TermCriteria_MAX_ITER, 30, 0.5);
    cv.kmeans(data, k, best, crit, 3, cv.KMEANS_PP_CENTERS, cc);
    const fresh = [];
    for (let i = 0; i < k; i++) fresh.push([cc.data32F[2 * i], cc.data32F[2 * i + 1] * 1.45]);
    data.delete(); best.delete(); cc.delete();
    out = out.filter((c) => !mine.includes(c)).concat(fresh);
  }
  return out;
}

function hueToColor(h, s, v) {
  if (h < 6 || h >= 168) return v < 115 ? "maroon" : "red";
  if (h < 18) return v < 100 ? "maroon" : "orange";
  if (h < 36) return "yellow";
  if (h < 88) return "green";
  if (h < 132) return "blue";
  return "purple";
}

// white / black / grey / coloured, as in balls._pixel_classes
const isWhite = (s, v) => s < 60 && v > 170;
const isBlack = (s, v) => v < 90 && s < 110;
const isGrey = (s, v) => s < 60 && v >= 90 && v <= 170;
const isColored = (s, v) => !isWhite(s, v) && !isBlack(s, v) && !isGrey(s, v) && s >= 80 && v >= 60;

function looksLikeEight(black, grey, colored, n) {
  return black / n + (0.5 * grey) / n > 0.25 && colored / n < 0.3;
}

/** Flat indices of the not-felt pixels that belong to the ball at (x, y) (balls._ball_pixels). */
function ballPixels(md, W, H, x, y, r, others) {
  const ax = 1.5 * r, ay = 2.4 * r;
  const x0 = Math.trunc(Math.max(0, x - ax)), x1 = Math.trunc(Math.min(W, x + ax + 1));
  const y0 = Math.trunc(Math.max(0, y - ay)), y1 = Math.trunc(Math.min(H, y + ay + 1));
  const out = [];
  for (let yy = y0; yy < y1; yy++) {
    for (let xx = x0; xx < x1; xx++) {
      if (((xx - x) / ax) ** 2 + ((yy - y) / ay) ** 2 > 1 || !md[yy * W + xx]) continue;
      if (others.length) {
        const mine = (xx - x) ** 2 + ((yy - y) / 1.45) ** 2;
        if (others.some(([ox, oy]) => mine > (xx - ox) ** 2 + ((yy - oy) / 1.45) ** 2)) continue;
      }
      out.push(yy * W + xx);
    }
  }
  return out;
}

/** [kind, color, white_frac] for the ball at (x, y), from its own pixels (balls.classify). */
export function classify(hsvData, md, W, H, x, y, r, ballArea, others) {
  const px = ballPixels(md, W, H, x, y, r, others);
  const n = px.length;
  if (n < 0.5 * PI * r * r) return ["unknown", null, 0.0];
  let white = 0, black = 0, grey = 0, colored = 0;
  for (const i of px) {
    const s = hsvData[3 * i + 1], v = hsvData[3 * i + 2];
    if (isWhite(s, v)) white++;
    else if (isBlack(s, v)) black++;
    else if (isGrey(s, v)) grey++;
    if (isColored(s, v)) colored++;
  }
  const whiteF = white / n, coloredF = colored / n;
  if (looksLikeEight(black, grey, colored, n)) return ["eight", null, whiteF];
  const inner = ballPixels(md, W, H, x, y, 0.7 * r, others);
  if (inner.length > 20) {
    let b = 0, g = 0, c = 0;
    for (const i of inner) {
      const s = hsvData[3 * i + 1], v = hsvData[3 * i + 2];
      if (!isWhite(s, v) && isBlack(s, v)) b++;
      else if (!isWhite(s, v) && isGrey(s, v)) g++;
      if (isColored(s, v)) c++;
    }
    if (looksLikeEight(b, g, c, inner.length)) return ["eight", null, whiteF];
  }
  if (whiteF > 0.7 && coloredF < 0.08) {
    // the cue is a whole ball; a smaller all-white blob is a blue/purple stripe's cap
    if (ballArea && n < 0.75 * ballArea) return ["stripe", null, whiteF];
    return ["cue", null, whiteF];
  }
  if (coloredF < 0.04) return ["unknown", null, whiteF];
  let sin = 0, cos = 0;
  const ss = [], vs = [];
  for (const i of px) {
    const s = hsvData[3 * i + 1], v = hsvData[3 * i + 2];
    if (!isColored(s, v)) continue;
    const ang = (hsvData[3 * i] / 180.0) * 2 * PI;
    sin += Math.sin(ang); cos += Math.cos(ang);
    ss.push(s); vs.push(v);
  }
  const meanAng = Math.atan2(sin / ss.length, cos / ss.length);
  const hue = (mod(meanAng, 2 * PI) / (2 * PI)) * 180.0;
  const color = hueToColor(hue, median(ss), median(vs));
  const whitePerBall = white / Math.max(n, ballArea);
  return [whitePerBall >= 0.2 ? "stripe" : "solid", color, whiteF];
}

/** Where a player is over the table: big not-felt shapes crossing the rail, grown 1.5r. */
export function occluderMask(mask, r) {
  const { n, labels, stats } = components(mask);
  const x0 = MARGIN + 3, y0 = MARGIN + 3, x1 = MARGIN + WARP_W - 3, y1 = MARGIN + WARP_H - 3;
  const occ = cv.Mat.zeros(mask.rows, mask.cols, cv.CV_8UC1);
  const pick = new Uint8Array(n);
  let any = false;
  for (let k = 1; k < n; k++) {
    const [x, y, w, h, area] = stats.subarray(k * 5, k * 5 + 5);
    const touches = x <= x0 + 2 || y <= y0 + 2 || x + w >= x1 - 2 || y + h >= y1 - 2;
    if (touches && area >= 3000) { pick[k] = 1; any = true; }
  }
  if (any) {
    const od = occ.data;
    for (let i = 0; i < labels.length; i++) if (pick[labels[i]]) od[i] = 255;
    const ker = ellipseKernel(Math.trunc(3 * r) | 1);
    dilateBinary(od, mask.cols, mask.rows, ker);
    ker.delete();
  }
  return occ;
}

/**
 * Exactly cv.dilate(img, img, kernel) for a 0/255 image and a symmetric kernel such as
 * MORPH_ELLIPSE, much faster in OpenCV.js (no SIMD there: a 41 px ellipse took ~270 ms).
 * Each kernel row is one horizontal run, so a pixel turns on when any of the rows above
 * or below has a set pixel within that row's half-width: counted with prefix sums, and
 * only in the box around the set pixels.
 */
function dilateBinary(data, W, H, kernel) {
  const kh = kernel.rows, kw = kernel.cols, kd = kernel.data, ay = kh >> 1, ax = kw >> 1;
  const half = []; // per kernel row: [dy, half-width] of its run (-1 = empty row)
  for (let ky = 0; ky < kh; ky++) {
    let lo = -1, hi = -1;
    for (let kx = 0; kx < kw; kx++) if (kd[ky * kw + kx]) { if (lo < 0) lo = kx; hi = kx; }
    if (lo >= 0) half.push([ky - ay, hi - ax, lo - ax]);
  }
  let x0 = W, x1 = -1, y0 = H, y1 = -1;
  for (let y = 0; y < H; y++) {
    for (let x = 0; x < W; x++) {
      if (data[y * W + x]) { if (x < x0) x0 = x; if (x > x1) x1 = x; if (y < y0) y0 = y; if (y > y1) y1 = y; }
    }
  }
  if (x1 < 0) return;
  const bx0 = Math.max(0, x0 - ax), bx1 = Math.min(W - 1, x1 + ax);
  const by0 = Math.max(0, y0 - ay), by1 = Math.min(H - 1, y1 + ay);
  const bw = bx1 - bx0 + 1;
  // prefix[y][i] = set pixels in row y from bx0 up to (not including) bx0 + i
  const prefix = new Int32Array((by1 - by0 + 1) * (bw + 1));
  for (let y = by0; y <= by1; y++) {
    const row = (y - by0) * (bw + 1);
    for (let i = 0; i < bw; i++) prefix[row + i + 1] = prefix[row + i] + (data[y * W + bx0 + i] ? 1 : 0);
  }
  const out = new Uint8Array(bw * (by1 - by0 + 1));
  for (let y = by0; y <= by1; y++) {
    for (let x = bx0; x <= bx1; x++) {
      for (const [dy, right, left] of half) {
        const sy = y + dy; // the kernel is symmetric: source rows y+dy, columns x+left..x+right
        if (sy < by0 || sy > by1) continue;
        const a = Math.max(bx0, x + left) - bx0, b = Math.min(bx1, x + right) - bx0 + 1;
        if (b > a && prefix[(sy - by0) * (bw + 1) + b] - prefix[(sy - by0) * (bw + 1) + a] > 0) {
          out[(y - by0) * bw + x - bx0] = 255;
          break;
        }
      }
    }
  }
  for (let y = by0; y <= by1; y++) data.set(out.subarray((y - by0) * bw, (y - by0 + 1) * bw), y * W + bx0);
}

/**
 * Balls on the table, plus the occluder mask (balls.detect_frame).
 * Returns {dets: [{x, y, kind, color, white_frac}], occ: cv.Mat}; caller deletes occ.
 */
export function detectFrame(view, cal, timings = null) {
  let t0 = performance.now();
  const lap = (name) => { if (timings) { const t = performance.now(); timings[name] = (timings[name] || 0) + t - t0; t0 = t; } };
  const r = ballRadiusPx(cal.table_size);
  const mask = nonfeltMask(view, cal);
  lap("mask");
  const occ = occluderMask(mask, r);
  lap("occluder");
  const W = VIEW_W, H = VIEW_H, od = occ.data, md = mask.data;
  const onTable = (x, y) => {
    if (od[Math.trunc(y) * W + Math.trunc(x)]) return false;
    return cal.pockets.every(([px, py], i) => hypot(x - px, y - py) > (SIDE_POCKETS.includes(i) ? 2.1 : 1.8) * r);
  };
  const centers = findBallCenters(mask, r).filter(([x, y]) => onTable(x, y));
  lap("centers");
  const near = centers.map(([x, y], i) => centers.filter((c, j) => j !== i && Math.abs(c[0] - x) < 3.2 * r && Math.abs(c[1] - y) < 5 * r));
  const sizes = centers.map(([x, y], i) => ballPixels(md, W, H, x, y, r, near[i]).length);
  const ballArea = sizes.length ? 0.8 * percentile(sizes, 75) : 0.0;
  lap("sizes");
  const hsv = new cv.Mat();
  cv.cvtColor(view, hsv, cv.COLOR_BGR2HSV);
  const out = [];
  centers.forEach(([x, y], i) => {
    const [kind, color, wf] = classify(hsv.data, md, W, H, x, y, r, ballArea, near[i]);
    // a dark blob in a pocket mouth is the pocket; just outside it, a corner pocket's
    // shadowed jaw has no highlight, the real 8 always shows some white
    const pocketD = Math.min(...cal.pockets.map(([px, py]) => hypot(x - px, y - py)));
    if ((kind === "eight" || kind === "unknown") && (pocketD < 2.2 * r || (pocketD < 3.5 * r && wf < 0.06))) return;
    out.push({ x, y, kind, color, white_frac: wf });
  });
  hsv.delete();
  lap("classify");
  for (const d of feltColouredBalls(view, cal, out, ballArea)) if (onTable(d.x, d.y)) out.push(d);
  lap("feltColoured");
  mask.delete();
  return { dets: out, occ };
}

function feltColouredBalls(view, cal, found, ballArea) {
  const r = ballRadiusPx(cal.table_size);
  const blur = new cv.Mat(), hsv = new cv.Mat();
  cv.GaussianBlur(view, blur, new cv.Size(3, 3), 0, 0, cv.BORDER_DEFAULT);
  cv.cvtColor(blur, hsv, cv.COLOR_BGR2HSV);
  blur.delete();
  const white = cv.Mat.zeros(VIEW_H, VIEW_W, cv.CV_8UC1);
  const inset = 3;
  const hd = hsv.data, wd = white.data;
  for (let y = MARGIN + inset; y < MARGIN + WARP_H - inset; y++) {
    for (let x = MARGIN + inset; x < MARGIN + WARP_W - inset; x++) {
      const i = y * VIEW_W + x;
      if (hd[3 * i + 1] < 60 && hd[3 * i + 2] > 170) wd[i] = 1;
    }
  }
  hsv.delete();
  const { n, stats, cents } = components(white);
  white.delete();
  const insideBall = (x, y) => found.some((b) => ((x - b.x) / (1.6 * r)) ** 2 + ((y - b.y) / (2.6 * r)) ** 2 <= 1);
  const spots = [];
  for (let i = 1; i < n; i++) {
    const area = stats[i * 5 + AREA], w = stats[i * 5 + 2], h = stats[i * 5 + 3];
    const x = cents[2 * i], y = cents[2 * i + 1];
    if (area < 30 || w > 2.2 * r || h > 3.0 * r) continue;
    if (insideBall(x, y) || cal.pockets.some(([px, py]) => hypot(x - px, y - py) < 3 * r)) continue;
    spots.push([x, y, area]);
  }
  const out = [], used = new Set();
  spots.forEach(([x, y], i) => {
    if (used.has(i)) return;
    const group = [i];
    for (let j = i + 1; j < spots.length; j++) {
      if (!used.has(j) && Math.abs(spots[j][0] - x) < 1.2 * r && Math.abs(spots[j][1] - y) < 2.6 * r) group.push(j);
    }
    group.forEach((g) => used.add(g));
    const total = group.reduce((s, g) => s + spots[g][2], 0);
    if (total < 80 || (ballArea && total > 0.7 * ballArea)) return;
    const gx = group.reduce((s, g) => s + spots[g][0], 0) / group.length;
    const gy = group.reduce((s, g) => s + spots[g][1], 0) / group.length;
    out.push({ x: gx, y: gy, kind: group.length >= 2 ? "stripe" : "solid", color: null, white_frac: 0.0 });
  });
  return out;
}

// ---------------------------------------------------------------- tracker (tracker.py)

// Tuned at 21-25 fps; rules counted in frames are scaled down at lower frame rates.
export const REFERENCE_FPS = 21.0;

/** A Counter: counts by key, kept in first-seen order (like collections.Counter). */
class Counter extends Map {
  inc(k, n = 1) { this.set(k, (this.get(k) || 0) + n); }
  of(k) { return this.get(k) || 0; }
  total() { let t = 0; for (const v of this.values()) t += v; return t; }
  mostCommon() { let best = null, bv = -Infinity; for (const [k, v] of this) if (v > bv) { best = k; bv = v; } return best; }
  equals(o) {
    const keys = new Set([...this.keys(), ...o.keys()]);
    for (const k of keys) if (this.of(k) !== o.of(k)) return false;
    return true;
  }
  toObject() { return Object.fromEntries(this); }
}

const GROUP = { solid: "solids", stripe: "stripes" };

export function describePot(ev) {
  if (ev.kind === "cue") return `SCRATCH: cue ball in the ${ev.pocket} pocket`;
  if (ev.kind === "eight") return `8-BALL potted in the ${ev.pocket} pocket`;
  return `Potted a ${ev.kind} in the ${ev.pocket} pocket`;
}

/** The same fields as Python's PotEvent.to_dict(). */
export function potToDict(ev) {
  const { t, track_id, kind, color, number, pocket, kind_conf } = ev;
  return { t, track_id, kind, color, number, pocket, kind_conf, group: GROUP[kind] || null };
}

/** An occluder mask the tracker can ask "is (x, y) covered?" (optionally at 1/step resolution). */
export class Occluder {
  constructor(data, w, h, step = 1) {
    this.data = data; this.w = w; this.h = h; this.step = step;
    this.any = data.some((v) => v);
  }
  covers(x, y) {
    const W = this.w * this.step, H = this.h * this.step;
    const xi = Math.min(W - 1, Math.max(0, Math.trunc(x))), yi = Math.min(H - 1, Math.max(0, Math.trunc(y)));
    return this.data[Math.trunc(yi / this.step) * this.w + Math.trunc(xi / this.step)] !== 0;
  }
}
const covers = (occ, x, y) => (occ ? occ.covers(x, y) : false);

class Track {
  constructor(id, x, y, t, born) {
    Object.assign(this, { id, x, y, t, vx: 0, vy: 0, hits: 1, misses: 0, confirmed: false, hidden: false, hiddenSince: 0, born });
    this.stillNeeded = 5; // still-frame votes before only those count (the Tracker scales it)
    this.stillDt = 0.1; // the longest frame gap that can still count as "sat still"
    this.history = []; // (t, x, y), last 90
    this.kinds = new Counter();
    this.stillKinds = new Counter();
    this.colors = new Counter();
  }

  observe(det, t) {
    const dt = t - this.t;
    const still = dt > 0 && dt <= this.stillDt && hypot(det.x - this.x, det.y - this.y) < 2.0;
    if (dt > 0) {
      const nvx = (det.x - this.x) / dt, nvy = (det.y - this.y) / dt;
      this.vx = 0.5 * this.vx + 0.5 * nvx;
      this.vy = 0.5 * this.vy + 0.5 * nvy;
    }
    this.x = det.x; this.y = det.y; this.t = t;
    this.history.push([t, det.x, det.y]);
    if (this.history.length > 90) this.history.shift();
    if (det.kind !== "unknown") {
      this.kinds.inc(det.kind);
      if (still) this.stillKinds.inc(det.kind);
    }
    if (det.color) this.colors.inc(det.color);
  }

  get votes() { return this.stillKinds.total() >= this.stillNeeded ? this.stillKinds : this.kinds; }

  get kind() {
    const votes = this.votes;
    if (!votes.size) return "unknown";
    const total = votes.total();
    if (votes.of("eight") >= 3 && votes.of("eight") >= 0.25 * total) return "eight";
    return votes.mostCommon();
  }

  get color() {
    const k = this.kind;
    if (k === "cue" || k === "eight") return null;
    return this.colors.size ? this.colors.mostCommon() : null;
  }

  get number() {
    const k = this.kind;
    if (k === "cue") return null;
    if (k === "eight") return 8;
    const c = this.color;
    if (c && (k === "solid" || k === "stripe")) return BALL_NUMBERS[c][k === "solid" ? 0 : 1];
    return null;
  }

  range(window) {
    const pts = this.history.filter(([t]) => t >= this.t - window);
    if (pts.length < 2) return 0.0;
    const xs = pts.map((p) => p[1]), ys = pts.map((p) => p[2]);
    return hypot(Math.max(...xs) - Math.min(...xs), Math.max(...ys) - Math.min(...ys));
  }

  moved(window) { return this.range(window); }
  wasMoving(r, window = 0.5) { return this.range(window) > 0.5 * r; }
}

/** Tracks balls across frames and decides pots (see tracker.py's module docstring). */
export class Tracker {
  constructor(ballR, pockets, opts = {}) {
    const o = { minHits: 5, lostTimeout: 0.8, pocketZone: 4.0, settleTime: 1.0, candidateTtl: 60.0, maxHidden: 120.0, clearConfirm: 1.5, ...opts };
    this.r = ballR;
    this.pockets = pockets;
    this.minHits = o.minHits;
    this.lostTimeout = o.lostTimeout;
    this.pocketZone = o.pocketZone * ballR;
    this.settleTime = o.settleTime;
    this.candidateTtl = o.candidateTtl;
    this.maxHidden = o.maxHidden;
    this.clearConfirm = o.clearConfirm;
    this.cand = new Map();
    this.totals = [];
    this.lastT = null;
    this.tracks = [];
    this.nextId = 1;
    this.log = [];
    this.pending = [];
    this.settled = null;
    this.recent = [];
    this.gaps = []; // recent frame intervals (last 50), for the frame rate
    this.projectFrames = 1.5; // how many unseen frames approach() follows a fast ball for
  }

  get frameGap() { return median(this.gaps); }

  /** 1.0 at REFERENCE_FPS or faster; e.g. 0.38 at 8 fps. */
  get scale() {
    return this.gaps.length < 5 ? 1.0 : Math.min(1.0, 1.0 / REFERENCE_FPS / this.frameGap);
  }

  frames(n, least = 1) { return Math.max(least, roundHalfEven(n * this.scale)); }

  update(t, dets, occluder = null) {
    if (this.lastT !== null && t > this.lastT) {
      this.gaps.push(t - this.lastT);
      if (this.gaps.length > 50) this.gaps.shift();
    }
    this.track(t, dets, occluder);
    const events = this.watchCandidates(t, occluder);
    return events.concat(this.census(t));
  }

  track(t, dets, occluder) {
    const pairs = [];
    this.tracks.forEach((tr, ti) => {
      const dt = Math.min(t - tr.t, 0.2);
      let px, py, gate;
      if (tr.misses === 0) {
        px = tr.x + tr.vx * dt; py = tr.y + tr.vy * dt;
        gate = 2.5 * this.r + 0.5 * hypot(tr.vx, tr.vy) * dt;
      } else {
        // lost for a moment: widen the search with the time since it was last seen
        px = tr.x; py = tr.y;
        gate = 2.5 * this.r + Math.min(10 * this.r, 1500.0 * (t - tr.t));
      }
      dets.forEach((d, di) => {
        const dist = hypot(d.x - px, d.y - py);
        if (dist <= gate) pairs.push([dist, ti, di]);
      });
    });
    pairs.sort((a, b) => a[0] - b[0] || a[1] - b[1] || a[2] - b[2]);
    const usedT = new Set(), usedD = new Set();
    for (const [, ti, di] of pairs) {
      if (usedT.has(ti) || usedD.has(di)) continue;
      usedT.add(ti); usedD.add(di);
      const tr = this.tracks[ti];
      tr.observe(dets[di], t);
      tr.hits += 1; tr.misses = 0; tr.hidden = false;
      if (tr.hits >= this.frames(this.minHits, 2)) tr.confirmed = true;
    }
    const keep = [];
    this.tracks.forEach((tr, ti) => {
      if (!usedT.has(ti) && tr.confirmed && covers(occluder, tr.x, tr.y)) {
        if (!tr.hidden) { tr.hidden = true; tr.hiddenSince = t; }
        if (t - tr.hiddenSince < this.maxHidden) keep.push(tr);
        return;
      }
      if (!usedT.has(ti)) {
        if (tr.hidden) tr.hidden = false;
        tr.misses += 1;
        if (!tr.confirmed) {
          if (tr.misses > this.frames(3)) return;
        } else if (t - tr.t > this.lostTimeout) {
          const ev = this.finalize(tr);
          if (ev && !this.duplicate(ev)) {
            if (ev.kind === "cue") this.pending = this.pending.filter((c) => c.kind !== "cue");
            this.pending.push(ev);
          }
          return;
        }
      }
      keep.push(tr);
    });
    this.tracks = keep;
    const scaled = this.scale < 1.0;
    const stillNeeded = this.frames(5, 2), stillDt = scaled ? 1.5 * this.frameGap : 0.1;
    if (scaled) for (const tr of this.tracks) { tr.stillNeeded = stillNeeded; tr.stillDt = stillDt; }
    dets.forEach((d, di) => {
      if (usedD.has(di)) return;
      const tr = new Track(this.nextId++, d.x, d.y, t, t);
      if (scaled) { tr.stillNeeded = stillNeeded; tr.stillDt = stillDt; }
      tr.observe(d, t);
      this.tracks.push(tr);
      for (const c of this.pending) { // a ball turning up where a candidate vanished
        const info = this.cand.get(c.track_id);
        if (info && hypot(d.x - info.x, d.y - info.y) < 2.5 * this.r) info.back = true;
      }
    });
  }

  watchCandidates(t, occluder) {
    const dt = this.lastT === null ? 0.0 : Math.min(0.2, t - this.lastT);
    this.lastT = t;
    this.totals.push([t, this.onTable()]);
    while (t - this.totals[0][0] > 30) this.totals.shift();
    const events = [], keep = [];
    for (const c of this.pending) {
      if (!this.cand.has(c.track_id)) this.cand.set(c.track_id, { x: c.x, y: c.y, clear: 0.0, back: false });
      const info = this.cand.get(c.track_id);
      if (info.back) {
        this.log.push(`t=${c.t.toFixed(1)}s ball #${c.track_id} (${c.kind}) came back by the ${c.pocket} pocket: not a pot`);
        continue;
      }
      if (!covers(occluder, info.x, info.y)) info.clear += dt;
      if (info.clear >= this.clearConfirm) {
        if (this.reallyGone(c, t, events.length)) { events.push(c); continue; }
        info.clear = 0.0;
      }
      keep.push(c);
    }
    this.pending = keep;
    return events;
  }

  census(t) {
    const visible = this.tracks.filter((tr) => tr.confirmed && !tr.hidden);
    let moving = visible.some((tr) => tr.misses === 0 && tr.moved(0.5) > 1.0 * this.r);
    moving = moving || visible.some((tr) => tr.misses > 0 && this.nearPocket(tr));
    this.recent.push([t, this.onTable(), false, moving]);
    while (this.recent.length && t - this.recent[0][0] > this.settleTime) this.recent.shift();
    for (const c of this.pending.filter((c) => t - c.t >= this.candidateTtl)) {
      this.log.push(`t=${c.t.toFixed(1)}s ball #${c.track_id} (${c.kind}) at the ${c.pocket} pocket: the table never settled to check it, dropped`);
    }
    this.pending = this.pending.filter((c) => t - c.t < this.candidateTtl);
    if (t - this.recent[0][0] < 0.9 * this.settleTime) return [];
    if (this.recent.some(([, , occ, mov]) => occ || mov)) return [];
    const counts = this.recent.map(([, c]) => c);
    const agree = (c) => counts.filter((o) => c.equals(o)).length;
    let census = counts[0], best = agree(counts[0]);
    for (const c of counts) { const a = agree(c); if (a > best) { census = c; best = a; } }
    if (best < 0.7 * counts.length) return [];
    const prev = this.settled;
    this.settled = census;
    if (prev === null || census.equals(prev)) {
      if (prev === null) this.pending = [];
      return [];
    }
    return this.confirm(prev, census, t);
  }

  duplicate(ev) {
    const objects = ["solid", "stripe", "unknown"];
    for (const c of this.pending) {
      const same = c.kind === ev.kind || (objects.includes(c.kind) && objects.includes(ev.kind)) || Math.min(c.kind_conf, ev.kind_conf) < 0.6;
      if (same && Math.abs(c.t - ev.t) < 1.0 && hypot(c.x - ev.x, c.y - ev.y) < 2 * this.r) {
        if (ev.kind_conf > c.kind_conf) Object.assign(c, { kind: ev.kind, color: ev.color, number: ev.number, kind_conf: ev.kind_conf });
        return true;
      }
    }
    return false;
  }

  totalAround(t0, t1, kind = null) {
    const vals = this.totals.filter(([t]) => t0 <= t && t <= t1).map(([, c]) => (kind ? c.of(kind) : c.total()));
    if (!vals.length) return null;
    const count = new Map();
    for (const v of vals) count.set(v, (count.get(v) || 0) + 1);
    let best = null, bc = -1;
    for (const [v, c] of [...count].sort((a, b) => a[0] - b[0])) if (c > bc) { best = v; bc = c; }
    return best;
  }

  reallyGone(c, t, already) {
    if (c.kind === "cue" || c.kind === "eight") {
      if (this.tracks.some((tr) => tr.kind === c.kind && (t - tr.t < 1.0 || tr.hidden))) return false;
    }
    const before = this.totalAround(c.t - 1.0, c.t - 0.1);
    const after = this.totalAround(t - 1.0, t);
    if (before === null || after === null || after > before - 1 - already) return false;
    if (c.kind_conf < 0.6 && ["solid", "stripe", "unknown"].includes(c.kind)) {
      const fell = ["solid", "stripe"].filter((k) => (this.totalAround(t - 1.0, t, k) || 0) < (this.totalAround(c.t - 1.0, c.t - 0.1, k) || 0));
      if (fell.length === 1 && fell[0] !== c.kind) Object.assign(c, { kind: fell[0], color: null, number: null });
    }
    return true;
  }

  nearPocket(tr) {
    return Math.min(...this.pockets.map(([px, py]) => hypot(tr.x - px, tr.y - py))) <= this.pocketZone;
  }

  finish() {
    for (const tr of this.tracks.filter((tr) => tr.confirmed && tr.misses > 0 && !tr.hidden)) {
      const ev = this.finalize(tr);
      if (ev) this.pending.push(ev);
      this.tracks.splice(this.tracks.indexOf(tr), 1);
    }
    if (this.settled === null || !this.pending.length) return [];
    const t = this.recent.length ? this.recent[this.recent.length - 1][0] : 0.0;
    return this.confirm(this.settled, this.onTable(), t);
  }

  confirm(prev, now, t) {
    let gone = prev.total() - now.total();
    const drops = new Map();
    for (const k of ["cue", "eight", "solid", "stripe"]) if (prev.of(k) > now.of(k)) drops.set(k, prev.of(k) - now.of(k));
    const events = [];
    const cands = this.pending.slice().sort((a, b) => b.kind_conf - a.kind_conf || a.t - b.t);
    for (const c of cands.slice()) {
      if (gone <= 0) break;
      gone -= 1;
      if ((drops.get(c.kind) || 0) > 0) drops.set(c.kind, drops.get(c.kind) - 1);
      events.push(c);
      cands.splice(cands.indexOf(c), 1);
    }
    for (const c of cands) {
      this.log.push(`t=${c.t.toFixed(1)}s ball #${c.track_id} (${c.kind}) vanished at the ${c.pocket} pocket, but the table count didn't drop: not a pot`);
    }
    for (const [k, n] of drops) if (n > 0) this.log.push(`t=${t.toFixed(1)}s the table has ${n} ${k} fewer, but no ball was seen dropping`);
    this.pending = [];
    return events.sort((a, b) => a.t - b.t);
  }

  /** How close the ball got to (px, py), following its velocity for the frames it went unseen. */
  approach(tr, px, py) {
    const d = hypot(tr.x - px, tr.y - py);
    if (this.scale >= 1.0) return d;
    const horizon = this.projectFrames * this.frameGap;
    const speed2 = tr.vx * tr.vx + tr.vy * tr.vy;
    if (speed2 < 1e-6) return d;
    const s = Math.min(horizon, Math.max(0.0, ((px - tr.x) * tr.vx + (py - tr.y) * tr.vy) / speed2));
    return Math.min(d, hypot(tr.x + tr.vx * s - px, tr.y + tr.vy * s - py));
  }

  finalize(tr) {
    const dists = this.pockets.map(([px, py]) => this.approach(tr, px, py));
    const pi = dists.indexOf(Math.min(...dists));
    if (dists[pi] > this.pocketZone) {
      this.log.push(`t=${tr.t.toFixed(1)}s ball #${tr.id} (${tr.kind}) lost mid-table (hidden?), ignored`);
      return null;
    }
    if (!tr.wasMoving(this.r)) {
      this.log.push(`t=${tr.t.toFixed(1)}s ball #${tr.id} (${tr.kind}) vanished at a pocket while still, ignored`);
      return null;
    }
    // a pot rolls in from the table; balls in the basket get jostled but never come from further out
    const [px, py] = this.pockets[pi];
    const recent = tr.history.filter(([t]) => t >= tr.t - 2.0);
    const xs = recent.map((p) => p[1]), ys = recent.map((p) => p[2]);
    const farthest = Math.max(...recent.map(([, x, y]) => hypot(x - px, y - py)));
    const travelled = hypot(Math.max(...xs) - Math.min(...xs), Math.max(...ys) - Math.min(...ys));
    if (farthest < dists[pi] + 1.0 * this.r || travelled < 1.5 * this.r) {
      this.log.push(`t=${tr.t.toFixed(1)}s ball #${tr.id} (${tr.kind}) stirred at the ${POCKET_NAMES[pi]} pocket without rolling in (a ball in the basket?), ignored`);
      return null;
    }
    const votes = tr.votes;
    const n = votes.total();
    const trKind = tr.kind;
    let kind = trKind;
    if ((kind === "cue" || kind === "eight") && n < 10) {
      const rest = new Counter([...votes].filter(([k]) => k === "solid" || k === "stripe"));
      if (rest.size) kind = rest.mostCommon();
    }
    const conf = n ? votes.of(kind) / n : 0.0;
    const same = kind === trKind;
    return {
      t: Math.round(tr.t * 100) / 100, track_id: tr.id, kind, color: same ? tr.color : null,
      number: same ? tr.number : null, pocket: POCKET_NAMES[pi], kind_conf: Math.round(conf * 100) / 100,
      x: tr.x, y: tr.y, born: tr.born,
    };
  }

  /** Balls on the table by kind: the ones in view plus the ones hidden under a player. */
  onTable() {
    const c = new Counter();
    for (const tr of this.tracks) if (tr.confirmed && (tr.misses === 0 || tr.hidden)) c.inc(tr.kind);
    return c;
  }
}
