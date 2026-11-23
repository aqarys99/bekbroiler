/* Редактор новости (фича 04): вкладки RU/ҚАЗ, счётчик, бейджи заполненности, «Скопировать с русского», режимы публикации,
   адрес страницы (slug), автосохранение черновика, защита от потери правок, конфликт версий.
   Разметка — admin-views/news/form.php; настройки и тексты — form[data-config]. Текст редактора — editor.js. */
(function () {
  'use strict';
  var form = document.querySelector('[data-news-form]');
  if (!form || form.__nf) { return; }
  form.__nf = true;

  var cfg = {};
  try { cfg = JSON.parse(form.getAttribute('data-config') || '{}'); } catch (e) { cfg = {}; }
  var T = cfg.i18n || {};
  var HDR = { 'X-Requested-With': 'fetch', 'Accept': 'application/json' };

  function q(sel, ctx) { return (ctx || form).querySelector(sel); }
  function qa(sel, ctx) { return Array.prototype.slice.call((ctx || form).querySelectorAll(sel)); }
  function named(n) { return q('[name="' + n + '"]'); }
  function fill(s, vars) { return String(s).replace(/:(\w+)/g, function (m, k) { return vars && k in vars ? vars[k] : m; }); }

  var titleRu = named('title_ru'), excerptRu = named('excerpt_ru');
  var titleKk = named('title_kk'), excerptKk = named('excerpt_kk');
  var idInput = named('id'), versionInput = named('version'), slugAuto = named('slug_auto');
  var slugInput = named('slug'), slugPreview = q('[data-testid="news-slug-preview"]'), slugHint = q('[data-testid="news-slug-hint"]');
  var csrf = (named('_csrf') || {}).value || '';
  var submitBtn = q('[data-testid="news-submit"]');
  var draftBtn = q('[data-testid="news-save-draft"]');
  var statusEl = q('[data-testid="news-autosave-status"]');
  var deleteBtn = q('[data-testid="news-delete"]');
  var deleteForm = document.getElementById('news-delete-form');
  var when = q('[data-when]');

  function editorOf(name) {
    var ta = named(name);
    var root = ta && ta.closest('[data-editor]');
    return root ? root.__editor : null;
  }
  var bodyRu = editorOf('body_ru'), bodyKk = editorOf('body_kk');
  if (!titleRu || !bodyRu || !bodyKk) { return; }

  function syncEditors() {
    qa('[data-editor]').forEach(function (r) {
      var ta = r.querySelector('.editor__source');
      if (ta && r.__editor) { ta.value = r.__editor.getHtml(); }
    });
  }

  // ---------- ошибки у полей ----------
  function controlOf(name) {
    var c = named(name);
    if (c && c.classList.contains('editor__source')) { c = c.closest('[data-editor]').querySelector('.editor__area'); }
    return c;
  }
  function clearIn(w) {
    qa('.field__error', w).forEach(function (n) { n.remove(); });
    qa('[aria-invalid]', w).forEach(function (n) { n.removeAttribute('aria-invalid'); });
  }
  function showError(name, msg) {
    var w = q('[data-field="' + name + '"]');
    if (!w) { return; }
    qa('.field__error', w).forEach(function (n) { n.remove(); });
    var s = document.createElement('span');
    s.className = 'field__error'; s.id = 'err-' + name; s.setAttribute('data-testid', 'news-field-error');
    s.textContent = msg;
    w.appendChild(s);
    var c = controlOf(name);
    if (c) {
      c.setAttribute('aria-invalid', 'true');
      var d = (c.getAttribute('aria-describedby') || '').replace(/\berr-\S+/g, '').trim();
      c.setAttribute('aria-describedby', (d + ' err-' + name).trim());
    }
  }
  function clearAllErrors() {
    clearIn(form);
    var fe = q('[data-testid="form-error"]');
    if (fe) { fe.remove(); }
  }

  // ---------- вкладки ----------
  var tabs = qa('[role="tab"][data-tab]');
  var panels = qa('[data-panel]');
  function showTab(code, focus) {
    form.setAttribute('data-tab', code);
    tabs.forEach(function (b) {
      var on = b.getAttribute('data-tab') === code;
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.tabIndex = on ? 0 : -1;
      if (on && focus) { b.focus(); }
    });
    panels.forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== code; });
  }
  tabs.forEach(function (b, i) {
    b.addEventListener('click', function () { showTab(b.getAttribute('data-tab')); });
    b.addEventListener('keydown', function (ev) {
      var n = null;
      if (ev.key === 'ArrowRight') { n = (i + 1) % tabs.length; }
      else if (ev.key === 'ArrowLeft') { n = (i + tabs.length - 1) % tabs.length; }
      else if (ev.key === 'Home') { n = 0; }
      else if (ev.key === 'End') { n = tabs.length - 1; }
      if (n !== null) { ev.preventDefault(); showTab(tabs[n].getAttribute('data-tab'), true); }
    });
  });
  showTab(cfg.tab === 'kk' ? 'kk' : 'ru');

  // ---------- бейджи заполненности ----------
  var badgeRu = q('[data-testid="news-tab-ru-state"]'), badgeKk = q('[data-testid="news-tab-kk-state"]');
  var kkBar = q('[data-kk-bar]'), kkText = q('[data-testid="news-kk-hint"]'), kkIcon = q('[data-kk-icon]');
  var copyBtn = q('[data-testid="news-copy-from-ru"]');
  function setBadge(el, ok) {
    el.textContent = ok ? T.filled : T.empty;
    el.classList.toggle('lang-badge--ok', ok);
    el.classList.toggle('lang-badge--warn', !ok);
  }
  // разбор и сборка через DOM: одинаковый текст сравнивается одинаково, как бы его ни записал браузер
  var normBox = document.createElement('div');
  function norm(html) { normBox.innerHTML = html; return normBox.innerHTML.trim(); }
  // то же правило, что News::isTranslated(): заполнено и отличается от русского
  function kkFilled() {
    var tk = titleKk.value.trim();
    if (tk === '') { return false; }
    var bk = norm(bodyKk.getHtml()), br = norm(bodyRu.getHtml());
    if (bk === '' && br !== '') { return false; }
    if (tk === titleRu.value.trim() && bk === br) { return false; }
    return true;
  }
  function updateBadges() {
    setBadge(badgeRu, titleRu.value.trim() !== '');
    var ok = kkFilled();
    setBadge(badgeKk, ok);
    if (kkBar) { kkBar.classList.toggle('is-missing', !ok); }
    if (kkText) { kkText.hidden = ok; }
    if (kkIcon) { kkIcon.toggleAttribute('hidden', ok); } // svg не знает свойства hidden
    if (copyBtn) {
      copyBtn.disabled = titleRu.value.trim() === '' && excerptRu.value.trim() === '' && bodyRu.isEmpty();
    }
  }

  // ---------- счётчик ----------
  var counter = q('[data-testid="news-title-ru-counter"]');
  function updateCounter() { counter.textContent = fill(T.counter, { n: titleRu.value.length }); }

  // ---------- состояние «есть несохранённое» ----------
  var changeSeq = 0, dirty = !!q('.field__error') || !!cfg.conflict, leaving = false;

  // ---------- слаг ----------
  var slugTimer = null, slugSeq = 0, initialSlug = slugInput.value;
  function setSlug(v) { slugInput.value = v; slugPreview.textContent = cfg.slugBase + v; updateSlugHint(); }
  function updateSlugHint() {
    var warn = cfg.state === 'published' && slugInput.value !== initialSlug;
    slugHint.textContent = warn ? T.slugWarn : T.slugAuto;
    slugHint.classList.toggle('is-warn', warn);
  }
  function getJson(url) {
    return fetch(url, { credentials: 'same-origin', headers: HDR }).then(function (r) {
      if (!r.ok) { throw new Error('http'); }
      return r.json();
    });
  }
  function suggestSlug(now) {
    if (slugAuto.value !== '1') { return; }
    clearTimeout(slugTimer);
    var run = function () {
      var txt = titleRu.value.trim();
      var my = ++slugSeq;
      if (txt === '') { setSlug(''); return; }
      getJson(cfg.slugUrl + '?text=' + encodeURIComponent(txt) + '&id=' + encodeURIComponent(idInput.value))
        .then(function (j) { if (my === slugSeq && slugAuto.value === '1') { setSlug(j.slug || ''); } })
        .catch(function () { /* адрес назначит сервер при сохранении */ });
    };
    if (now) { run(); } else { slugTimer = setTimeout(run, 400); }
  }
  slugInput.addEventListener('input', function () {
    slugAuto.value = '0';
    slugSeq++;
    slugPreview.textContent = cfg.slugBase + slugInput.value;
    updateSlugHint();
  });
  slugInput.addEventListener('change', function () {
    var v = slugInput.value.trim();
    if (v === '') { slugAuto.value = '1'; suggestSlug(true); return; }
    var my = ++slugSeq;
    getJson(cfg.slugUrl + '?manual=1&text=' + encodeURIComponent(v) + '&id=' + encodeURIComponent(idInput.value))
      .then(function (j) {
        if (my !== slugSeq) { return; }
        setSlug(j.slug || '');
        if (j.taken) { showError('slug', fill(T.slugTaken, { title: j.taken_by || '' })); }
      })
      .catch(function () { /* проверит сервер при сохранении */ });
  });

  // ---------- публикация ----------
  function mode() { var c = q('[name="publish_mode"]:checked'); return c ? c.value : 'now'; }
  function applyMode() {
    var m = mode();
    if (submitBtn && cfg.labels && cfg.labels[m]) { submitBtn.textContent = cfg.labels[m]; }
    if (when) { when.hidden = m !== 'later'; }
    if (draftBtn) { draftBtn.hidden = m === 'draft'; }
    qa('.news-pub__opt').forEach(function (l) { l.classList.toggle('is-on', l.querySelector('input').checked); });
  }

  // ---------- статус автосохранения ----------
  function status(text) { if (statusEl) { statusEl.textContent = text || ''; } }

  var as = { timer: null, inflight: false, last: 0, stopped: !cfg.autosave || !!cfg.conflict, again: false, pending: null };

  function showConflict() {
    if (!q('[data-testid="news-conflict"]')) {
      var tpl = document.getElementById('news-conflict-tpl');
      if (tpl) {
        var node = tpl.content.firstElementChild.cloneNode(true);
        var a = node.querySelector('a');
        if (a) { a.href = cfg.conflictUrl; }
        var bar = q('[data-testid="news-actions"]');
        bar.parentNode.insertBefore(node, bar.nextSibling);
      }
    }
  }

  function schedule() {
    if (as.stopped) { return; }
    clearTimeout(as.timer);
    var wait = Math.max(cfg.delay || 5000, as.last + (cfg.minGap || 15000) - Date.now());
    as.timer = setTimeout(run, wait);
  }

  function applySaved(j) {
    var isNew = idInput.value === '';
    idInput.value = String(j.id);
    versionInput.value = String(j.version);
    if (isNew) {
      form.action = j.update_url;
      try { history.replaceState(null, '', j.edit_url); } catch (e) { /* не критично */ }
      cfg.conflictUrl = j.edit_url;
      cfg.state = 'draft';
      if (deleteForm) { deleteForm.action = j.update_url + '/delete'; updDeleteConfirm(); }
      if (deleteBtn) { deleteBtn.hidden = false; }
    }
    if (slugAuto.value === '1' && j.slug) { setSlug(j.slug); initialSlug = j.slug; }
  }

  function tabOfError(errors) {
    var keys = Object.keys(errors || {});
    for (var i = 0; i < keys.length; i++) {
      if (/_kk$/.test(keys[i])) { return 'kk'; }
      if (/_ru$/.test(keys[i])) { return 'ru'; }
    }
    return null;
  }

  function run() {
    if (as.stopped) { return; }
    if (titleRu.value.trim() === '') { status(T.needTitle); return; }
    if (as.inflight) { as.again = true; return; }
    if (navigator.onLine === false) { status(T.failed); return; }
    syncEditors();
    as.inflight = true; as.last = Date.now();
    var sent = changeSeq;
    status(T.saving);
    fetch(cfg.autosaveUrl, {
      method: 'POST', body: new FormData(form), credentials: 'same-origin',
      headers: { 'X-CSRF-Token': csrf, 'X-Requested-With': 'fetch', 'Accept': 'application/json' }
    })
      .then(function (r) {
        return r.text().then(function (txt) {
          var j = null;
          try { j = JSON.parse(txt); } catch (e) { j = null; }
          return { s: r.status, j: j };
        });
      })
      .then(function (res) {
        var j = res.j || {};
        if (res.s === 200 && j.ok) {
          applySaved(j);
          clearAllErrors();
          if (changeSeq === sent) { dirty = false; }
          status(fill(T.saved, { time: j.saved_at || '' }));
        } else if (res.s === 422 && j.code === 'validation') {
          clearAllErrors();
          Object.keys(j.errors || {}).forEach(function (k) { showError(k, j.errors[k]); });
          var tab = tabOfError(j.errors);
          if (tab) { showTab(tab); }
          status(T.invalid);
        } else if (res.s === 409 && j.code === 'conflict') {
          as.stopped = true; clearTimeout(as.timer);
          showConflict(); status('');
        } else if (res.s === 409) {
          as.stopped = true; clearTimeout(as.timer);
          status(T.notDraft);
        } else if (res.s === 404) {
          as.stopped = true; clearTimeout(as.timer);
          status(T.failed);
        } else if (res.s === 401) {
          status(T.session);
        } else {
          status(T.failed);
        }
      })
      .catch(function () { status(T.failed); })
      .then(function () {
        as.inflight = false;
        if (as.pending) {
          var p = as.pending; as.pending = null;
          form.requestSubmit(p);
          return;
        }
        if (!as.stopped && (as.again || (dirty && changeSeq !== sent))) { as.again = false; schedule(); }
      });
  }

  function markDirty() {
    changeSeq++;
    dirty = true;
    if (as.stopped) { return; }
    if (titleRu.value.trim() === '') { clearTimeout(as.timer); status(T.needTitle); return; }
    status(T.dirty);
    schedule();
  }

  // ---------- удаление ----------
  function updDeleteConfirm() {
    if (deleteForm) { deleteForm.setAttribute('data-confirm', fill(cfg.deleteConfirm, { title: titleRu.value })); }
  }

  // ---------- события формы ----------
  function onChange(ev) {
    var t = ev.target;
    // у однострочных полей событие input уже учтено; change на blur не считаем правкой
    if (ev.type === 'change' && (t.tagName === 'TEXTAREA' || (t.tagName === 'INPUT' && /^(text|date|time)$/.test(t.type)))) { return; }
    var w = t.closest && t.closest('[data-field]');
    if (w) { clearIn(w); }
    if (t === slugInput) { markDirty(); return; }
    if (t === titleRu) { updateCounter(); suggestSlug(false); updDeleteConfirm(); }
    if (t.name === 'publish_mode') { applyMode(); }
    updateBadges();
    markDirty();
  }
  form.addEventListener('input', onChange);
  form.addEventListener('change', onChange);

  // Enter в однострочных полях не должен отправлять форму (первой кнопкой стоит «Предпросмотр»)
  form.addEventListener('keydown', function (ev) {
    var t = ev.target;
    if (ev.key === 'Enter' && t.tagName === 'INPUT' && /^(text|date|time)$/.test(t.type)) { ev.preventDefault(); }
  });

  if (copyBtn) {
    copyBtn.addEventListener('click', function () {
      var any = titleKk.value.trim() !== '' || excerptKk.value.trim() !== '' || !bodyKk.isEmpty();
      if (any && !window.confirm(T.copyConfirm)) { return; }
      titleKk.value = titleRu.value;
      excerptKk.value = excerptRu.value;
      bodyKk.setHtml(bodyRu.getHtml());
      ['title_kk', 'excerpt_kk', 'body_kk'].forEach(function (n) { var w = q('[data-field="' + n + '"]'); if (w) { clearIn(w); } });
      updateBadges();
      markDirty();
    });
  }

  // отправка: предпросмотр уходит в новую вкладку, форма остаётся рабочей; обычная отправка ждёт текущее автосохранение
  form.addEventListener('submit', function (ev) {
    var sub = ev.submitter;
    syncEditors();
    if (sub && sub.getAttribute('data-testid') === 'news-preview') { ev.stopImmediatePropagation(); return; }
    if (as.inflight) {
      ev.preventDefault(); ev.stopImmediatePropagation();
      as.pending = sub || null;
      return;
    }
    clearTimeout(as.timer);
    as.stopped = true;
    leaving = true;
  }, true);
  if (deleteForm) { deleteForm.addEventListener('submit', function () { leaving = true; }); }

  window.addEventListener('beforeunload', function (ev) {
    if (dirty && !leaving) { ev.preventDefault(); ev.returnValue = ''; return ''; }
    return undefined;
  });
  window.addEventListener('pageshow', function (ev) {
    if (ev.persisted) { leaving = false; as.stopped = !cfg.autosave || !!cfg.conflict; }
  });
  window.addEventListener('online', function () { if (dirty && !as.stopped) { schedule(); } });

  // липкая панель под липкой шапкой на узких экранах
  var topbar = document.querySelector('.topbar');
  function setTop() {
    var narrow = window.matchMedia('(max-width: 899px)').matches;
    form.style.setProperty('--topbar-h', (narrow && topbar ? topbar.offsetHeight : 0) + 'px');
  }
  window.addEventListener('resize', setTop);
  setTop();

  applyMode();
  updateCounter();
  updateBadges();
  updateSlugHint();
  updDeleteConfirm();
})();
