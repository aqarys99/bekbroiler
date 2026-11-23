/* Страница альбома: загрузка в альбом (uploader.js), «Добавить из медиатеки», порядок перетаскиванием. */
(function () {
  'use strict';
  var root = document.querySelector('[data-gallery]');
  var U = window.MediaUploader;
  if (!root || !U) { return; }

  var list = root.querySelector('[data-role="photos"]');
  var empty = root.querySelector('[data-role="empty"]');
  var title = root.querySelector('[data-role="count-title"]');
  var csrf = root.getAttribute('data-csrf');
  var i18n = {};
  try { i18n = JSON.parse(root.getAttribute('data-i18n') || '{}'); } catch (e) { i18n = {}; }
  var $ = function (r) { return root.querySelector('[data-role="' + r + '"]'); };

  function tiles() { return Array.prototype.slice.call(list.querySelectorAll('.gphoto')); }

  function refresh() {
    var all = tiles();
    all.forEach(function (li, i) {
      var l = li.querySelector('[data-role="left"]'), r = li.querySelector('[data-role="right"]');
      if (l) { l.disabled = i === 0; }
      if (r) { r.disabled = i === all.length - 1; }
    });
    list.hidden = all.length === 0;
    empty.hidden = all.length !== 0;
    title.textContent = title.getAttribute('data-tpl').replace(':n', String(all.length));
  }

  function post(url, params) {
    var fd = new FormData();
    params.forEach(function (p) { fd.append(p[0], p[1]); });
    return fetch(url, {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json', 'X-CSRF-Token': csrf }
    }).then(function (r) { return r.json().then(function (j) { return { status: r.status, json: j }; }); });
  }

  // Добавление в альбом идёт строго по очереди, чтобы порядок совпадал с порядком загрузки
  var chain = Promise.resolve();
  var pendingAdds = 0;
  function addToAlbum(ids) {
    pendingAdds++;
    chain = chain.then(function () {
      return post(root.getAttribute('data-photos-url'), ids.map(function (id) { return ['media_ids[]', String(id)]; }));
    }).then(function (res) {
      if (res.json && res.json.ok) {
        res.json.tiles.forEach(function (t) {
          list.insertAdjacentHTML('beforeend', t.html);
        });
        refresh();
      } else {
        window.alert((res.json && res.json.message) || i18n.err_network);
      }
    }).catch(function () { window.alert(i18n.err_network); }).then(function () { pendingAdds--; });
    return chain;
  }

  // загрузка
  var up = U.create({
    cfg: U.readCfg(root), i18n: i18n,
    queue: { root: $('queue'), summary: $('summary'), saved: $('saved'), clear: $('clear'), list: $('list') },
    onDone: function (m) { addToAlbum([m.id]); }
  });
  U.bindDropzone($('dropzone'), $('input'), up);

  // из медиатеки
  $('library').addEventListener('click', function () {
    if (!window.AdminMediaPicker) { return; }
    var existing = tiles().map(function (li) { return parseInt(li.getAttribute('data-media-id'), 10); });
    window.AdminMediaPicker.open({
      multiple: true,
      selected: [],
      onConfirm: function (items) {
        var ids = items.map(function (i) { return i.id; }).filter(function (id) { return existing.indexOf(id) === -1; });
        if (ids.length) { addToAlbum(ids); }
      },
      onClose: function () { $('library').focus(); }
    });
  });

  // подтверждение «Убрать из альбома»
  list.addEventListener('click', function (ev) {
    var b = ev.target.closest ? ev.target.closest('[data-confirm-click]') : null;
    if (b && !window.confirm(b.getAttribute('data-confirm-click'))) { ev.preventDefault(); }
  });

  // порядок перетаскиванием
  var dragged = null;
  list.addEventListener('dragstart', function (ev) {
    var li = ev.target.closest ? ev.target.closest('.gphoto') : null;
    if (!li || ev.target.tagName === 'INPUT') { return; }
    dragged = li;
    li.classList.add('is-dragging');
    if (ev.dataTransfer) { ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', 'gphoto'); }
  });
  list.addEventListener('dragend', function () {
    if (dragged) { dragged.classList.remove('is-dragging'); }
    tiles().forEach(function (t) { t.classList.remove('is-over'); });
    dragged = null;
  });
  list.addEventListener('dragover', function (ev) {
    if (!dragged) { return; }
    ev.preventDefault();
    var li = ev.target.closest ? ev.target.closest('.gphoto') : null;
    tiles().forEach(function (t) { t.classList.toggle('is-over', t === li && t !== dragged); });
  });
  list.addEventListener('drop', function (ev) {
    if (!dragged) { return; }
    ev.preventDefault();
    var li = ev.target.closest ? ev.target.closest('.gphoto') : null;
    if (!li || li === dragged) { return; }
    var all = tiles();
    if (all.indexOf(dragged) < all.indexOf(li)) { li.after(dragged); } else { li.before(dragged); }
    refresh();
    var order = tiles().map(function (t) { return ['order[]', t.getAttribute('data-media-id')]; });
    post(root.getAttribute('data-order-url'), order).then(function (res) {
      if (!res.json || !res.json.ok) { window.alert(root.getAttribute('data-reorder-failed')); }
    }).catch(function () { window.alert(root.getAttribute('data-reorder-failed')); });
  });

  refresh();
})();
