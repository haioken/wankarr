/**
 * FlipBook — a page-curl book renderer.
 *
 * Pages are laid out as "views": a two-page spread (physical faces L and R)
 * or a single page (face C). Moving between neighbouring views is done by a
 * Sheet: one physical leaf whose front is the page being lifted and whose
 * back is the page it lands as. The leaf is folded along the perpendicular
 * bisector between its grabbed point and the pointer, exactly like paper:
 *
 *   front  – the lifted page, clipped to the part that has not folded yet
 *   under  – the page revealed underneath the lifted one
 *   back   – the reverse of the leaf, reflected across the fold onto the flap
 *   static – the page beneath where the leaf lands
 *
 * All geometry is computed in a normalised frame where the spine is x = 0,
 * the lifted page spans x ∈ [0, W] and the grabbed point is on the far edge
 * at the bottom; toReal()/toNorm() mirror that frame for left-side leaves
 * and top-corner grabs, so one set of maths covers every case.
 */

const WIDE_RATIO = 1.05;
const DEFAULT_ASPECT = 1.42; // height / width of a typical manga page

export const isWide = (p) => p.w > 0 && p.h > 0 && p.w / p.h > WIDE_RATIO;

/**
 * Arrange pages into views.
 * Spreads honour reading direction, keep wide images across both faces and
 * pad with blanks so they never straddle two spreads.
 */
export function buildViews(pages, { spread, rtl, coverAlone }) {
  if (!spread) return pages.map((_, i) => ({ C: { kind: 'page', page: i }, pages: [i] }));

  const slots = [];
  if (coverAlone && pages.length > 1 && !isWide(pages[0])) slots.push({ kind: 'none' });
  pages.forEach((p, i) => {
    if (isWide(p)) {
      if (slots.length % 2) slots.push({ kind: slots.length === 1 ? 'none' : 'blank' });
      slots.push({ kind: 'half', page: i }, { kind: 'half', page: i });
    } else {
      slots.push({ kind: 'page', page: i });
    }
  });
  if (slots.length % 2) slots.push({ kind: 'none' });

  const views = [];
  for (let k = 0; k < slots.length; k += 2) {
    const first = slots[k], second = slots[k + 1]; // reading order
    const view = rtl ? { R: first, L: second } : { L: first, R: second };
    view.pages = [...new Set([first, second].filter((s) => s.page !== undefined).map((s) => s.page))];
    views.push(view);
  }
  return views;
}

// --- small geometry helpers ------------------------------------------------

const dot = (a, b) => a.x * b.x + a.y * b.y;

/** Clip a convex polygon to the half-plane sign·((p − m)·n) ≥ 0. */
function clipHalf(poly, m, n, sign) {
  const out = [];
  for (let i = 0; i < poly.length; i++) {
    const a = poly[i], b = poly[(i + 1) % poly.length];
    const da = sign * ((a.x - m.x) * n.x + (a.y - m.y) * n.y);
    const db = sign * ((b.x - m.x) * n.x + (b.y - m.y) * n.y);
    if (da >= 0) out.push(a);
    if ((da >= 0) !== (db >= 0)) {
      const t = da / (da - db);
      out.push({ x: a.x + (b.x - a.x) * t, y: a.y + (b.y - a.y) * t });
    }
  }
  return out;
}

function reflect(p, m, n) {
  const k = 2 * ((p.x - m.x) * n.x + (p.y - m.y) * n.y);
  return { x: p.x - k * n.x, y: p.y - k * n.y };
}

const polygon = (pts) => (pts.length < 3 ? 'polygon(0 0, 0 0, 0 0)' : `polygon(${pts.map((p) => `${p.x.toFixed(2)}px ${p.y.toFixed(2)}px`).join(',')})`);

const easeInOut = (t) => (t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2);
const easeOut = (t) => 1 - Math.pow(1 - t, 3);

function el(cls, parent) {
  const e = document.createElement('div');
  e.className = cls;
  if (parent) parent.append(e);
  return e;
}

