/* Поле «Выбрать из медиатеки / загрузить». Подключение: контейнер [data-media-picker] (partials/media-field.php).
   data-name, data-multiple, data-sortable, data-picker-url, data-url/-max-bytes/-max-side/-quality/-csrf, data-i18n, data-state.
   Программный вызов окна (фича 04, редактор текста):
     window.AdminMediaPicker.open({ multiple: true, selected: [id…], onConfirm(items), onClose(applied) })
   Настройки (адрес, загрузчик, тексты) берутся из первого поля [data-media-picker] на странице. */
(function () {
  'use strict';
  var U = window.MediaUploader;
  if (!U) { return; }
  var el = U.el, tpl = U.tpl;
  var uid = 0;
  var modalOpen = false;
  var defaults = null; // {t, cfg, pickerUrl} первого поля на странице

  function thumbEl(item, cls) {
    var box = el('span', cls);
    var pic = el('picture');
    if (item.thumb_webp) { var s = el('source', null, { type: 'image/webp' }); s.srcset = item.thumb_webp; pic.appendChild(s); }
    var img = el('img', null, { alt: item.alt || '', loading: 'lazy' });
    img.src = item.thumb_jpg || item.thumb_webp || '';
    pic.appendChild(img); box.appendChild(pic);
    return box;
  }

  function pickItem(c) {
    return { id: c.id, deleted: false, original_name: c.original_name, alt: c.alt, width: c.width, height: c.height, thumb_webp: c.thumb_webp, thumb_jpg: c.thumb_jpg };
  }

  /* ---------- окно выбора ----------
     o: {t, cfg, pickerUrl, multiple, selected:[элементы state], onConfirm(items), onClose(applied)} */
  function openModal(o) {
    if (modalOpen) { return; }
    modalOpen = true;
    var t = o.t, cfg = o.cfg, pickerUrl = o.pickerUrl, multiple = !!o.multiple;
    var id = ++uid;
    var initial = o.selected || [];
    var sel = initial.filter(function (s) { return !s.deleted; }).map(function (s) { return s.id; });
    var cache = {};
    initial.forEach(function (s) { if (!s.deleted) { cache[s.id] = s; } });
    var q = '', page = 0, pages = 1, loading = false, reqNo = 0, failedPage = null, uploading = false, timer = null;
    var uploadMounted = false;

    var wrap = el('div', 'modal-wrap');
    var dlg = el('div', 'modal', { role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'mp-title-' + id, 'data-testid': 'media-picker-modal' });
    var head = el('div', 'modal__head');
    var h2 = el('h2', null, { id: 'mp-title-' + id }); h2.textContent = t.pTitle;
    var closeBtn = el('button', 'modal__close', { type: 'button', 'aria-label': t.pClose, 'data-testid': 'media-picker-close' });
    closeBtn.textContent = '×';
    head.appendChild(h2); head.appendChild(closeBtn);

    var tabs = el('div', 'tabs', { role: 'tablist' });
    var tabLib = el('button', 'tab', { type: 'button', role: 'tab', id: 'mp-tab-lib-' + id, 'aria-controls': 'mp-lib-' + id, 'aria-selected': 'true', 'data-testid': 'media-picker-tab-library' });
    var tabUp = el('button', 'tab', { type: 'button', role: 'tab', id: 'mp-tab-up-' + id, 'aria-controls': 'mp-up-' + id, 'aria-selected': 'false', tabindex: '-1', 'data-testid': 'media-picker-tab-upload' });
    tabLib.textContent = t.pTabLibrary; tabUp.textContent = t.pTabUpload;
    tabs.appendChild(tabLib); tabs.appendChild(tabUp);

    var libPanel = el('div', 'modal__body', { role: 'tabpanel', id: 'mp-lib-' + id, 'aria-labelledby': 'mp-tab-lib-' + id });
    var search = el('input', 'input', { type: 'search', maxlength: '100', 'aria-label': t.pSearch, placeholder: t.pSearch, 'data-testid': 'media-picker-search' });
    var grid = el('ul', 'pgrid');
    var status = el('p', 'modal__status'); status.hidden = true;
    var goUpload = el('button', 'btn btn--md btn--ghost btn--more', { type: 'button' });
    goUpload.textContent = t.pGoUpload; goUpload.hidden = true;
    var more = el('button', 'btn btn--md btn--ghost btn--more', { type: 'button', 'data-testid': 'media-picker-more' });
    more.textContent = t.pMore; more.hidden = true;
    libPanel.appendChild(search); libPanel.appendChild(grid); libPanel.appendChild(status); libPanel.appendChild(goUpload); libPanel.appendChild(more);

    var upPanel = el('div', 'modal__body', { role: 'tabpanel', id: 'mp-up-' + id, 'aria-labelledby': 'mp-tab-up-' + id });
    upPanel.hidden = true;

    var foot = el('div', 'modal__foot');
    var count = el('span', 'modal__count', { 'aria-live': 'polite' });
    var cancel = el('button', 'btn btn--md btn--ghost', { type: 'button', 'data-testid': 'media-picker-cancel' }); cancel.textContent = t.pCancel;
    var confirm = el('button', 'btn btn--md', { type: 'button', 'data-testid': 'media-picker-confirm' });
    foot.appendChild(count); foot.appendChild(cancel); foot.appendChild(confirm);

    dlg.appendChild(head); dlg.appendChild(tabs); dlg.appendChild(libPanel); dlg.appendChild(upPanel); dlg.appendChild(foot);
    wrap.appendChild(dlg);
    document.body.appendChild(wrap);
    document.body.classList.add('modal-open');

    function updateFooter() {
      confirm.textContent = multiple && sel.length ? tpl(t.pConfirmN, { n: sel.length }) : t.pConfirm;
      confirm.disabled = !sel.length || uploading;
      count.textContent = multiple && sel.length ? tpl(t.pSelected, { n: sel.length }) : '';
    }
    function markSelected() {
      Array.prototype.forEach.call(grid.querySelectorAll('[data-id]'), function (b) {
        b.setAttribute('aria-pressed', sel.indexOf(parseInt(b.getAttribute('data-id'), 10)) !== -1 ? 'true' : 'false');
      });
    }
    function select(itemId) {
      var i = sel.indexOf(itemId);
      if (!multiple) { sel = [itemId]; }
      else if (i === -1) { sel.push(itemId); }
      else { sel.splice(i, 1); }
      markSelected(); updateFooter();
    }
    function tile(item) {
      var li = el('li');
      var b = el('button', 'pgrid__item', { type: 'button', 'aria-pressed': sel.indexOf(item.id) !== -1 ? 'true' : 'false', 'data-id': String(item.id), 'data-testid': 'media-picker-item', title: item.original_name });
      b.appendChild(thumbEl(item, 'media-item__img'));
      var sr = el('span', 'media-item__name'); sr.textContent = item.original_name;
      sr.style.cssText = 'position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0)';
      b.appendChild(sr);
      b.addEventListener('click', function () { select(item.id); });
      li.appendChild(b);
      return li;
    }
    function hasTile(itemId) { return !!grid.querySelector('[data-id="' + itemId + '"]'); }
    function syncStatus() {
      var emptyNow = !grid.children.length && !loading && failedPage === null;
      goUpload.hidden = !emptyNow;
      if (emptyNow) { status.hidden = false; status.textContent = t.pEmpty; }
      else if (failedPage !== null) { status.hidden = false; status.textContent = t.pLoadFailed; }
      else { status.hidden = true; }
      more.hidden = !(failedPage !== null || (page < pages && grid.children.length));
    }
    function load(p) {
      var n = ++reqNo;
      loading = true; more.disabled = true;
      var url = pickerUrl + (pickerUrl.indexOf('?') === -1 ? '?' : '&') + 'q=' + encodeURIComponent(q) + '&page=' + p;
      fetch(url, { credentials: 'same-origin', headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json' } })
        .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.json(); })
        .then(function (j) {
          if (n !== reqNo) { return; } // устаревший ответ
          if (p === 1) { grid.textContent = ''; }
          (j.items || []).forEach(function (it) {
            cache[it.id] = cache[it.id] || it;
            if (!hasTile(it.id)) { grid.appendChild(tile(it)); }
          });
          page = j.page || p; pages = j.pages || 1; failedPage = null;
        })
        .catch(function () { if (n === reqNo) { failedPage = p; } })
        .then(function () {
          if (n !== reqNo) { return; }
          loading = false; more.disabled = false; syncStatus();
        });
    }
    more.addEventListener('click', function () {
      if (loading) { return; }
      load(failedPage !== null ? failedPage : page + 1);
    });
    search.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        q = search.value.trim(); page = 0; pages = 1; failedPage = null;
        grid.textContent = '';
        load(1);
      }, 300);
    });

    function showTab(which, focus) {
      var lib = which === 'lib';
      tabLib.setAttribute('aria-selected', lib ? 'true' : 'false'); tabLib.tabIndex = lib ? 0 : -1;
      tabUp.setAttribute('aria-selected', lib ? 'false' : 'true'); tabUp.tabIndex = lib ? -1 : 0;
      libPanel.hidden = !lib; upPanel.hidden = lib;
      if (!lib && !uploadMounted) {
        uploadMounted = true;
        U.mountPanel(upPanel, {
          cfg: cfg, i18n: t, dropzoneTestid: 'media-picker-dropzone',
          onBusy: function (b) { uploading = b; updateFooter(); },
          onDone: function (m) {
            cache[m.id] = m;
            if (!multiple) { sel = [m.id]; } else if (sel.indexOf(m.id) === -1) { sel.push(m.id); }
            if (!hasTile(m.id)) { grid.insertBefore(tile(m), grid.firstChild); }
            markSelected(); syncStatus(); updateFooter();
          }
        });
      }
      if (focus) { (lib ? tabLib : tabUp).focus(); }
    }
    tabLib.addEventListener('click', function () { showTab('lib'); });
    tabUp.addEventListener('click', function () { showTab('up'); });
    goUpload.addEventListener('click', function () { showTab('up', true); });
    tabs.addEventListener('keydown', function (ev) {
      if (ev.key === 'ArrowRight' || ev.key === 'ArrowLeft' || ev.key === 'Home' || ev.key === 'End') {
        ev.preventDefault();
        var toUp = ev.key === 'ArrowRight' || ev.key === 'End';
        showTab(toUp ? 'up' : 'lib', true);
      }
    });

    var closed = false;
    function close(apply) {
      if (closed) { return; }
      closed = true;
      var items = null;
      if (apply) {
        var prev = {};
        initial.forEach(function (s) { prev[s.id] = s; });
        items = sel.map(function (sid) { return pickItem(cache[sid] || prev[sid]); });
      }
      clearTimeout(timer);
      reqNo++;
      document.removeEventListener('keydown', onKey, true);
      document.body.classList.remove('modal-open');
      wrap.remove();
      modalOpen = false;
      if (items && o.onConfirm) { o.onConfirm(items); }
      if (o.onClose) { o.onClose(!!apply); }
    }
    function onKey(ev) {
      if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); close(false); return; }
      if (ev.key !== 'Tab') { return; }
      var f = Array.prototype.filter.call(dlg.querySelectorAll('a[href], button, input, select, textarea, [tabindex]'), function (n) {
        return !n.disabled && n.tabIndex >= 0 && n.type !== 'hidden' && !n.closest('[hidden]') && n.offsetParent !== null;
      });
      if (!f.length) { ev.preventDefault(); return; }
      var first = f[0], last = f[f.length - 1];
      if (!dlg.contains(document.activeElement)) { ev.preventDefault(); first.focus(); }
      else if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
      else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
    }
    document.addEventListener('keydown', onKey, true);
    closeBtn.addEventListener('click', function () { close(false); });
    cancel.addEventListener('click', function () { close(false); });
    confirm.addEventListener('click', function () { if (!confirm.disabled) { close(true); } });
    wrap.addEventListener('mousedown', function (ev) { if (ev.target === wrap) { close(false); } });

    updateFooter();
    load(1);
    search.focus();
  }

  // ---------- программный вызов ----------
  window.AdminMediaPicker = {
    /** @param {{multiple?:boolean, selected?:Array, onConfirm?:Function, onClose?:Function}} o */
    open: function (o) {
      o = o || {};
      if (!defaults) { return false; }
      var selected = (o.selected || []).map(function (x) { return typeof x === 'object' ? x : { id: x, deleted: false }; });
      openModal({
        t: defaults.t, cfg: defaults.cfg, pickerUrl: defaults.pickerUrl,
        multiple: !!o.multiple, selected: selected, onConfirm: o.onConfirm, onClose: o.onClose
      });
      return true;
    }
  };

  // ---------- поле ----------
  function init(root) {
    if (root.__mp) { return; }
    root.__mp = true;
    var t = {};
    try { t = JSON.parse(root.getAttribute('data-i18n') || '{}'); } catch (e) { t = {}; }
    var state = [];
    try { state = JSON.parse(root.getAttribute('data-state') || '[]'); } catch (e) { state = []; }
    var name = root.getAttribute('data-name');
    var multiple = root.getAttribute('data-multiple') === '1';
    var sortable = multiple && root.getAttribute('data-sortable') === '1';
    var pickerUrl = root.getAttribute('data-picker-url');
    var cfg = U.readCfg(root);
    var preview = root.querySelector('[data-role="preview"]');
    var inputs = root.querySelector('[data-role="inputs"]');
    var openBtn = root.querySelector('[data-role="open"]');
    var dragFrom = -1;
    if (!defaults) { defaults = { t: t, cfg: cfg, pickerUrl: pickerUrl }; }

    function changed() { root.dispatchEvent(new Event('change', { bubbles: true })); }

    function move(from, to, focusRole) {
      if (to < 0 || to >= state.length || from === to) { return; }
      var it = state.splice(from, 1)[0];
      state.splice(to, 0, it);
      render();
      changed();
      if (focusRole) {
        var items = preview.querySelectorAll('.mpick__item');
        var b = items[to] && items[to].querySelector('[data-testid="media-picker-move-' + focusRole + '"]');
        if (b && b.disabled) { b = items[to].querySelector('[data-testid="media-picker-move-' + (focusRole === 'prev' ? 'next' : 'prev') + '"]'); }
        if (b) { b.focus(); }
      }
    }

    function moveBtn(role, label, glyph) {
      var b = el('button', 'mpick__move', { type: 'button', 'data-testid': 'media-picker-move-' + role, 'aria-label': label });
      var g = el('span'); g.textContent = glyph; g.setAttribute('aria-hidden', 'true');
      b.appendChild(g);
      return b;
    }

    function render() {
      preview.textContent = '';
      inputs.textContent = '';
      if (!multiple) {
        var one = el('input', null, { type: 'hidden', name: name });
        one.value = state.length ? String(state[0].id) : '';
        inputs.appendChild(one);
      } else {
        state.forEach(function (s) {
          var h = el('input', null, { type: 'hidden', name: name + '[]' });
          h.value = String(s.id);
          inputs.appendChild(h);
        });
      }
      if (!state.length && !sortable) {
        var none = el('span', 'mpick__none'); none.textContent = t.pNone;
        preview.appendChild(none);
        return;
      }
      state.forEach(function (s, idx) {
        var item = el('div', 'mpick__item');
        if (s.deleted) {
          var d = el('span', 'mpick__thumb mpick__thumb--deleted'); d.textContent = t.pDeleted;
          item.appendChild(d);
        } else {
          item.appendChild(thumbEl(s, 'mpick__thumb'));
        }
        var rm;
        if (multiple) {
          rm = el('button', 'mpick__x', { type: 'button', 'data-testid': 'media-picker-clear', 'aria-label': t.pClear + ': ' + (s.original_name || '') });
          var x = el('span'); x.textContent = '×'; x.setAttribute('aria-hidden', 'true'); rm.appendChild(x);
        } else {
          rm = el('button', 'btn--text', { type: 'button', 'data-testid': 'media-picker-clear' });
          rm.textContent = t.pClear;
        }
        rm.addEventListener('click', function () {
          state.splice(idx, 1);
          render();
          changed();
          openBtn.focus();
        });
        item.appendChild(rm);
        if (sortable) {
          item.setAttribute('draggable', 'true');
          item.setAttribute('data-testid', 'media-picker-thumb');
          Array.prototype.forEach.call(item.querySelectorAll('img'), function (im) { im.draggable = false; });
          var bar = el('span', 'mpick__moves');
          var prev = moveBtn('prev', t.pMovePrev, '←');
          var next = moveBtn('next', t.pMoveNext, '→');
          prev.disabled = idx === 0;
          next.disabled = idx === state.length - 1;
          prev.addEventListener('click', function () { move(idx, idx - 1, 'prev'); });
          next.addEventListener('click', function () { move(idx, idx + 1, 'next'); });
          bar.appendChild(prev); bar.appendChild(next);
          item.appendChild(bar);
          item.addEventListener('dragstart', function (ev) {
            dragFrom = idx;
            item.classList.add('is-dragging');
            if (ev.dataTransfer) { ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', String(idx)); }
          });
          item.addEventListener('dragend', function () {
            dragFrom = -1;
            item.classList.remove('is-dragging');
            Array.prototype.forEach.call(preview.querySelectorAll('.is-over'), function (n) { n.classList.remove('is-over'); });
          });
          item.addEventListener('dragover', function (ev) {
            if (dragFrom === -1) { return; }
            ev.preventDefault();
            if (ev.dataTransfer) { ev.dataTransfer.dropEffect = 'move'; }
            item.classList.add('is-over');
          });
          item.addEventListener('dragleave', function () { item.classList.remove('is-over'); });
          item.addEventListener('drop', function (ev) {
            if (dragFrom === -1) { return; }
            ev.preventDefault();
            var from = dragFrom;
            dragFrom = -1;
            move(from, idx, null);
          });
        }
        preview.appendChild(item);
      });
      if (sortable) { preview.appendChild(openBtn); } // плитка «Добавить фото» последней в сетке
    }

    function open() {
      openModal({
        t: t, cfg: cfg, pickerUrl: pickerUrl, multiple: multiple, selected: state,
        onConfirm: function (items) { state = items; render(); changed(); },
        onClose: function () { openBtn.focus(); }
      });
    }

    openBtn.addEventListener('click', open);
    render();
  }

  document.querySelectorAll('[data-media-picker]').forEach(init);
})();
