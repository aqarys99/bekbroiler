/* Просмотр PDF на сайте: «Читалка» (PDF.js) и «Книга» (PDF.js + StPageFlip). Библиотеки лежат у нас в /assets/vendor. */
(function () {
  'use strict';

  var app = document.querySelector('[data-pdf-viewer]');
  if (!app) { return; }
  var nojs = document.querySelector('[data-dv-nojs]');
  if (nojs) { nojs.hidden = true; }
  app.hidden = false;

  function $(s) { return app.querySelector(s); }
  function $$(s) { return Array.prototype.slice.call(app.querySelectorAll(s)); }
  var I18N = {};
  try { I18N = JSON.parse(app.getAttribute('data-i18n') || '{}'); } catch (e) { I18N = {}; }
  function fmt(tpl, map) {
    return String(tpl).replace(/:([a-z]+)/g, function (m, k) { return map && map[k] !== undefined ? map[k] : m; });
  }

  var SRC = app.getAttribute('data-src');
  var VENDOR = app.getAttribute('data-pdfjs');
  var FLIP_SRC = app.getAttribute('data-pageflip');
  var STORE_KEY = 'docview-mode';
  var MOBILE = window.matchMedia ? window.matchMedia('(max-width: 767px)') : { matches: false };

  var elLoading = $('[data-dv-loading]');
  var elError = $('[data-dv-error]');
  var paneReader = $('[data-pane="reader"]');
  var paneBook = $('[data-pane="book"]');
  var scrollEl = $('[data-scroll]');
  var pagesEl = $('[data-pages]');
  var thumbsEl = $('[data-thumbs]');
  var pageInput = $('[data-pageinput]');
  var pageTotal = $('[data-pagetotal]');
  var zoomLabel = $('[data-zoomlabel]');
  var searchForm = $('[data-searchform]');
  var searchInput = $('[data-searchinput]');
  var searchCount = $('[data-searchcount]');
  var bookStage = $('[data-bookstage]');
  var bookPageLabel = $('[data-bookpage]');

  var lib = null;       // pdfjs
  var pdf = null;       // документ
  var N = 0;
  var base = { w: 595, h: 842 };  // размер первой страницы при масштабе 1
  var mode = null;
  var cur = 1;          // текущая страница (1..N), общая для обоих режимов

  // ------------------------------------------------------------------ служебное
  function getStored() { try { var v = window.localStorage.getItem(STORE_KEY); return v === 'reader' || v === 'book' ? v : null; } catch (e) { return null; } }
  function setStored(v) { try { window.localStorage.setItem(STORE_KEY, v); } catch (e) { /* хранилище недоступно */ } }
  function paramMode() { var m = /[?&]mode=(reader|book)(?:&|#|$)/.exec(window.location.search); return m ? m[1] : null; }
  function setUrlMode(v) {
    try {
      var u = new URL(window.location.href);
      u.searchParams.set('mode', v);
      window.history.replaceState(null, '', u.pathname + u.search + u.hash);
    } catch (e) { /* не критично */ }
  }
  function debounce(fn, ms) { var t = null; return function () { var a = arguments; clearTimeout(t); t = setTimeout(function () { fn.apply(null, a); }, ms); }; }
  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }

  function showError() {
    elLoading.hidden = true;
    paneReader.hidden = true;
    paneBook.hidden = true;
    elError.hidden = false;
  }

  // ------------------------------------------------------------------ загрузка PDF.js и документа
  function load() {
    return import(VENDOR + 'pdf.min.mjs').then(function (m) {
      lib = m;
      lib.GlobalWorkerOptions.workerSrc = VENDOR + 'pdf.worker.min.mjs';
      var task = lib.getDocument({
        url: SRC,
        cMapUrl: VENDOR + 'cmaps/',
        cMapPacked: true,
        standardFontDataUrl: VENDOR + 'standard_fonts/',
        wasmUrl: VENDOR + 'wasm/',
        iccUrl: VENDOR + 'iccs/',
        isEvalSupported: false
      });
      task.onProgress = function (d) {
        if (d && d.total) {
          var pct = Math.round(clamp(d.loaded / d.total, 0, 1) * 100);
          var bar = $('[data-dv-progress]');
          if (bar) { bar.style.width = pct + '%'; }
          var t = $('[data-dv-loading-text]');
          if (t && I18N.loading_pct) { t.textContent = fmt(I18N.loading_pct, { n: pct }); }
        }
      };
      return task.promise;
    }).then(function (doc) {
      pdf = doc;
      N = doc.numPages;
      return doc.getPage(1);
    }).then(function (p1) {
      var vp = p1.getViewport({ scale: 1 });
      base = { w: vp.width, h: vp.height };
    });
  }

  // ------------------------------------------------------------------ режимы
  function setMode(m, opts) {
    opts = opts || {};
    var prev = mode;
    mode = m;
    $$('[data-mode-btn]').forEach(function (b) { b.setAttribute('aria-pressed', b.getAttribute('data-mode-btn') === m ? 'true' : 'false'); });
    if (opts.persist) { setStored(m); setUrlMode(m); }
    if (prev === 'book' && m !== 'book') { destroyBook(); }
    paneReader.hidden = m !== 'reader';
    paneBook.hidden = m !== 'book';
    if (m === 'reader') {
      buildReader();
      window.requestAnimationFrame(function () {
        if (R.fit) { applyFit(true); }
        goTo(cur, true);
      });
    } else {
      buildBook().catch(function () { setMode('reader', {}); });
    }
  }

  // ------------------------------------------------------------------ читалка
  var R = { built: false, scale: 1, fit: true, els: [], thumbs: [], sizes: {}, gen: 0, queue: [], active: 0, io: null, tio: null, texts: {}, lock: 0, rendered: [] };

  function pageDims(n) { var s = R.sizes[n] || base; return { w: s.w, h: s.h }; }
  function layoutPage(n) {
    var d = pageDims(n);
    R.els[n].style.width = Math.floor(d.w * R.scale) + 'px';
    R.els[n].style.height = Math.floor(d.h * R.scale) + 'px';
  }

  function buildReader() {
    if (R.built) { return; }
    R.built = true;
    pageTotal.textContent = fmt(I18N.of || ':n', { n: N });
    pageInput.max = String(N);
    var thumbW = 90;
    for (var n = 1; n <= N; n++) {
      var d = document.createElement('div');
      d.className = 'dv-page';
      d.setAttribute('data-n', String(n));
      d.setAttribute('data-testid', 'docview-page');
      R.els[n] = d;
      pagesEl.appendChild(d);

      var b = document.createElement('button');
      b.type = 'button';
      b.className = 'dv-thumb';
      b.setAttribute('data-n', String(n));
      b.setAttribute('data-testid', 'docview-thumb');
      b.setAttribute('aria-label', I18N.page_of ? fmt(I18N.page_of, { a: n, n: N }) : String(n));
      var ph = document.createElement('span');
      ph.className = 'dv-thumb__ph';
      ph.style.width = thumbW + 'px';
      ph.style.height = Math.round(thumbW * base.h / base.w) + 'px';
      var lab = document.createElement('span');
      lab.textContent = String(n);
      b.appendChild(ph);
      b.appendChild(lab);
      R.thumbs[n] = b;
      thumbsEl.appendChild(b);
    }
    thumbsEl.addEventListener('click', function (ev) {
      var t = ev.target.closest ? ev.target.closest('.dv-thumb') : null;
      if (t) { goTo(parseInt(t.getAttribute('data-n'), 10)); }
    });
    if (MOBILE.matches) { setThumbs(false); }

    R.scale = fitScale();
    for (var i = 1; i <= N; i++) { layoutPage(i); }
    updateZoomLabel();

    if ('IntersectionObserver' in window) {
      R.io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) { if (en.isIntersecting) { enqueue(parseInt(en.target.getAttribute('data-n'), 10)); } });
      }, { root: scrollEl, rootMargin: '300px 0px' });
      R.tio = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) { if (en.isIntersecting) { renderThumb(parseInt(en.target.getAttribute('data-n'), 10)); R.tio.unobserve(en.target); } });
      }, { root: thumbsEl, rootMargin: '200px 0px' });
      observeAll();
      for (var k = 1; k <= N; k++) { R.tio.observe(R.thumbs[k]); }
    } else {
      for (var j = 1; j <= Math.min(N, 5); j++) { enqueue(j); }
    }

    var ticking = false;
    scrollEl.addEventListener('scroll', function () {
      if (ticking) { return; }
      ticking = true;
      window.requestAnimationFrame(function () { ticking = false; syncCurrent(); });
    }, { passive: true });

    pageInput.addEventListener('change', function () {
      var v = parseInt(pageInput.value, 10);
      if (isNaN(v)) { pageInput.value = String(cur); return; }
      goTo(clamp(v, 1, N));
    });
    pageInput.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); pageInput.blur(); pageInput.dispatchEvent(new Event('change')); } });
  }

  function observeAll() {
    if (!R.io) { return; }
    R.io.disconnect();
    for (var n = 1; n <= N; n++) { R.io.observe(R.els[n]); }
  }

  function fitScale() {
    var w = scrollEl.clientWidth - 28;
    if (w < 100) { w = (app.clientWidth || 360) - 28 - (thumbsEl.classList.contains('is-off') ? 0 : 110); }
    return clamp(w / base.w, 0.3, 3);
  }
  function applyFit(force) {
    var s = fitScale();
    if (force || Math.abs(s - R.scale) > 0.005) { setScale(s, true); }
  }
  function setScale(s, fit) {
    s = clamp(s, 0.3, 4);
    R.fit = !!fit;
    if (Math.abs(s - R.scale) < 0.001 && R.built) { updateZoomLabel(); return; }
    var keep = cur;
    R.scale = s;
    R.gen++;
    R.queue = [];
    R.rendered.slice().forEach(function (n) { releasePage(n); });
    for (var n = 1; n <= N; n++) { layoutPage(n); }
    updateZoomLabel();
    observeAll();
    goTo(keep, true);
  }
  function updateZoomLabel() { zoomLabel.textContent = Math.round(R.scale * 100) + '%'; }

  function enqueue(n) {
    var el = R.els[n];
    if (!el || el._state) { return el ? el._ready : null; }
    el._state = 'queued';
    el.classList.add('is-busy');
    var gen = R.gen;
    el._ready = new Promise(function (resolve) { el._resolve = resolve; });
    R.queue.push({ n: n, gen: gen });
    pump();
    return el._ready;
  }
  function pump() {
    while (R.active < 2 && R.queue.length) {
      var job = R.queue.shift();
      if (job.gen !== R.gen) { continue; }
      R.active++;
      renderPage(job.n, job.gen).then(function () { R.active--; pump(); }, function () { R.active--; pump(); });
    }
  }

  function renderPage(n, gen) {
    var el = R.els[n];
    return pdf.getPage(n).then(function (page) {
      if (gen !== R.gen) { return; }
      var sc = R.scale;
      var vp = page.getViewport({ scale: 1 });
      R.sizes[n] = { w: vp.width, h: vp.height };
      layoutPage(n);
      var dpr = clamp(window.devicePixelRatio || 1, 1, 2);
      var vpr = page.getViewport({ scale: sc * dpr });
      var cv = document.createElement('canvas');
      cv.width = Math.floor(vpr.width);
      cv.height = Math.floor(vpr.height);
      var task = page.render({ canvas: cv, canvasContext: cv.getContext('2d'), viewport: vpr, background: '#ffffff' });
      el._task = task;
      el._state = 'rendering';
      return task.promise.then(function () {
        if (gen !== R.gen) { return; }
        el._task = null;
        el.appendChild(cv);
        el.classList.remove('is-busy');
        el._state = 'done';
        var tl = document.createElement('div');
        tl.className = 'dv-text';
        tl.style.setProperty('--scale-factor', String(sc));
        el.appendChild(tl);
        R.rendered.push(n);
        evict();
        var layer = new lib.TextLayer({ textContentSource: page.streamTextContent(), container: tl, viewport: page.getViewport({ scale: sc }) });
        return layer.render().then(function () {
          if (gen === R.gen) { applyHighlights(n); }
        }, function () { /* текст не критичен */ });
      }).then(function () { if (el._resolve) { el._resolve(); } }, function (err) {
        if (err && err.name === 'RenderingCancelledException') { return; }
        el.classList.remove('is-busy');
        el._state = 'error';
        if (el._resolve) { el._resolve(); }
      });
    }, function () { el.classList.remove('is-busy'); el._state = 'error'; if (el._resolve) { el._resolve(); } });
  }

  function releasePage(n) {
    var el = R.els[n];
    if (!el) { return; }
    if (el._task) { try { el._task.cancel(); } catch (e) { /* уже завершена */ } el._task = null; }
    while (el.firstChild) { el.removeChild(el.firstChild); }
    el.classList.remove('is-busy');
    el._state = null;
    el._ready = null;
    el._resolve = null;
    var i = R.rendered.indexOf(n);
    if (i >= 0) { R.rendered.splice(i, 1); }
  }
  function evict() {
    while (R.rendered.length > 14) {
      var far = R.rendered[0];
      R.rendered.forEach(function (n) { if (Math.abs(n - cur) > Math.abs(far - cur)) { far = n; } });
      if (Math.abs(far - cur) <= 3) { break; }
      releasePage(far);
    }
  }

  function renderThumb(n) {
    var b = R.thumbs[n];
    pdf.getPage(n).then(function (page) {
      var w = 90;
      var vp0 = page.getViewport({ scale: 1 });
      var s = w / vp0.width;
      var vp = page.getViewport({ scale: s * clamp(window.devicePixelRatio || 1, 1, 2) });
      var cv = document.createElement('canvas');
      cv.width = Math.floor(vp.width);
      cv.height = Math.floor(vp.height);
      cv.style.width = w + 'px';
      cv.style.height = Math.round(w * vp0.height / vp0.width) + 'px';
      return page.render({ canvas: cv, canvasContext: cv.getContext('2d'), viewport: vp, background: '#ffffff' }).promise.then(function () {
        var ph = b.querySelector('.dv-thumb__ph');
        if (ph) { b.replaceChild(cv, ph); }
      });
    }).catch(function () { /* миниатюра не критична */ });
  }

  function setThumbs(on) {
    thumbsEl.classList.toggle('is-off', !on);
    var btn = $('[data-act="thumbs"]');
    if (btn) { btn.setAttribute('aria-pressed', on ? 'true' : 'false'); }
    if (R.fit && R.built) { window.requestAnimationFrame(function () { applyFit(false); }); }
  }

  function syncCurrent() {
    if (!R.built || mode !== 'reader' || Date.now() < R.lock) { return; }
    var line = scrollEl.getBoundingClientRect().top + Math.min(90, scrollEl.clientHeight * 0.3);
    var n = clamp(cur, 1, N);
    while (n < N && R.els[n + 1].getBoundingClientRect().top <= line) { n++; }
    while (n > 1 && R.els[n].getBoundingClientRect().top > line) { n--; }
    if (n !== cur) { cur = n; showCurrent(); }
  }
  function showCurrent() {
    pageInput.value = String(cur);
    for (var n = Math.max(1, cur - 2); n <= Math.min(N, cur + 2); n++) {
      if (R.thumbs[n]) { R.thumbs[n].removeAttribute('aria-current'); }
    }
    R.thumbs.forEach(function (t, i) { if (t && i !== cur && t.getAttribute('aria-current')) { t.removeAttribute('aria-current'); } });
    var t = R.thumbs[cur];
    if (t) {
      t.setAttribute('aria-current', 'true');
      if (!thumbsEl.classList.contains('is-off')) {
        var tr = t.getBoundingClientRect(), pr = thumbsEl.getBoundingClientRect();
        if (tr.top < pr.top || tr.bottom > pr.bottom) { thumbsEl.scrollTop += tr.top - pr.top - (pr.height - tr.height) / 2; }
      }
    }
  }
  function goTo(n, instant) {
    n = clamp(n, 1, N);
    cur = n;
    if (mode === 'reader' && R.built) {
      var el = R.els[n];
      R.lock = Date.now() + 500;
      scrollEl.scrollTop += el.getBoundingClientRect().top - scrollEl.getBoundingClientRect().top - 14;
      enqueue(n);
      showCurrent();
    } else if (mode === 'book' && B.flip) {
      B.flip.turnToPage(n - 1);
    }
  }

  // ------------------------------------------------------------------ поиск
  var S = { q: '', counts: [], total: 0, idx: -1, token: 0 };

  function pageText(n) {
    if (R.texts[n]) { return Promise.resolve(R.texts[n]); }
    return pdf.getPage(n).then(function (p) { return p.getTextContent(); }).then(function (tc) {
      var arr = [];
      tc.items.forEach(function (it) { if (typeof it.str === 'string' && it.str !== '') { arr.push(it.str.toLowerCase()); } });
      R.texts[n] = arr;
      return arr;
    });
  }
  function countIn(str, q) {
    var c = 0, i = 0;
    while ((i = str.indexOf(q, i)) !== -1) { c++; i += q.length; }
    return c;
  }
  function doSearch(raw) {
    var q = raw.trim().toLowerCase();
    var token = ++S.token;
    S.q = q;
    S.counts = [];
    S.total = 0;
    S.idx = -1;
    for (var n = 1; n <= N; n++) { clearHighlights(n); }
    if (q === '') { searchCount.textContent = ''; return Promise.resolve(); }
    searchCount.textContent = I18N.search_busy || '…';
    var chain = Promise.resolve();
    var counts = [];
    for (var p = 1; p <= N; p++) {
      (function (pn) {
        chain = chain.then(function () {
          if (token !== S.token) { return; }
          return pageText(pn).then(function (items) {
            var c = 0;
            items.forEach(function (s) { c += countIn(s, q); });
            counts[pn] = c;
          });
        });
      })(p);
    }
    return chain.then(function () {
      if (token !== S.token) { return; }
      S.counts = counts;
      S.total = counts.reduce(function (a, c) { return a + (c || 0); }, 0);
      for (var n2 = 1; n2 <= N; n2++) { applyHighlights(n2); }
      if (S.total === 0) { searchCount.textContent = I18N.search_none || ''; return; }
      // первое совпадение — с текущей страницы и ниже
      var k = 0;
      for (var pn = 1; pn < cur; pn++) { k += counts[pn] || 0; }
      if (k >= S.total) { k = 0; }
      return showMatch(k);
    });
  }
  function locate(k) {
    var acc = 0;
    for (var n = 1; n <= N; n++) {
      var c = S.counts[n] || 0;
      if (k < acc + c) { return { page: n, j: k - acc }; }
      acc += c;
    }
    return null;
  }
  function showMatch(k) {
    if (S.total === 0) { return Promise.resolve(); }
    k = ((k % S.total) + S.total) % S.total;
    S.idx = k;
    searchCount.textContent = fmt(I18N.search_count || ':a/:n', { a: k + 1, n: S.total });
    var loc = locate(k);
    if (!loc) { return Promise.resolve(); }
    var token = S.token;
    goTo(loc.page);
    var ready = enqueue(loc.page) || Promise.resolve();
    return ready.then(function () {
      if (token !== S.token) { return; }
      applyHighlights(loc.page);
      var marks = R.els[loc.page].querySelectorAll('mark');
      Array.prototype.forEach.call(scrollEl.querySelectorAll('mark.is-active'), function (m) { m.classList.remove('is-active'); });
      var m = marks[Math.min(loc.j, marks.length - 1)];
      if (m) {
        m.classList.add('is-active');
        var mr = m.getBoundingClientRect(), sr = scrollEl.getBoundingClientRect();
        R.lock = Date.now() + 500;
        scrollEl.scrollTop += mr.top - sr.top - sr.height / 2;
        scrollEl.scrollLeft += mr.left - sr.left - sr.width / 2 > 0 ? mr.left - sr.left - sr.width / 2 : 0;
      }
    });
  }
  function clearHighlights(n) {
    var el = R.els[n];
    if (!el) { return; }
    Array.prototype.forEach.call(el.querySelectorAll('mark'), function (m) {
      var parent = m.parentNode;
      parent.replaceChild(document.createTextNode(m.textContent), m);
      parent.normalize();
    });
  }
  function applyHighlights(n) {
    var el = R.els[n];
    if (!el || !S.q || el._state !== 'done') { return; }
    var tl = el.querySelector('.dv-text');
    if (!tl || tl.querySelector('mark')) { return; }
    Array.prototype.forEach.call(tl.querySelectorAll('span'), function (sp) {
      if (sp.firstElementChild || sp.classList.contains('markedContent')) { return; }
      var txt = sp.textContent;
      var low = txt.toLowerCase();
      if (low.length !== txt.length || low.indexOf(S.q) === -1) { return; }
      var frag = document.createDocumentFragment();
      var pos = 0, i;
      while ((i = low.indexOf(S.q, pos)) !== -1) {
        if (i > pos) { frag.appendChild(document.createTextNode(txt.slice(pos, i))); }
        var mk = document.createElement('mark');
        mk.textContent = txt.slice(i, i + S.q.length);
        frag.appendChild(mk);
        pos = i + S.q.length;
      }
      if (pos < txt.length) { frag.appendChild(document.createTextNode(txt.slice(pos))); }
      sp.textContent = '';
      sp.appendChild(frag);
    });
  }

  // ------------------------------------------------------------------ книга
  var B = { flip: null, srcs: {}, imgs: [], queue: [], active: 0, loading: null };

  function loadFlipLib() {
    if (window.St && window.St.PageFlip) { return Promise.resolve(); }
    if (B.loading) { return B.loading; }
    B.loading = new Promise(function (resolve, reject) {
      var s = document.createElement('script');
      s.src = FLIP_SRC;
      s.onload = function () { window.St && window.St.PageFlip ? resolve() : reject(new Error('page-flip')); };
      s.onerror = function () { B.loading = null; reject(new Error('page-flip')); };
      document.head.appendChild(s);
    });
    return B.loading;
  }

  function buildBook() {
    return loadFlipLib().then(function () {
      if (mode !== 'book') { return; }
      destroyBook();
      var el = document.createElement('div');
      bookStage.innerHTML = '';
      bookStage.appendChild(el);
      var pages = [];
      for (var n = 1; n <= N; n++) {
        var d = document.createElement('div');
        d.className = 'dv-bpage is-busy';
        d.setAttribute('data-n', String(n));
        if (n === 1 || (n === N && N > 1)) { d.setAttribute('data-density', 'hard'); }
        B.imgs[n] = d;
        pages.push(d);
        el.appendChild(d);
      }
      var ratio = base.w / base.h;
      var flip = new window.St.PageFlip(el, {
        width: 400, height: Math.round(400 / ratio), size: 'stretch',
        minWidth: 384, maxWidth: 900, minHeight: 300, maxHeight: 1600,
        showCover: true, usePortrait: true, startPage: clamp(cur - 1, 0, N - 1),
        flippingTime: 700, maxShadowOpacity: 0.4, drawShadow: true, mobileScrollSupport: true, swipeDistance: 25
      });
      B.flip = flip;
      flip.loadFromHTML(pages);
      flip.on('flip', function (e) { cur = e.data + 1; bookChanged(); });
      flip.on('changeOrientation', function () { bookChanged(); });
      bookChanged();
    });
  }
  function destroyBook() {
    if (B.flip) { try { B.flip.destroy(); } catch (e) { /* уже удалена */ } }
    B.flip = null;
    B.queue = [];
    B.imgs = [];
    bookStage.innerHTML = '';
  }
  function bookChanged() {
    var idx = cur - 1;
    var portrait = B.flip && B.flip.getOrientation && B.flip.getOrientation() === 'portrait';
    var single = portrait || idx === 0 || idx + 1 >= N;
    bookPageLabel.textContent = single ? fmt(I18N.page_of, { a: idx + 1, n: N }) : fmt(I18N.pages_of, { a: idx + 1, b: idx + 2, n: N });
    var prev = $('[data-act="book-prev"]'), next = $('[data-act="book-next"]');
    prev.disabled = idx <= 0;
    next.disabled = (single ? idx + 1 : idx + 2) >= N;
    for (var n = idx - 3; n <= idx + 4; n++) { if (n >= 0 && n < N) { bookRender(n + 1); } }
    Object.keys(B.srcs).forEach(function (k) {
      if (Math.abs(parseInt(k, 10) - 1 - idx) > 10 && B.imgs[k]) {
        delete B.srcs[k];
        var im = B.imgs[k].querySelector('img');
        if (im) { B.imgs[k].removeChild(im); B.imgs[k].classList.add('is-busy'); }
      }
    });
  }
  function bookRender(n) {
    var holder = B.imgs[n];
    if (!holder || holder._busy || holder.querySelector('img')) { return; }
    if (B.srcs[n]) { attachImg(holder, B.srcs[n]); return; }
    holder._busy = true;
    B.queue.push(n);
    bookPump();
  }
  function bookPump() {
    while (B.active < 2 && B.queue.length) {
      var n = B.queue.shift();
      var holder = B.imgs[n];
      if (!holder) { continue; }
      B.active++;
      (function (n, holder, flipRef) {
        pdf.getPage(n).then(function (page) {
          var vp0 = page.getViewport({ scale: 1 });
          var targetH = clamp(bookStage.clientHeight * clamp(window.devicePixelRatio || 1, 1, 2), 700, 1600);
          var vp = page.getViewport({ scale: targetH / vp0.height });
          var cv = document.createElement('canvas');
          cv.width = Math.floor(vp.width);
          cv.height = Math.floor(vp.height);
          return page.render({ canvas: cv, canvasContext: cv.getContext('2d'), viewport: vp, background: '#ffffff' }).promise.then(function () {
            return cv.toDataURL('image/jpeg', 0.88);
          });
        }).then(function (url) {
          holder._busy = false;
          if (B.flip === flipRef) { B.srcs[n] = url; attachImg(holder, url); }
        }, function () { holder._busy = false; }).then(function () { B.active--; bookPump(); });
      })(n, holder, B.flip);
    }
  }
  function attachImg(holder, url) {
    if (holder.querySelector('img')) { return; }
    var im = document.createElement('img');
    im.alt = '';
    im.draggable = false;
    im.src = url;
    holder.appendChild(im);
    holder.classList.remove('is-busy');
  }

  // ------------------------------------------------------------------ полный экран
  function isFull() { return document.fullscreenElement === app || document.webkitFullscreenElement === app || app.classList.contains('is-full'); }
  function fsChanged() {
    var on = isFull();
    var btn = $('[data-act="fullscreen"]');
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
    var lab = $('[data-fs-label]');
    var text = on ? I18N.fullscreen_off : I18N.fullscreen;
    lab.textContent = text;
    btn.setAttribute('aria-label', text);
    window.setTimeout(function () {
      if (mode === 'reader' && R.fit) { applyFit(false); }
      if (mode === 'book' && B.flip) { try { B.flip.update(); } catch (e) { /* нет */ } }
    }, 150);
  }
  function toggleFull() {
    if (isFull()) {
      if (document.fullscreenElement || document.webkitFullscreenElement) {
        (document.exitFullscreen || document.webkitExitFullscreen).call(document);
      }
      app.classList.remove('is-full');
      fsChanged();
      return;
    }
    var req = app.requestFullscreen || app.webkitRequestFullscreen;
    if (req) {
      var r;
      try { r = req.call(app); } catch (e) { r = null; }
      if (r && r.catch) { r.catch(function () { app.classList.add('is-full'); fsChanged(); }); }
    } else {
      app.classList.add('is-full');
      fsChanged();
    }
  }
  document.addEventListener('fullscreenchange', fsChanged);
  document.addEventListener('webkitfullscreenchange', fsChanged);

  // ------------------------------------------------------------------ кнопки и клавиши
  app.addEventListener('click', function (ev) {
    var t = ev.target.closest ? ev.target.closest('[data-act],[data-mode-btn]') : null;
    if (!t || !app.contains(t)) { return; }
    var mb = t.getAttribute('data-mode-btn');
    if (mb) { if (mb !== mode && pdf) { setMode(mb, { persist: true }); } return; }
    switch (t.getAttribute('data-act')) {
      case 'fullscreen': toggleFull(); break;
      case 'thumbs': setThumbs(thumbsEl.classList.contains('is-off')); break;
      case 'zoom-in': setScale(R.scale * 1.2, false); break;
      case 'zoom-out': setScale(R.scale / 1.2, false); break;
      case 'zoom-fit': setScale(fitScale(), true); break;
      case 'search-next': searchStep(1); break;
      case 'search-prev': searchStep(-1); break;
      case 'book-prev': if (B.flip) { B.flip.flipPrev('top'); } break;
      case 'book-next': if (B.flip) { B.flip.flipNext('top'); } break;
    }
  });
  function searchStep(d) {
    if (searchInput.value.trim().toLowerCase() !== S.q) { doSearch(searchInput.value); return; }
    if (S.total > 0) { showMatch(S.idx + d); }
  }
  searchForm.addEventListener('submit', function (ev) { ev.preventDefault(); searchStep(1); });
  searchInput.addEventListener('keydown', function (ev) { if (ev.key === 'Enter' && ev.shiftKey) { ev.preventDefault(); searchStep(-1); } });
  searchInput.addEventListener('input', debounce(function () { doSearch(searchInput.value); }, 350));

  document.addEventListener('keydown', function (ev) {
    if (ev.key === 'Escape' && app.classList.contains('is-full')) { toggleFull(); return; }
    if (mode !== 'book' || !B.flip || ev.altKey || ev.ctrlKey || ev.metaKey) { return; }
    var tag = ev.target && ev.target.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT') { return; }
    if (ev.key === 'ArrowRight') { ev.preventDefault(); B.flip.flipNext('top'); }
    else if (ev.key === 'ArrowLeft') { ev.preventDefault(); B.flip.flipPrev('top'); }
  });
  window.addEventListener('resize', debounce(function () {
    if (mode === 'reader' && R.fit && R.built) { applyFit(false); }
  }, 200));

  // ------------------------------------------------------------------ старт
  var initial = paramMode() || getStored() || (app.getAttribute('data-mode') === 'book' ? 'book' : 'reader');
  load().then(function () {
    elLoading.hidden = true;
    setMode(initial, {});
  }).catch(function () {
    showError();
  });
})();