/** Position a gradient strip whose left edge lies on a line and fades along dir. */
function placeStrip(strip, p, dir, width, length) {
  const t = { x: -dir.y, y: dir.x };
  strip.style.width = `${Math.max(0, width)}px`;
  strip.style.height = `${length}px`;
  strip.style.transform = `matrix(${dir.x},${dir.y},${t.x},${t.y},${p.x - (t.x * length) / 2},${p.y - (t.y * length) / 2})`;
}

// --- Sheet: one leaf being turned --------------------------------------------

class Sheet {
  constructor(book, from) {
    this.book = book;
    this.from = from;
    const { W, H, spread } = book;
    this.W = W;
    this.H = H;
    this.side = book.forwardSide;               // physical side the leaf lifts from
    this.S = spread ? W : this.side === 'R' ? 0 : W; // spine x in book coordinates
    const A = book.views[from], B = book.views[from + 1];
    const lifted = this.side, landing = this.side === 'R' ? 'L' : 'R';
    this.frontLeft = this.side === 'R' ? this.S : this.S - W;
    this.backLeft = this.side === 'R' ? this.S - W : this.S;

    let front, back, under, stat = null;
    if (spread) {
      front = book.face(A[lifted], lifted);
      back = book.face(B[landing], landing);
      under = book.face(B[lifted], lifted);
      stat = book.face(A[landing], landing);
    } else {
      front = book.face(A.C, 'C');
      back = book.face({ kind: 'back', page: A.C.page }, 'C');
      under = book.face(B.C, 'C');
    }
    this.underIsNone = (spread ? B[lifted] : B.C).kind === 'none';

    const root = (this.root = el('wk-sheet', book.bookEl));
    if (stat) this.slot(root, this.backLeft).append(stat);
    this.slot(root, this.frontLeft).append(under);

    this.shadeUnder = this.slot(root, this.frontLeft, 'wk-shade');
    this.shadeUnderStrip = el('wk-strip wk-strip-under', this.shadeUnder);

    this.front = this.slot(root, this.frontLeft, 'wk-front');
    this.front.append(front);
    this.frontStrip = el('wk-strip wk-strip-front', this.front);

    this.backWrap = el('wk-backwrap', root);
    this.back = this.slot(this.backWrap, 0, 'wk-back');
    this.back.append(back);
    this.backStrip = el('wk-strip wk-strip-back', this.back);

    this.setGrab(H, true);
  }

  slot(parent, left, extra = '') {
    const s = el(`wk-slot ${extra}`, parent);
    s.style.left = `${left}px`;
    return s;
  }

  /** Choose the grabbed point on the outer edge from a book-relative y. */
  setGrab(y, corner = false) {
    const H = this.H;
    if (corner || y > H * 0.7) {
      this.top = false;
      this.C = { x: this.W, y: H };
    } else if (y < H * 0.3) {
      this.top = true;
      this.C = { x: this.W, y: H };
    } else {
      this.top = false;
      this.C = { x: this.W, y: Math.max(0, Math.min(H, y)) };
    }
    this.L = { x: -this.W, y: this.C.y }; // where C ends up once the leaf has landed
  }

  toReal(p) {
    return { x: this.side === 'R' ? this.S + p.x : this.S - p.x, y: this.top ? this.H - p.y : p.y };
  }
  toNorm(r) {
    return { x: this.side === 'R' ? r.x - this.S : this.S - r.x, y: this.top ? this.H - r.y : r.y };
  }
  /** Map a direction vector from the normalised frame to book coordinates. */
  dirReal(v) {
    return { x: this.side === 'R' ? v.x : -v.x, y: this.top ? -v.y : v.y };
  }
  dirNorm(v) {
    return this.dirReal(v); // the mapping is its own inverse
  }

  /** Keep the grabbed point where real paper could reach without tearing at the spine. */
  clamp(T) {
    let p = { x: Math.min(T.x, this.W), y: T.y };
    const anchors = [{ x: 0, y: 0 }, { x: 0, y: this.H }];
    for (let i = 0; i < 3; i++) {
      for (const c of anchors) {
        const r = Math.hypot(this.C.x - c.x, this.C.y - c.y);
        const dx = p.x - c.x, dy = p.y - c.y, d = Math.hypot(dx, dy);
        if (d > r) p = { x: c.x + (dx * r) / d, y: c.y + (dy * r) / d };
      }
    }
    return p;
  }

