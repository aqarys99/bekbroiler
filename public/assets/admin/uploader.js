/* Загрузчик фото: очередь, сжатие в браузере (canvas), отправка по одному через XHR.
   Используется на экране «Медиатека» (авто-инициализация [data-uploader]) и в окне выбора (media-picker.js). */
(function () {
  'use strict';
  if (window.__mediaUploaderInit) { return; }
  window.__mediaUploaderInit = true;

  var EXT_IMAGE = /\.(jpe?g|png|webp|gif|heic|heif)$/i;
  var EXT_JPEG = /\.jpe?g$/i;
  var EXT_HEIC = /\.(heic|heif)$/i;
  var NON_RETRY = { not_image: 1, too_large: 1, heic_unsupported: 1, broken: 1, too_many_pixels: 1, no_file: 1, disk_full: 1, session: 1, image_unsupported: 1 };

  function fmt(b) {
    b = Math.max(0, Math.round(b));
    if (b < 1024) { return b + ' Б'; }
    var kb = Math.round(b / 1024);
    if (kb < 1024) { return kb + ' КБ'; }
    var mb = b / 1048576;
    if (Math.round(mb * 10) / 10 < 1024) { return mb.toFixed(1).replace('.', ',') + ' МБ'; }
    return (b / 1073741824).toFixed(1).replace('.', ',') + ' ГБ';
  }
  function tpl(s, vars) {
    return String(s).replace(/:(\w+)/g, function (m, k) { return vars && k in vars ? vars[k] : m; });
  }
  function el(tag, cls, attrs) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (attrs) { Object.keys(attrs).forEach(function (k) { n.setAttribute(k, attrs[k]); }); }
    return n;
  }

  // Браузер не должен открывать брошенный мимо зоны файл
  var globalDrag = false;
  function preventGlobalDrop() {
    if (globalDrag) { return; }
    globalDrag = true;
    ['dragover', 'drop'].forEach(function (n) {
      document.addEventListener(n, function (ev) {
        if (ev.dataTransfer && Array.prototype.indexOf.call(ev.dataTransfer.types || [], 'Files') !== -1) { ev.preventDefault(); }
      });
    });
  }

  // ---------- сжатие ----------
  function decode(file) {
    if (window.createImageBitmap) {
      return window.createImageBitmap(file, { imageOrientation: 'from-image' }).catch(function () {
        return window.createImageBitmap(file);
      }).catch(function () { return decodeViaImg(file); });
    }
    return decodeViaImg(file);
  }
  function decodeViaImg(file) {
    return new Promise(function (resolve, reject) {
      var url = URL.createObjectURL(file);
      var img = new Image();
      img.onload = function () { URL.revokeObjectURL(url); resolve(img); };
      img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('decode')); };
      img.src = url;
    });
  }
  function toBlob(canvas, type, q) {
    return new Promise(function (resolve) { canvas.toBlob(resolve, type, q); });
  }

  /** @returns Promise<{blob:Blob|File, name:string}> */
  function compress(file, cfg) {
    var type = (file.type || '').toLowerCase();
    var name = file.name || 'photo';
    var isGif = type === 'image/gif' || /\.gif$/i.test(name);
    if (isGif) { return Promise.resolve({ blob: file, name: name }); }
    var isPngWebp = type === 'image/png' || type === 'image/webp' || /\.(png|webp)$/i.test(name);
    var asIs = { blob: file, name: name };
    return decode(file).then(function (bmp) {
      var w = bmp.width || bmp.naturalWidth, h = bmp.height || bmp.naturalHeight;
      var longSide = Math.max(w, h);
      if (!w || !h) { return asIs; }
      if (isPngWebp && longSide <= cfg.maxSide) { if (bmp.close) { bmp.close(); } return asIs; }
      var scale = Math.min(1, cfg.maxSide / longSide);
      var cw = Math.max(1, Math.round(w * scale)), ch = Math.max(1, Math.round(h * scale));
      var canvas = document.createElement('canvas');
      canvas.width = cw; canvas.height = ch;
      var ctx = canvas.getContext('2d');
      var outType = isPngWebp ? (type === 'image/webp' || /\.webp$/i.test(name) ? 'image/webp' : 'image/png') : 'image/jpeg';
      if (outType === 'image/jpeg') { ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, cw, ch); }
      ctx.drawImage(bmp, 0, 0, cw, ch);
      if (bmp.close) { bmp.close(); }
      return toBlob(canvas, outType, outType === 'image/png' ? undefined : cfg.quality).then(function (blob) {
        canvas.width = canvas.height = 0;
        if (!blob || blob.size >= file.size) { return asIs; }
        var outName = name;
        if (outType === 'image/jpeg' && !EXT_JPEG.test(name)) { outName = name.replace(/\.[^.]+$/, '') + '.jpg'; }
        return { blob: blob, name: outName };
      });
    }, function () { return asIs; }); // не декодируется (например HEIC) — отправляем как есть
  }

  function looksLikeImage(file) {
    var type = (file.type || '').toLowerCase();
    if (type) { return type.indexOf('image/') === 0 && type !== 'image/svg+xml' ? true : (EXT_HEIC.test(file.name || '') && type === 'application/octet-stream'); }
    return EXT_IMAGE.test(file.name || '');
  }

  // Один обработчик beforeunload на страницу, даже если окна пикера открывались много раз
  var registry = [];
  var leaveText = '';
  window.addEventListener('beforeunload', function (ev) {
    if (registry.some(function (u) { return u.isBusy(); })) { ev.preventDefault(); ev.returnValue = leaveText; return leaveText; }
  });

  // ---------- загрузчик ----------
  /**
   * opts: cfg{url,maxBytes,maxSide,quality,csrf}, i18n, queue{root,summary,saved,clear,list},
   *       onDone(media, disk), onBusy(isBusy)
   */
  function create(opts) {
    var cfg = opts.cfg, t = opts.i18n, q = opts.queue;
    var items = [];
    var pending = [];
    var running = false;
    var halted = false;

    // Забываем только загрузчики, чья очередь уже убрана из страницы (закрытое окно выбора);
    // простаивающий загрузчик страницы остаётся в реестре, чтобы beforeunload видел его работу.
    registry = registry.filter(function (u) { return u.queueRoot && document.contains(u.queueRoot); });
    leaveText = t.leave;

    function active(it) { return it.status === 'wait' || it.status === 'compress' || it.status === 'upload'; }
    function isBusy() { return items.some(active); }

    function refresh() {
      var done = items.filter(function (i) { return i.status === 'done'; });
      q.root.hidden = items.length === 0;
      q.summary.textContent = tpl(t.summary, { done: done.length, total: items.length });
      var saved = done.reduce(function (s, i) { return s + Math.max(0, i.file.size - i.resultSize); }, 0);
      q.saved.hidden = saved <= 0;
      q.saved.textContent = saved > 0 ? tpl(t.saved, { size: fmt(saved) }) : '';
      var busy = isBusy();
      q.clear.disabled = busy;
      if (opts.onBusy) { opts.onBusy(busy); }
    }

    function setStatus(it, status, text) {
      it.status = status;
      it.row.setAttribute('data-status', status);
      it.pill.setAttribute('data-status', status);
      it.pill.textContent = text || t[status] || '';
    }
    function setBar(it, pct) {
      it.bar.style.width = pct + '%';
      it.barBox.setAttribute('aria-valuenow', String(Math.round(pct)));
    }
    function setError(it, code, message, loginLink) {
      setStatus(it, 'error', t.error);
      setBar(it, 0);
      it.sizes.textContent = '';
      if (loginLink) {
        var a = el('a'); a.href = t.loginUrl; a.textContent = message; it.sizes.appendChild(a);
      } else {
        it.sizes.textContent = message;
      }
      it.errCode = code;
      it.retryBtn.hidden = !!NON_RETRY[code] || code === 'server_unknown';
      refresh();
    }

    function buildRow(it) {
      var row = el('div', 'queue__item', { 'data-testid': 'media-queue-item', 'data-status': 'wait' });
      it.thumb = el('span', 'queue__thumb');
      var main = el('div', 'queue__main');
      var line = el('div', 'queue__row');
      var name = el('b', 'queue__name'); name.textContent = it.file.name;
      it.sizes = el('span', 'queue__sizes'); it.sizes.textContent = fmt(it.file.size);
      line.appendChild(name); line.appendChild(it.sizes);
      it.barBox = el('div', 'queue__bar', { role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': '100', 'aria-valuenow': '0', 'aria-label': it.file.name });
      it.bar = el('div'); it.barBox.appendChild(it.bar);
      main.appendChild(line); main.appendChild(it.barBox);
      it.pill = el('span', 'pill', { 'data-status': 'wait' }); it.pill.textContent = t.wait;
      it.retryBtn = el('button', 'btn--text', { type: 'button', 'data-testid': 'media-queue-retry' });
      it.retryBtn.textContent = t.retry; it.retryBtn.hidden = true;
      it.retryBtn.addEventListener('click', function () {
        if (it.retryBtn.hidden) { return; }
        it.retryBtn.hidden = true;
        it.sizes.textContent = fmt(it.file.size);
        setStatus(it, 'wait');
        pending.push(it);
        refresh();
        pump();
      });
      row.appendChild(it.thumb); row.appendChild(main); row.appendChild(it.pill); row.appendChild(it.retryBtn);
      it.row = row;
      q.list.appendChild(row);
    }

    function add(files) {
      var list = Array.prototype.slice.call(files || []);
      list.forEach(function (file) {
        var it = { file: file, status: 'wait', resultSize: 0, errCode: '' };
        items.push(it);
        buildRow(it);
        if (!looksLikeImage(file)) {
          setError(it, 'not_image', t.err_not_image);
        } else {
          pending.push(it);
        }
      });
      refresh();
      pump();
    }

    function send(it, blob, name) {
      return new Promise(function (resolve) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', cfg.url);
        xhr.setRequestHeader('X-Requested-With', 'fetch');
        xhr.setRequestHeader('X-CSRF-Token', cfg.csrf);
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.upload.onprogress = function (ev) {
          if (!ev.lengthComputable) { return; }
          var pct = Math.min(100, Math.round(ev.loaded / ev.total * 100));
          setStatus(it, 'upload', tpl(t.upload, { percent: pct }));
          setBar(it, pct);
        };
        xhr.onload = function () {
          var json = null;
          try { json = JSON.parse(xhr.responseText); } catch (e) { json = null; }
          resolve({ status: xhr.status, json: json });
        };
        xhr.onerror = xhr.ontimeout = xhr.onabort = function () { resolve({ status: 0, json: null }); };
        var fd = new FormData();
        fd.append('file', blob, name);
        setStatus(it, 'upload', tpl(t.upload, { percent: 0 }));
        xhr.send(fd);
      });
    }

    function handle(it, res) {
      var j = res.json;
      if (res.status === 201 && j && j.ok) {
        var m = j.media;
        it.resultSize = m.result_size || 0;
        setStatus(it, 'done', t.done);
        setBar(it, 100);
        it.sizes.textContent = fmt(it.file.size) + ' → ' + fmt(it.resultSize);
        it.thumb.textContent = '';
        var img = el('img', null, { alt: '', width: '48', height: '48' });
        img.src = m.thumb_jpg || m.thumb_webp || '';
        it.thumb.appendChild(img);
        it.media = m;
        refresh();
        if (opts.onDone) { opts.onDone(m, j.disk || null, it); }
        return;
      }
      if (res.status === 401) {
        setError(it, 'session', t.err_session, true);
        halted = true;
        pending.splice(0).forEach(function (o) { setError(o, 'session', t.err_session, true); });
        return;
      }
      if (res.status === 413 && !(j && j.code)) { setError(it, 'too_large', t.err_too_large); return; }
      if (j && j.ok === false && j.code === 'image_unsupported') { setError(it, 'image_unsupported', j.message || t.err_network); return; }
      if (res.status === 400 && j && j.code === 'csrf') { setError(it, 'csrf', t.err_csrf); return; }
      if (j && j.ok === false && j.code && res.status < 500) {
        var msg = t['err_' + j.code] || j.message || t.err_network;
        if (j.code === 'too_many_pixels' && j.message) { msg = j.message; }
        setError(it, j.code, msg);
        return;
      }
      setError(it, 'network', t.err_network);
    }

    function pump() {
      if (running || halted) { return; }
      var it = pending.shift();
      if (!it) { return; }
      running = true;
      setStatus(it, 'compress');
      setBar(it, 0);
      refresh();
      compress(it.file, cfg).then(function (out) {
        if (out.blob.size > cfg.maxBytes) {
          setError(it, 'too_large', t.err_too_large);
          return null;
        }
        it.sizes.textContent = out.blob.size < it.file.size ? fmt(it.file.size) + ' → ' + fmt(out.blob.size) : fmt(it.file.size);
        return send(it, out.blob, out.name).then(function (res) { handle(it, res); });
      }).catch(function () {
        setError(it, 'network', t.err_network);
      }).then(function () {
        running = false;
        refresh();
        pump();
      });
    }

    q.clear.addEventListener('click', function () {
      if (q.clear.disabled) { return; }
      items = []; pending = [];
      q.list.textContent = '';
      refresh();
    });
    refresh();
    var api = { add: add, isBusy: isBusy, queueRoot: q.root };
    registry.push(api);
    return api;
  }

  function filesFromDrop(dt) {
    var out = [];
    if (!dt) { return out; }
    var list = dt.files || [];
    var entries = dt.items || [];
    for (var i = 0; i < list.length; i++) {
      var entry = entries[i] && entries[i].webkitGetAsEntry ? entries[i].webkitGetAsEntry() : null;
      if (entry && entry.isDirectory) { continue; }
      out.push(list[i]);
    }
    return out;
  }

  function bindDropzone(dz, input, uploader) {
    preventGlobalDrop();
    dz.addEventListener('click', function () { input.click(); });
    input.addEventListener('change', function () {
      var files = Array.prototype.slice.call(input.files || []);
      input.value = '';
      if (files.length) { uploader.add(files); }
    });
    var depth = 0;
    dz.addEventListener('dragenter', function (ev) { ev.preventDefault(); depth++; dz.classList.add('is-drag'); });
    dz.addEventListener('dragover', function (ev) { ev.preventDefault(); if (ev.dataTransfer) { ev.dataTransfer.dropEffect = 'copy'; } dz.classList.add('is-drag'); });
    dz.addEventListener('dragleave', function () { depth = Math.max(0, depth - 1); if (!depth) { dz.classList.remove('is-drag'); } });
    dz.addEventListener('drop', function (ev) {
      ev.preventDefault(); depth = 0; dz.classList.remove('is-drag');
      var files = filesFromDrop(ev.dataTransfer);
      if (files.length) { uploader.add(files); }
    });
  }

  function readCfg(root) {
    return {
      url: root.getAttribute('data-url'),
      maxBytes: parseInt(root.getAttribute('data-max-bytes'), 10) || 0,
      maxSide: parseInt(root.getAttribute('data-max-side'), 10) || 2560,
      quality: parseFloat(root.getAttribute('data-quality')) || 0.85,
      csrf: root.getAttribute('data-csrf') || ''
    };
  }

  /** Строит зону загрузки и очередь внутри container (окно выбора). */
  function mountPanel(container, o) {
    var t = o.i18n;
    var dz = el('button', 'dropzone', { type: 'button', 'data-testid': o.dropzoneTestid || 'media-dropzone' });
    var title = el('b', 'dropzone__title'); title.textContent = t.dropTitle;
    var hint = el('span', 'dropzone__hint'); hint.textContent = t.dropHint;
    dz.appendChild(title); dz.appendChild(hint);
    var input = el('input', null, { type: 'file', multiple: '', hidden: '', tabindex: '-1', 'data-testid': 'media-file-input', accept: 'image/jpeg,image/png,image/webp,image/gif,image/heic,image/heif,.heic,.heif' });
    var root = el('div', 'queue', { 'data-testid': 'media-queue' }); root.hidden = true;
    var head = el('div', 'queue__head');
    var left = el('span'); var summary = el('b', null, { 'data-testid': 'media-queue-summary' });
    var saved = el('span', 'queue__saved'); saved.hidden = true;
    left.appendChild(summary); left.appendChild(document.createTextNode(' ')); left.appendChild(saved);
    var clear = el('button', 'btn--text', { type: 'button', 'data-testid': 'media-queue-clear' }); clear.textContent = t.clear;
    head.appendChild(left); head.appendChild(clear);
    var list = el('div', null, { 'aria-live': 'polite' });
    root.appendChild(head); root.appendChild(list);
    container.appendChild(dz); container.appendChild(input); container.appendChild(root);
    var up = create({ cfg: o.cfg, i18n: t, queue: { root: root, summary: summary, saved: saved, clear: clear, list: list }, onDone: o.onDone, onBusy: o.onBusy });
    bindDropzone(dz, input, up);
    return { uploader: up, dropzone: dz };
  }

  function newTile(m, backHref) {
    var li = el('li');
    var a = el('a', 'media-item', { 'data-testid': 'media-item', title: m.original_name });
    a.href = m.url + '?back=' + encodeURIComponent(backHref);
    var box = el('span', 'media-item__img');
    var pic = el('picture');
    if (m.thumb_webp) { var s = el('source', null, { type: 'image/webp' }); s.srcset = m.thumb_webp; pic.appendChild(s); }
    var img = el('img', null, { alt: m.alt || '', loading: 'lazy' });
    img.src = m.thumb_jpg || m.thumb_webp || '';
    pic.appendChild(img); box.appendChild(pic);
    var name = el('span', 'media-item__name'); name.textContent = m.original_name;
    var meta = el('span', 'media-item__meta'); meta.textContent = m.width && m.height ? m.width + ' × ' + m.height + ' px' : '';
    a.appendChild(box); a.appendChild(name); a.appendChild(meta);
    li.appendChild(a);
    return li;
  }

  window.MediaUploader = { create: create, mountPanel: mountPanel, bindDropzone: bindDropzone, readCfg: readCfg, fmt: fmt, tpl: tpl, el: el };

  // ---------- экран «Медиатека» ----------
  document.querySelectorAll('[data-uploader]').forEach(function (root) {
    var i18n = {};
    try { i18n = JSON.parse(root.getAttribute('data-i18n') || '{}'); } catch (e) { i18n = {}; }
    var cfg = readCfg(root);
    var $ = function (r) { return root.querySelector('[data-role="' + r + '"]'); };
    var page = document.querySelector('main') || document;
    var grid = page.querySelector('[data-role="grid"]');
    var empty = page.querySelector('[data-role="empty"]');
    var disk = page.querySelector('[data-role="disk"]');
    var up = create({
      cfg: cfg, i18n: i18n,
      queue: { root: $('queue'), summary: $('summary'), saved: $('saved'), clear: $('clear'), list: $('list') },
      onDone: function (m, d) {
        if (grid) { grid.hidden = false; grid.insertBefore(newTile(m, location.pathname + location.search), grid.firstChild); }
        if (empty) { empty.hidden = true; }
        if (d && d.line && disk) { disk.textContent = d.line; }
      }
    });
    bindDropzone($('dropzone'), $('input'), up);
  });
})();
