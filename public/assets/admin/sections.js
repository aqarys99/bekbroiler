/* Режим дня: строки «время — занятие» (добавить, удалить, порядок). Разметка — admin-views/pages/form.php. */
(function () {
  'use strict';
  var root = document.querySelector('[data-schedule]');
  if (!root) { return; }
  var rows = root.querySelector('[data-role="rows"]');
  var tr = {};
  try { tr = JSON.parse(root.getAttribute('data-i18n') || '{}'); } catch (e) { tr = {}; }

  function btn(label, testid, text, fn) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = 'icon-btn';
    b.setAttribute('aria-label', label);
    b.title = label;
    b.setAttribute('data-testid', testid);
    b.textContent = text;
    b.addEventListener('click', fn);
    return b;
  }
  function decorate(row) {
    var box = row.querySelector('[data-role="btns"]');
    box.textContent = '';
    box.appendChild(btn(tr.up || '', 'pages-schedule-up', '↑', function () {
      var p = row.previousElementSibling; if (p) { rows.insertBefore(row, p); refresh(); }
    }));
    box.appendChild(btn(tr.down || '', 'pages-schedule-down', '↓', function () {
      var n = row.nextElementSibling; if (n) { rows.insertBefore(n, row); refresh(); }
    }));
    var d = btn(tr.del || '', 'pages-schedule-delete', '✕', function () { row.remove(); refresh(); });
    d.className += ' icon-btn--danger';
    box.appendChild(d);
  }
  function refresh() {
    var all = rows.querySelectorAll('[data-role="row"]');
    all.forEach(function (r, i) {
      r.querySelector('[data-testid="pages-schedule-up"]').disabled = i === 0;
      r.querySelector('[data-testid="pages-schedule-down"]').disabled = i === all.length - 1;
    });
  }
  function bind() { rows.querySelectorAll('[data-role="row"]').forEach(decorate); refresh(); }

  root.querySelector('[data-role="add"]').addEventListener('click', function () {
    var tpl = root.querySelector('[data-role="tpl"]');
    var row = tpl.content.firstElementChild.cloneNode(true);
    rows.appendChild(row);
    decorate(row);
    refresh();
    row.querySelector('input').focus();
  });
  bind();
})();