  /** 0 = leaf flat on its original side, 1 = fully landed. */
  progress() {
    return (this.C.x - this.T.x) / (2 * this.W);
  }

  render(target) {
    const { W, H, C } = this;
    const T = (this.T = this.clamp(target));
    const d = { x: T.x - C.x, y: T.y - C.y };
    const len = Math.hypot(d.x, d.y);
    const frontL = (p) => { const r = this.toReal(p); return { x: r.x - this.frontLeft, y: r.y }; };
    const backL = (p) => { const r = this.toReal(p); return { x: r.x - this.backLeft, y: r.y }; };

    if (len < 0.5) {
      this.front.style.clipPath = 'none';
      this.backWrap.style.visibility = 'hidden';
      this.shadeUnder.style.visibility = 'hidden';
      this.frontStrip.style.width = '0px';
      return;
    }
    this.backWrap.style.visibility = '';
    this.shadeUnder.style.visibility = this.underIsNone ? 'hidden' : '';

    const n = { x: d.x / len, y: d.y / len };
    const M = { x: (C.x + T.x) / 2, y: (C.y + T.y) / 2 };
    const rect = [{ x: 0, y: 0 }, { x: W, y: 0 }, { x: W, y: H }, { x: 0, y: H }];
    const frontPoly = clipHalf(rect, M, n, 1);
    const flapPoly = clipHalf(rect, M, n, -1);

    // Front: what is left of the lifted page.
    this.front.style.clipPath = polygon(frontPoly.map(frontL));

    // Back: the leaf's reverse, reflected across the fold. Its resting
    // position is the landing page; composing "mirror across spine" with
    // "reflect across fold" is a rotation, so the content is never mirrored.
    const chain = (b) => {
      const Y = this.toNorm({ x: this.backLeft + b.x, y: b.y });
      return this.toReal(reflect({ x: -Y.x, y: Y.y }, M, n));
    };
    const q0 = chain({ x: 0, y: 0 }), q1 = chain({ x: W, y: 0 }), q2 = chain({ x: 0, y: H });
    this.back.style.left = '0px';
    this.back.style.transform = `matrix(${(q1.x - q0.x) / W},${(q1.y - q0.y) / W},${(q2.x - q0.x) / H},${(q2.y - q0.y) / H},${q0.x},${q0.y})`;
    this.back.style.clipPath = polygon(flapPoly.map((X) => backL({ x: -X.x, y: X.y })));

    // Shading. Strength rises as the fold opens, fades as the leaf lands.
    const p = Math.max(0, Math.min(1, this.progress()));
    const strength = Math.sin(Math.PI * Math.min(1, p * 1.15));
    const L = 3 * (W + H);
    const foldFront = frontL(M);
    const toC = this.dirReal({ x: -n.x, y: -n.y });
    const awayC = this.dirReal(n);

    // Shadow the leaf casts on the revealed page, darkest at the fold.
    this.shadeUnder.style.clipPath = polygon(flapPoly.map(frontL));
    placeStrip(this.shadeUnderStrip, foldFront, toC, Math.min(len * 0.35, W * 0.4), L);
    this.shadeUnderStrip.style.opacity = strength;

    // The front page bowing up towards the fold.
    placeStrip(this.frontStrip, foldFront, awayC, Math.min(len * 0.12, W * 0.12), L);
    this.frontStrip.style.opacity = strength;

    // Curvature highlight on the flap.
    const foldBack = backL({ x: -M.x, y: M.y });
    const intoFlap = this.dirReal({ x: n.x, y: -n.y });
    placeStrip(this.backStrip, foldBack, intoFlap, Math.min(len * 0.5, W * 0.7), L);
    this.backStrip.style.opacity = strength;
  }

  destroy() {
    this.root.remove();
  }
}

// --- FlipBook ----------------------------------------------------------------

