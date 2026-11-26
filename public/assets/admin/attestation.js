/* Раздел «Аттестация»: строки файлов, загрузка по одному (XHR с прогрессом), порядок файлов перетаскиванием. */
(function () {
  'use strict';

  function tpl(s, vars) {
    return String(s).replace(/:(\w+)/g, function (m, k) { return vars && k in vars ? vars[k] : m; });
  }
  function ext(name) { var m = /\.([^.]+)$/.exec(name || ''); return m ? m[1].toLowerCase() : ''; }
  function titleFromName(name) {
    var t = String(name).replace(/\.[^.]+$/, '').replace(/_/g, ' ').replace(/\s+/g, ' ').trim();
    return t.slice(0, 200);
  }

  var root = document.querySelector('[data-att-files]');
  if (root) { initUploader(root); }

  function initUploader(root) {
    var cfg = JSON.parse(root.getAttribute('data-cfg'));
    var T = cfg.i18n;
    var form = root.closest('form');
    var rowsBox = root.querySelector('[data-role="rows"]');
    var tplEl = root.querySelector('template[data-role="tpl"]');
    var addBtn = root.querySelector('[data-role="add"]');
    var multi = root.querySelector('[data-role="multi"]');
    var summary = root.querySelector('[data-role="summary"]');
    var submit = form.querySelector('[data-role="submit"]');
    var skip = form.querySelector('[data-role="skip"]');
    var busy = false;
    var page = null; // создана страница (режим create): {files_url, done_url, edit_url}
    var created = cfg.mode === 'create';
    var submitLabel = submit.textContent;

    // JS работает: прячем «noscript»-строки (их и так не видно) и показываем кнопки
    addBtn.hidden = false;
    function rows() { return Array.prototype.slice.call(rowsBox.querySelectorAll('[data-row]')); }
    function q(row, role) { return row.querySelector('[data-role="' + role + '"]'); }

    function setStatus(row, text, kind) {
      var st = q(row, 'status');
      st.textContent = text;
      st.className = 'att-row__status' + (kind ? ' att-row__status--' + kind : '');
      var old = st.querySelector('button');
      if (old) { old.remove(); }
    }
    function setFile(row, file) {
      row.__file = file || null;
      row.__done = false;
      q(row, 'fname').textContent = file ? file.name : T.pick;
      var vm = q(row, 'viewmode-field');
      vm.hidden = !(file && ext(file.name) === 'pdf');
      setStatus(row, '', '');
      row.classList.remove('is-error');
    }
    function wire(row) {
      q(row, 'remove').hidden = false;
      var input = q(row, 'file');
      input.addEventListener('change', function () {
        var f = input.files && input.files[0];
        setFile(row, f);
        var title = q(row, 'title');
        if (f && title.value.trim() === '') { title.value = titleFromName(f.name); }
        input.value = '';
      });
      q(row, 'remove').addEventListener('click', function () {
        if (busy) { return; }
        row.remove();
        if (!rows().length) { addRow(); }
      });
    }
    function addRow() {
      var holder = document.createElement('div');
      holder.innerHTML = tplEl.innerHTML.trim();
      var row = holder.firstElementChild;
      rowsBox.appendChild(row);
      wire(row);
      return row;
    }
    rows().forEach(wire);
    addBtn.addEventListener('click', function () {
      var row = addRow();
      var pick = q(row, 'file');
      row.scrollIntoView({ block: 'nearest' });
      q(row, 'title').focus();
      void pick;
    });
    multi.addEventListener('change', function () {
      var files = Array.prototype.slice.call(multi.files || []);
      files.forEach(function (f) {
        // пустую первую строку используем, остальные добавляем
        var row = rows().filter(function (r) { return !r.__file && q(r, 'title').value.trim() === ''; })[0] || addRow();
        setFile(row, f);
        q(row, 'title').value = titleFromName(f.name);
      });
      multi.value = '';
    });

    // ---------- проверка на клиенте ----------
    function validate(row) {
      if (!row.__file) { return T.no_file; }
      if (cfg.exts.indexOf(ext(row.__file.name)) === -1) { return T.bad_type; }
      if (row.__file.size > cfg.max) { return T.too_large; }
      return '';
    }
    function active() {
      return rows().filter(function (r) {
        return r.__file || q(r, 'title').value.trim() !== '' || q(r, 'title_kk').value.trim() !== '';
      });
    }

    // ---------- отправка ----------
    function fieldErr(name, text) {
      var el = form.querySelector('[data-role="err-' + name + '"]');
      if (!el) { return; }
      el.textContent = text || '';
      el.hidden = !text;
      var inp = form.querySelector('[name="' + name + '"]');
      if (inp) { if (text) { inp.setAttribute('aria-invalid', 'true'); } else { inp.removeAttribute('aria-invalid'); } }
    }
    function say(text) { summary.textContent = text || ''; summary.hidden = !text; }

    function post(url, fd, onProgress) {
      return new Promise(function (resolve) {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.setRequestHeader('X-Requested-With', 'fetch');
        xhr.setRequestHeader('Accept', 'application/json');
        xhr.setRequestHeader('X-CSRF-Token', cfg.csrf);
        if (onProgress && xhr.upload) {
          xhr.upload.onprogress = function (ev) { if (ev.lengthComputable) { onProgress(Math.round(ev.loaded * 100 / ev.total)); } };
        }
        xhr.onload = function () {
          var j = null;
          try { j = JSON.parse(xhr.responseText); } catch (e) { j = null; }
          resolve({ status: xhr.status, json: j });
        };
        xhr.onerror = function () { resolve({ status: 0, json: null }); };
        xhr.send(fd);
      });
    }

    function uploadRow(row) {
      var fd = new FormData();
      fd.append('file', row.__file, row.__file.name);
      ['title', 'title_kk', 'doc_date', 'valid_until', 'checklist_key', 'view_mode'].forEach(function (k) {
        var el = q(row, k);
        fd.append(k, el ? el.value : '');
      });
      fd.append('_csrf', cfg.csrf);
      setStatus(row, tpl(T.uploading, { p: 0 }), 'busy');
      return post(page ? page.files_url : cfg.filesUrl, fd, function (p) {
        setStatus(row, tpl(T.uploading, { p: p }), 'busy');
      }).then(function (res) {
        if (res.json && res.json.ok) {
          row.__done = true;
          row.classList.remove('is-error');
          setStatus(row, T.done, 'ok');
          return true;
        }
        var msg = res.json && res.json.message ? res.json.message : T.network;
        row.__error = msg;
        row.classList.add('is-error');
        setStatus(row, tpl(T.error, { msg: msg }), 'error');
        var retry = document.createElement('button');
        retry.type = 'button';
        retry.className = 'btn btn--sm btn--ghost';
        retry.textContent = T.retry;
        retry.setAttribute('data-testid', 'att-row-retry');
        retry.addEventListener('click', function () {
          if (busy) { return; }
          setBusy(true);
          uploadRow(row).then(function () { setBusy(false); afterQueue(); });
        });
        q(row, 'status').appendChild(document.createTextNode(' '));
        q(row, 'status').appendChild(retry);
        return false;
      });
    }

    function setBusy(on) {
      busy = on;
      submit.disabled = on;
      rowsBox.classList.toggle('is-busy', on);
      Array.prototype.forEach.call(root.querySelectorAll('button, input'), function (el) {
        if (el.getAttribute('data-role') === 'file' || el.getAttribute('data-role') === 'remove' || el === multi || el === addBtn) { el.disabled = on; }
      });
    }
    window.addEventListener('beforeunload', function (ev) {
      if (busy) { ev.preventDefault(); ev.returnValue = T.leave; return T.leave; }
    });

    function finish(list) {
      var total = list.length;
      var ok = list.filter(function (r) { return r.__done; }).length;
      var failed = list.filter(function (r) { return !r.__done; }).map(function (r) {
        return (r.__file ? r.__file.name : '') + ': ' + (r.__error || '');
      }).join('; ');
      var fd = new FormData();
      fd.append('total', String(total));
      fd.append('ok', String(ok));
      fd.append('failed', failed);
      fd.append('created', created ? '1' : '0');
      fd.append('_csrf', cfg.csrf);
      busy = true; // пока идёт переход, предупреждение при уходе не нужно
      var url = page ? page.done_url : cfg.doneUrl;
      post(url, fd).then(function (res) {
        busy = false;
        var to = res.json && res.json.url ? res.json.url : (page ? page.edit_url : window.location.href);
        // тот же адрес, меняется только #якорь — браузер не перезагрузит страницу сам
        var same = new URL(to, window.location.href).href.split('#')[0] === window.location.href.split('#')[0];
        window.location.href = to;
        if (same) { window.location.reload(); }
      });
    }

    var current = [];
    function afterQueue() {
      var failed = current.filter(function (r) { return !r.__done; });
      if (!failed.length) { finish(current); return; }
      say(tpl(T.nothing, {}));
      submit.textContent = T.save;
      skip.hidden = false;
    }

    skip.addEventListener('click', function () { if (!busy) { finish(current); } });

    form.addEventListener('submit', function (ev) {
      ev.preventDefault();
      if (busy) { return; }
      say('');
      fieldErr('title', '');
      var list = current.length ? current : active();
      // проверка файлов до отправки
      var bad = false;
      list.forEach(function (row) {
        if (row.__done) { return; }
        var err = validate(row);
        if (err) {
          bad = true;
          row.classList.add('is-error');
          setStatus(row, tpl(T.error, { msg: err }), 'error');
        }
      });
      if (bad) { return; }
      if (cfg.mode === 'edit' && !list.length) { say(T.no_file); return; }
      setBusy(true);
      var ready = Promise.resolve();
      if (cfg.mode === 'create' && !page) {
        submit.textContent = T.creating;
        var fd = new FormData();
        ['title', 'title_kk'].forEach(function (n) { fd.append(n, form.querySelector('[name="' + n + '"]').value); });
        if (form.querySelector('[name="in_menu"]').checked) { fd.append('in_menu', '1'); }
        if (form.querySelector('[name="is_published"]').checked) { fd.append('is_published', '1'); }
        fd.append('_csrf', cfg.csrf);
        ready = post(cfg.createUrl, fd).then(function (res) {
          submit.textContent = submitLabel;
          if (res.json && res.json.ok) { page = res.json; return true; }
          var errs = res.json && res.json.errors ? res.json.errors : null;
          if (errs) { Object.keys(errs).forEach(function (n) { fieldErr(n, errs[n]); }); } else { say(res.json && res.json.message ? res.json.message : T.network); }
          setBusy(false);
          var f = form.querySelector('[aria-invalid="true"]');
          if (f) { f.focus(); }
          return false;
        });
      }
      ready.then(function (ok) {
        if (ok === false) { return; }
        current = list;
        if (!list.length) { setBusy(false); finish([]); return; }
        var chain = Promise.resolve();
        list.forEach(function (row) {
          if (row.__done) { return; }
          chain = chain.then(function () { return uploadRow(row); });
        });
        chain.then(function () { setBusy(false); afterQueue(); });
      });
    });
  }

  // ---------- порядок файлов перетаскиванием (экран страницы аттестации) ----------
  var sec = document.querySelector('[data-att-list]');
  if (!sec) { return; }
  var list = sec.querySelector('[data-role="list"]');
  var dragged = null;
  function rows2() { return Array.prototype.slice.call(list.querySelectorAll('.doc-row[data-id]')); }
  list.addEventListener('dragstart', function (ev) {
    var row = ev.target.closest ? ev.target.closest('.doc-row') : null;
    if (!row) { return; }
    dragged = row;
    row.classList.add('is-dragging');
    if (ev.dataTransfer) { ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', 'doc'); }
  });
  list.addEventListener('dragend', function () {
    if (dragged) { dragged.classList.remove('is-dragging'); }
    rows2().forEach(function (r) { r.classList.remove('is-over'); });
    dragged = null;
  });
  list.addEventListener('dragover', function (ev) {
    if (!dragged) { return; }
    ev.preventDefault();
    var row = ev.target.closest ? ev.target.closest('.doc-row') : null;
    rows2().forEach(function (r) { r.classList.toggle('is-over', r === row && r !== dragged); });
  });
  list.addEventListener('drop', function (ev) {
    if (!dragged) { return; }
    ev.preventDefault();
    var row = ev.target.closest ? ev.target.closest('.doc-row') : null;
    if (!row || row === dragged) { return; }
    var all = rows2();
    if (all.indexOf(dragged) < all.indexOf(row)) { row.after(dragged); } else { row.before(dragged); }
    var fd = new FormData();
    rows2().forEach(function (r) { fd.append('order[]', r.getAttribute('data-id')); });
    fetch(sec.getAttribute('data-order-url'), {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json', 'X-CSRF-Token': sec.getAttribute('data-csrf') }
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { window.alert(sec.getAttribute('data-failed')); } else { location.reload(); }
    }).catch(function () { window.alert(sec.getAttribute('data-failed')); });
  });
})();
