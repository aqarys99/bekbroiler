/* Загрузка MP4 частями с возобновлением (экран «Видео»). */
(function () {
  'use strict';
  var root = document.querySelector('[data-video-uploader]');
  if (!root) { return; }
  var q = function (n) { return root.querySelector('[data-role="' + n + '"]'); };
  var openBtn = q('open'), input = q('input'), box = q('progress'), nameEl = q('name'), bar = q('bar'),
      fill = q('fill'), textEl = q('text'), retry = q('retry'), cancel = q('cancel'), errEl = q('error');
  var i18n = {};
  try { i18n = JSON.parse(root.getAttribute('data-i18n') || '{}'); } catch (e) { i18n = {}; }
  var maxBytes = parseInt(root.getAttribute('data-max-bytes'), 10) || 0;
  var csrf = root.getAttribute('data-csrf');
  var state = null; // { file, id, chunkSize, chunks, received:{}, aborted }

  function fmt(b) { return (b / 1048576).toFixed(1).replace('.', ',') + ' МБ'; }
  function showError(msg) { errEl.textContent = msg; errEl.hidden = !msg; }
  function setProgress(done, total) {
    var pct = total ? Math.round(done / total * 100) : 0;
    fill.style.width = pct + '%';
    bar.setAttribute('aria-valuenow', String(pct));
    textEl.textContent = i18n.progress.replace(':done', fmt(done)).replace(':total', fmt(total)) + ' (' + pct + '%)';
  }
  function key(f) { return 'video-upload:' + f.name + ':' + f.size + ':' + f.lastModified; }

  function post(url, fields, file) {
    var fd = new FormData();
    Object.keys(fields).forEach(function (k) { fd.append(k, fields[k]); });
    if (file) { fd.append('chunk', file, 'chunk.part'); }
    return fetch(url, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, code: 'server', message: i18n.network }; })
        .then(function (j) { j.status = r.status; return j; });
    });
  }

  function fail(j) {
    box.hidden = false;
    showError((j && j.message) || i18n.network);
    var fatal = j && j.status && j.status < 500 && j.status !== 408 && j.status !== 429;
    retry.hidden = fatal;
    if (fatal) { try { localStorage.removeItem(key(state.file)); } catch (e) {} }
    return false;
  }
  function netFail() {
    showError(i18n.resume);
    retry.hidden = false;
    return false;
  }

  function start(file) {
    showError('');
    if (!/\.mp4$/i.test(file.name)) { showError(i18n.type); return; }
    if (maxBytes && file.size > maxBytes) { showError(i18n.too_large); return; }
    state = { file: file, id: null, chunkSize: 0, chunks: 0, received: {}, aborted: false };
    nameEl.textContent = file.name;
    box.hidden = false; retry.hidden = true; openBtn.disabled = true;
    setProgress(0, file.size);
    run();
  }

  function run() {
    showError(''); retry.hidden = true;
    var f = state.file, saved = null;
    try { saved = localStorage.getItem(key(f)); } catch (e) {}
    var begin = saved
      ? post(root.dataset.status, { upload_id: saved }).then(function (j) { return j.ok ? j : null; })
      : Promise.resolve(null);
    begin.catch(function () { return null; }).then(function (j) {
      if (j) { return j; }
      return post(root.dataset.init, { name: f.name, size: f.size });
    }).then(function (j) {
      if (state.aborted) { return; }
      if (!j.ok) { return fail(j); }
      state.id = j.upload_id; state.chunkSize = j.chunk_size; state.chunks = j.chunks;
      state.received = {};
      (j.received || []).forEach(function (i) { state.received[i] = true; });
      try { localStorage.setItem(key(f), state.id); } catch (e) {}
      return next();
    }).catch(netFail);
  }

  function doneBytes() {
    var n = 0;
    Object.keys(state.received).forEach(function (i) {
      i = +i;
      n += (i === state.chunks - 1) ? state.file.size - state.chunkSize * i : state.chunkSize;
    });
    return n;
  }

  function next() {
    if (state.aborted) { return; }
    var i = 0;
    while (i < state.chunks && state.received[i]) { i++; }
    setProgress(doneBytes(), state.file.size);
    if (i >= state.chunks) { return finish(); }
    var blob = state.file.slice(i * state.chunkSize, Math.min(state.file.size, (i + 1) * state.chunkSize));
    return post(root.dataset.chunk, { upload_id: state.id, index: String(i) }, blob).then(function (j) {
      if (state.aborted) { return; }
      if (!j.ok) {
        if (j.code === 'video_session') { try { localStorage.removeItem(key(state.file)); } catch (e) {} }
        return fail(j);
      }
      state.received[i] = true;
      return next();
    }).catch(netFail);
  }

  function finish() {
    textEl.textContent = i18n.finishing;
    return post(root.dataset.finish, { upload_id: state.id, title: '' }).then(function (j) {
      if (!j.ok) { return fail(j); }
      try { localStorage.removeItem(key(state.file)); } catch (e) {}
      window.location.href = j.redirect;
    }).catch(netFail);
  }

  openBtn.addEventListener('click', function () { input.click(); });
  input.addEventListener('change', function () {
    if (input.files && input.files[0]) { start(input.files[0]); }
    input.value = '';
  });
  retry.addEventListener('click', function () { if (state) { run(); } });
  cancel.addEventListener('click', function () {
    if (state) { state.aborted = true; try { localStorage.removeItem(key(state.file)); } catch (e) {} }
    state = null; box.hidden = true; showError(''); openBtn.disabled = false;
  });
  window.addEventListener('beforeunload', function (ev) {
    if (state && !state.aborted && state.id && retry.hidden) { ev.preventDefault(); ev.returnValue = ''; }
  });
})();