export class FlipBook {
  constructor(stage, { onChange = () => {}, onEdge = () => {}, onTap = () => {} } = {}) {
    this.stage = stage;
    this.onChange = onChange;
    this.onEdge = onEdge;
    this.onTap = onTap;
    this.pages = [];
    this.views = [];
    this.index = 0;
    this.options = { direction: 'rtl', layout: 'auto', coverAlone: true };
    this.faces = new Map();
    this.active = null; // { sheet, raf?, finish? }
    this.reducedMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;

    this.bookEl = el('wk-book', stage);
    this.viewEl = el('wk-view', this.bookEl);

    stage.addEventListener('pointerdown', (e) => this.pointerDown(e));
    stage.addEventListener('pointermove', (e) => this.pointerMove(e));
    stage.addEventListener('pointerup', (e) => this.pointerUp(e, false));
    stage.addEventListener('pointercancel', (e) => this.pointerUp(e, true));
    stage.addEventListener('contextmenu', (e) => e.preventDefault());

    let t;
    this.resizeObserver = new ResizeObserver(() => {
      clearTimeout(t);
      t = setTimeout(() => this.relayout(), 60);
    });
    this.resizeObserver.observe(stage);
  }

  get rtl() { return this.options.direction === 'rtl'; }
  /** The physical side a page is lifted from to move forward in reading order. */
  get forwardSide() { return this.rtl ? 'L' : 'R'; }
  get currentView() { return this.views[this.index]; }

  setPages(pages, startPage = 0) {
    this.pages = pages;
    this.faces.clear();
    this.viewEl.replaceChildren();
    this.relayout(startPage);
  }

  configure(options) {
    const page = this.currentView?.pages[0] ?? 0;
    this.options = { ...this.options, ...options };
    this.faces.clear();
    this.relayout(page, true);
  }

  // --- layout ---------------------------------------------------------------

  pageAspect() {
    const ratios = this.pages.filter((p) => p.w > 0 && p.h > 0 && !isWide(p)).map((p) => p.h / p.w).sort((a, b) => a - b);
    return ratios.length ? ratios[Math.floor(ratios.length / 2)] : DEFAULT_ASPECT;
  }

  relayout(page = this.currentView?.pages[0] ?? 0, force = false) {
    if (!this.pages.length) return;
    this.abort();
    const pad = 8;
    const aw = Math.max(100, this.stage.clientWidth - pad * 2);
    const ah = Math.max(100, this.stage.clientHeight - pad * 2);
    const a = this.pageAspect();
    const spreadW = Math.min(aw / 2, ah / a);
    const singleW = Math.min(aw, ah / a);
    const layout = this.options.layout;
    const spread = layout === 'double' || (layout === 'auto' && spreadW >= singleW * 0.8);
    const W = Math.floor(spread ? spreadW : singleW);
    const H = Math.floor(W * a);

    const changed = force || spread !== this.spread || this.views.length === 0;
    this.spread = spread;
    this.W = W;
    this.H = H;
    this.bookEl.style.width = `${spread ? W * 2 : W}px`;
    this.bookEl.style.height = `${H}px`;
    this.bookEl.style.setProperty('--W', `${W}px`);
    this.bookEl.style.setProperty('--H', `${H}px`);
    this.bookEl.classList.toggle('is-spread', spread);
    this.bookEl.classList.toggle('is-single', !spread);
    this.bookEl.classList.toggle('is-rtl', this.rtl);

    if (changed) {
      this.views = buildViews(this.pages, { spread, rtl: this.rtl, coverAlone: this.options.coverAlone });
      this.index = this.viewForPage(page);
    }
    this.renderStatic();
    this.emit();
  }

  viewForPage(page) {
    const i = this.views.findIndex((v) => v.pages.includes(page));
    if (i >= 0) return i;
    let best = 0;
    this.views.forEach((v, k) => { if (v.pages.length && v.pages[0] <= page) best = k; });
    return best;
  }

  // --- faces ----------------------------------------------------------------

