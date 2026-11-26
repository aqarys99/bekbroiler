/* Экран страницы: адрес по названию, блок «Документы» (форма, порядок перетаскиванием). */
(function () {
  'use strict';

  // адрес (slug) из названия, пока его не правили вручную
  var form = document.querySelector('[data-page-form]');
  if (form) {
    var title = form.querySelector('[name="title"]');
    var slug = form.querySelector('[name="slug"]');
    var auto = form.getAttribute('data-slug-auto') === '1';
    var timer = null, seq = 0;
    slug.addEventListener('input', function () { auto = false; seq++; });
    title.addEventListener('input', function () {
      if (!auto) { return; }
      clearTimeout(timer);
      timer = setTimeout(function () {
        var my = ++seq;
        fetch(form.getAttribute('data-slug-url') + '?title=' + encodeURIComponent(title.value) + '&id=' + encodeURIComponent(form.getAttribute('data-page-id')),
          { credentials: 'same-origin', headers: { 'Accept': 'application/json', 'X-Requested-With': 'fetch' } })
          .then(function (r) { return r.json(); })
          .then(function (j) { if (my === seq && auto) { slug.value = j.slug || ''; } })
          .catch(function () {});
      }, 300);
    });
  }

  var root = document.querySelector('[data-page-docs]');
  if (!root) { return; }
  var panel = root.querySelector('#page-docs-form');
  var toggle = root.querySelector('[data-role="toggle"]');
  var cancel = root.querySelector('[data-role="cancel"]');
  function show(on) {
    panel.hidden = !on;
    toggle.setAttribute('aria-expanded', on ? 'true' : 'false');
    if (on) { var f = panel.querySelector('input[type="file"]'); panel.scrollIntoView({ block: 'nearest' }); if (f) { f.focus(); } }
  }
  toggle.addEventListener('click', function () { show(panel.hidden); });
  cancel.addEventListener('click', function () { show(false); });
  if (!panel.hidden && location.hash === '#page-docs-form') { panel.scrollIntoView({ block: 'nearest' }); }

  // «Открывать как» — только для PDF
  var file = panel.querySelector('input[type="file"]');
  var vm = panel.querySelector('[data-viewmode-field]');
  function syncMode() {
    var f = file.files && file.files[0];
    vm.hidden = !(f && /\.pdf$/i.test(f.name));
  }
  file.addEventListener('change', syncMode);
  syncMode();

  // порядок перетаскиванием
  var list = root.querySelector('[data-role="list"]');
  var dragged = null;
  function rows() { return Array.prototype.slice.call(list.querySelectorAll('.doc-row[data-id]')); }
  list.addEventListener('dragstart', function (ev) {
    var row = ev.target.closest ? ev.target.closest('.doc-row') : null;
    if (!row) { return; }
    dragged = row;
    row.classList.add('is-dragging');
    if (ev.dataTransfer) { ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', 'doc'); }
  });
  list.addEventListener('dragend', function () {
    if (dragged) { dragged.classList.remove('is-dragging'); }
    rows().forEach(function (r) { r.classList.remove('is-over'); });
    dragged = null;
  });
  list.addEventListener('dragover', function (ev) {
    if (!dragged) { return; }
    ev.preventDefault();
    var row = ev.target.closest ? ev.target.closest('.doc-row') : null;
    rows().forEach(function (r) { r.classList.toggle('is-over', r === row && r !== dragged); });
  });
  list.addEventListener('drop', function (ev) {
    if (!dragged) { return; }
    ev.preventDefault();
    var row = ev.target.closest ? ev.target.closest('.doc-row') : null;
    if (!row || row === dragged) { return; }
    var all = rows();
    if (all.indexOf(dragged) < all.indexOf(row)) { row.after(dragged); } else { row.before(dragged); }
    var fd = new FormData();
    rows().forEach(function (r) { fd.append('order[]', r.getAttribute('data-id')); });
    fetch(root.getAttribute('data-order-url'), {
      method: 'POST', body: fd, credentials: 'same-origin',
      headers: { 'X-Requested-With': 'fetch', 'Accept': 'application/json', 'X-CSRF-Token': root.getAttribute('data-csrf') }
    }).then(function (r) { return r.json(); }).then(function (j) {
      if (!j.ok) { window.alert(root.getAttribute('data-failed')); } else { location.reload(); }
    }).catch(function () { window.alert(root.getAttribute('data-failed')); });
  });
})();