  /** A rendered page face, cached so the decoded image survives view changes. */
  face(spec, side) {
    const key = `${spec.kind}:${spec.page ?? ''}:${side}`;
    let f = this.faces.get(key);
    if (f) {
      this.faces.delete(key);
      this.faces.set(key, f);
      return f;
    }
    f = el(`wk-face wk-face-${spec.kind} side-${side}`);
    if (spec.page !== undefined) {
      const img = new Image();
      img.decoding = 'async';
      img.draggable = false;
      img.alt = '';
      img.src = this.pages[spec.page].src;
      if (spec.kind === 'half') el('wk-half', f).append(img);
      else f.append(img);
    }
    this.faces.set(key, f);
    if (this.faces.size > 48) {
      for (const [k, v] of this.faces) {
        if (this.faces.size <= 40) break;
        if (!v.isConnected) this.faces.delete(k);
      }
    }
    return f;
  }

  facesOf(view) {
    return this.spread ? [[view.L, 'L'], [view.R, 'R']] : [[view.C, 'C']];
  }

  renderStatic() {
    const view = this.currentView;
    const slots = this.facesOf(view).map(([spec, side]) => {
      const s = el('wk-slot');
      s.style.left = `${side === 'R' ? this.W : 0}px`;
      s.append(this.face(spec, side));
      return s;
    });
    this.viewEl.replaceChildren(...slots);
    this.viewEl.hidden = false;
    // Warm neighbours so a turn never waits on image decode.
    for (let k = this.index - 2; k <= this.index + 3; k++) {
      const v = this.views[k];
      if (v && k !== this.index) for (const [spec, side] of this.facesOf(v)) this.face(spec, side);
    }
  }

  emit() {
    const v = this.currentView;
    if (v) this.onChange({ view: this.index, views: this.views.length, pages: v.pages, spread: this.spread });
  }

  // --- turning --------------------------------------------------------------

  canTurn(forward) {
    return forward ? this.index < this.views.length - 1 : this.index > 0;
  }

  beginSheet(forward) {
    const sheet = new Sheet(this, forward ? this.index : this.index - 1);
    this.viewEl.hidden = true;
    return sheet;
  }

  commit(sheet, landed) {
    this.index = sheet.from + (landed ? 1 : 0);
    this.renderStatic();
    sheet.destroy();
    this.active = null;
    this.emit();
  }

  animate(sheet, from, to, duration, lift, ease, landed) {
    const t0 = performance.now();
    const finish = () => {
      cancelAnimationFrame(this.active?.raf);
      this.commit(sheet, landed);
    };
    this.active = { sheet, finish };
    if (duration <= 0) return finish();
    const step = (now) => {
      const t = Math.min(1, (now - t0) / duration);
      const e = ease(t);
      sheet.render({
        x: from.x + (to.x - from.x) * e,
        y: from.y + (to.y - from.y) * e - Math.sin(Math.PI * e) * lift,
      });
      if (t < 1) this.active.raf = requestAnimationFrame(step);
      else finish();
    };
    this.active.raf = requestAnimationFrame(step);
  }

  /** Complete any running animation immediately. */
  finishNow() {
    this.active?.finish?.();
  }

  /** Drop any in-flight turn, returning to a clean static view. */
  abort() {
    if (!this.active) return;
    cancelAnimationFrame(this.active.raf);
    this.active.sheet.destroy();
    this.active = null;
    this.drag = null;
    this.viewEl.hidden = false;
  }

  /** Turn the page on a physical side ('L' or 'R'). */
  flip(side) {
    if (this.drag) return;
    this.finishNow();
    const forward = side === this.forwardSide;
    if (!this.canTurn(forward)) return this.onEdge(forward ? 'end' : 'start');
    const sheet = this.beginSheet(forward);
    const [from, to] = forward ? [sheet.C, sheet.L] : [sheet.L, sheet.C];
    sheet.render(from);
    this.animate(sheet, from, to, this.reducedMotion ? 0 : 620, this.H * 0.12, easeInOut, forward);
  }

  next() { this.flip(this.forwardSide); }
  prev() { this.flip(this.forwardSide === 'R' ? 'L' : 'R'); }

  goToView(i) {
    this.abort();
    this.index = Math.max(0, Math.min(this.views.length - 1, i));
    this.renderStatic();
    this.emit();
  }

  goToPage(page) {
    this.goToView(this.viewForPage(page));
  }

  // --- pointer input ----------------------------------------------------------

  pointerDown(e) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    if (e.target.closest('button, a, input, [data-no-flip]')) return;
    if (this.down || this.drag) return;
    this.finishNow();
    this.down = { id: e.pointerId, x: e.clientX, y: e.clientY, t: performance.now(), moved: false };
    try { this.stage.setPointerCapture(e.pointerId); } catch { /* pointer already gone */ }
  }

  pointerMove(e) {
    const d = this.down;
    if (!d || e.pointerId !== d.id) return;
    if (!d.moved) {
      const dx = e.clientX - d.x, dy = e.clientY - d.y;
      if (Math.hypot(dx, dy) < 8) return;
      d.moved = true;
      if (Math.abs(dy) > Math.abs(dx) * 1.4) return; // vertical gesture: not a page turn
      this.startDrag(d, dx < 0 ? 'R' : 'L');
    }
    const g = this.drag;
    if (!g) return;
    e.preventDefault();
    const p = this.bookPoint(e);
    const delta = g.sheet.dirNorm({ x: p.x - g.p0.x, y: p.y - g.p0.y });
    g.target = { x: g.T0.x + delta.x * g.k, y: g.T0.y + delta.y };
    const now = performance.now();
    g.samples.push({ t: now, x: g.target.x });
    while (g.samples.length > 2 && now - g.samples[0].t > 90) g.samples.shift();
    if (!g.raf) g.raf = requestAnimationFrame(() => { g.raf = 0; g.sheet.render(g.target); });
  }

  pointerUp(e, cancelled) {
    const d = this.down;
    if (!d || e.pointerId !== d.id) return;
    this.down = null;
    const g = this.drag;
    if (g) {
      this.drag = null;
      cancelAnimationFrame(g.raf);
      g.sheet.render(g.target);
      const s = g.samples;
      const v = s.length > 1 ? (s[s.length - 1].x - s[0].x) / Math.max(1, s[s.length - 1].t - s[0].t) : 0;
      const p = g.sheet.progress();
      let land = cancelled ? !g.forward : v < -0.35 ? true : v > 0.35 ? false : p > 0.5;
      const sheet = g.sheet;
      const to = land ? sheet.L : sheet.C;
      const dist = Math.abs(to.x - sheet.T.x) / (2 * this.W);
      this.animate(sheet, sheet.T, to, this.reducedMotion ? 0 : 140 + 380 * dist, 0, easeOut, land);
      return;
    }
    if (!d.moved && !cancelled && performance.now() - d.t < 600) {
      const r = this.stage.getBoundingClientRect();
      const fx = (e.clientX - r.left) / r.width;
      if (fx < 0.3) this.flip('L');
      else if (fx > 0.7) this.flip('R');
      else this.onTap('center');
    }
  }

  bookPoint(e) {
    const r = this.bookEl.getBoundingClientRect();
    return { x: e.clientX - r.left, y: e.clientY - r.top };
  }

  /** Lift a page from `side`, following the pointer from here on. */
  startDrag(d, side) {
    const forward = side === this.forwardSide;
    if (!this.canTurn(forward)) {
      this.onEdge(forward ? 'end' : 'start');
      return;
    }
    const sheet = this.beginSheet(forward);
    const r = this.bookEl.getBoundingClientRect();
    const p0 = { x: d.x - r.left, y: d.y - r.top };
    sheet.setGrab(p0.y);
    const T0 = forward ? sheet.C : sheet.L;
    sheet.render(T0);
    this.active = { sheet };
    this.drag = {
      sheet, forward, p0, T0, target: T0, raf: 0, samples: [],
      k: this.spread ? 1 : 1.6, // single pages need less travel per turn
    };
  }

  destroy() {
    this.abort();
    this.resizeObserver.disconnect();
    this.bookEl.remove();
  }
}
