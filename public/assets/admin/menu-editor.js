/* Редактор меню сайта: дерево до двух уровней, перетаскивание (HTML5 DnD) и кнопки ↑ ↓ → ←. Сохраняется одним POST с JSON. */
(function () {
  'use strict';
  var root = document.querySelector('[data-menu-editor]');
  if (!root) { return; }
  var q = function (sel) { return root.querySelector(sel); };
  var read = function (a) { try { return JSON.parse(root.getAttribute(a) || 'null'); } catch (e) { return null; } };
  var T = read('data-i18n') || {};
  var pages = read('data-pages') || [];
  var sections = read('data-sections') || [];
  var uid = 0;
  var dirty = false;

  function wrap(n) {
    n._id = ++uid;
    n.children = (n.children || []).map(wrap);
    return n;
  }
  var tree = (read('data-tree') || []).map(wrap);

  var list = q('[data-role="tree"]');
  var emptyEl = q('[data-role="empty"]');
  var input = q('[data-role="tree-input"]');
  var form = document.getElementById('menu-form');
  var focusAfter = null;
  var dragged = null;

  function el(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined) { e.textContent = text; }
    return e;
  }

  // ---------- модель
  function locate(id) {
    for (var i = 0; i < tree.length; i++) {
      if (tree[i]._id === id) { return { arr: tree, i: i, parent: null }; }
      for (var j = 0; j < tree[i].children.length; j++) {
        if (tree[i].children[j]._id === id) { return { arr: tree[i].children, i: j, parent: tree[i] }; }
      }
    }
    return null;
  }
  function isInside(node, id) {
    return node._id === id || node.children.some(function (c) { return isInside(c, id); });
  }
  function change() { dirty = true; render(); }

  function move(id, dir) {
    var p = locate(id);
    var k = p.i + dir;
    if (k < 0 || k >= p.arr.length) { return; }
    var t = p.arr[p.i]; p.arr[p.i] = p.arr[k]; p.arr[k] = t;
    change();
  }
  function canIndent(p) { return !p.parent && p.i > 0 && p.arr[p.i].children.length === 0; }
  function indent(id) {
    var p = locate(id);
    if (!canIndent(p)) { return; }
    var node = p.arr.splice(p.i, 1)[0];
    p.arr[p.i - 1].children.push(node);
    p.arr[p.i - 1].open = true;
    change();
  }
  function outdent(id) {
    var p = locate(id);
    if (!p.parent) { return; }
    var node = p.arr.splice(p.i, 1)[0];
    var at = locate(p.parent._id);
    at.arr.splice(at.i + 1, 0, node);
    change();
  }
  function remove(id) {
    var p = locate(id);
    var node = p.arr.splice(p.i, 1)[0];
    // подпункты не пропадают: поднимаются на верхний уровень
    if (!p.parent && node.children.length) {
      Array.prototype.splice.apply(tree, [p.i, 0].concat(node.children));
    }
    change();
  }
  // перенос: where = before | after | inside
  function drop(id, targetId, where) {
    if (id === targetId) { return; }
    var from = locate(id);
    var node = from.arr[from.i];
    if (isInside(node, targetId)) { return; }
    var target = locate(targetId);
    if (where === 'inside' && (target.parent || node.children.length)) { where = 'after'; }
    if (target.parent && node.children.length) { return; } // нельзя: третий уровень
    from.arr.splice(from.i, 1);
    target = locate(targetId);
    if (where === 'inside') {
      target.arr[target.i].children.push(node);
      target.arr[target.i].open = true;
    } else {
      target.arr.splice(target.i + (where === 'after' ? 1 : 0), 0, node);
    }
    change();
  }

  // ---------- вывод
  function btn(label, cls, action, id, disabled, glyph) {
    var b = el('button', 'icon-btn menu-btn' + (cls ? ' ' + cls : ''), glyph);
    b.type = 'button';
    b.setAttribute('aria-label', label);
    b.title = label;
    b.disabled = !!disabled;
    b.setAttribute('data-act', action);
    b.setAttribute('data-id', String(id));
    b.setAttribute('data-testid', 'menu-item-' + action);
    return b;
  }
  function field(label, value, ph, name, node, extra) {
    var wrapEl = el('div', 'field');
    var id = 'mf-' + node._id + '-' + name;
    var lb = el('label', null, label);
    lb.setAttribute('for', id);
    var inp = el('input', 'input');
    inp.type = 'text'; inp.id = id; inp.value = value || ''; inp.maxLength = 100;
    if (ph) { inp.placeholder = ph; }
    inp.setAttribute('data-testid', 'menu-item-' + name);
    inp.addEventListener('input', function () {
      node[name] = inp.value; dirty = true;
      var head = inp.closest('.menu-node').querySelector('.menu-node__label');
      if (head && name === 'title_ru') { head.textContent = inp.value.trim() || node.label || ''; }
    });
    wrapEl.appendChild(lb); wrapEl.appendChild(inp);
    return wrapEl;
  }
  function labelOf(n) { return (n.title_ru || '').trim() || n.label || (n.type === 'link' ? n.url : '') || ''; }

  function buildNode(n, siblings, idx, parent) {
    var li = el('li', 'menu-node menu-node--' + n.type);
    li.draggable = true;
    li.setAttribute('data-id', String(n._id));
    li.setAttribute('data-testid', 'menu-item');
    li.setAttribute('data-type', n.type);
    var head = el('div', 'menu-node__head');
    var grip = el('span', 'menu-grip', '⋮⋮');
    grip.setAttribute('aria-hidden', 'true'); grip.title = T.drag || '';
    var name = el('b', 'menu-node__label', labelOf(n));
    name.setAttribute('data-testid', 'menu-item-label');
    var kind = el('span', 'menu-kind', T['type_' + n.type] || n.type);
    head.appendChild(grip); head.appendChild(name); head.appendChild(kind);
    if (n.hidden) { head.appendChild(el('span', 'menu-kind menu-kind--warn', T.hidden_page)); }
    head.appendChild(el('span', 'menu-sp'));
    var depth0 = !parent;
    head.appendChild(btn(T.up, '', 'up', n._id, idx === 0, '↑'));
    head.appendChild(btn(T.down, '', 'down', n._id, idx === siblings.length - 1, '↓'));
    head.appendChild(btn(T.indent, '', 'indent', n._id, !(depth0 && idx > 0 && n.children.length === 0), '→'));
    head.appendChild(btn(T.outdent, '', 'outdent', n._id, depth0, '←'));
    head.appendChild(btn(n.open ? T.collapse : T.expand, 'menu-btn--toggle', 'toggle', n._id, false, n.open ? '▴' : '▾'));
    head.lastChild.setAttribute('aria-expanded', n.open ? 'true' : 'false');
    li.appendChild(head);

    if (n.open) {
      var body = el('div', 'menu-node__body');
      var ph = (n.type === 'page' || n.type === 'section') ? n.label : '';
      body.appendChild(field(T.title_ru, n.title_ru, ph, 'title_ru', n));
      body.appendChild(field(T.title_kk, n.title_kk, ph, 'title_kk', n));
      if (n.type === 'link') { body.appendChild(field(T.url, n.url, 'https://…', 'url', n)); }
      if (n.type !== 'group') {
        var cl = el('label', 'check');
        var cb = el('input'); cb.type = 'checkbox'; cb.checked = !!n.target_blank;
        cb.setAttribute('data-testid', 'menu-item-blank');
        cb.addEventListener('change', function () { n.target_blank = cb.checked; dirty = true; });
        cl.appendChild(cb); cl.appendChild(document.createTextNode(' ' + T.new_tab));
        body.appendChild(cl);
      }
      var rm = el('button', 'btn btn--sm btn--ghost btn--danger', T.remove);
      rm.type = 'button'; rm.setAttribute('data-act', 'remove'); rm.setAttribute('data-id', String(n._id));
      rm.setAttribute('data-testid', 'menu-item-remove');
      body.appendChild(rm);
      li.appendChild(body);
    }
    if (n.children.length) {
      var ul = el('ul', 'menu-list menu-list--sub');
      n.children.forEach(function (c, i) { ul.appendChild(buildNode(c, n.children, i, n)); });
      li.appendChild(ul);
    }
    return li;
  }

  function render() {
    list.textContent = '';
    tree.forEach(function (n, i) { list.appendChild(buildNode(n, tree, i, null)); });
    emptyEl.hidden = tree.length > 0;
    if (focusAfter) {
      var b = list.querySelector('[data-act="' + focusAfter.act + '"][data-id="' + focusAfter.id + '"]:not([disabled])') ||
              list.querySelector('[data-id="' + focusAfter.id + '"] [data-act]:not([disabled])');
      if (b) { b.focus(); }
      focusAfter = null;
    }
  }

  list.addEventListener('click', function (ev) {
    var b = ev.target.closest('[data-act]');
    if (!b) { return; }
    var id = parseInt(b.getAttribute('data-id'), 10);
    var act = b.getAttribute('data-act');
    focusAfter = { act: act, id: id };
    if (act === 'up') { move(id, -1); }
    else if (act === 'down') { move(id, 1); }
    else if (act === 'indent') { indent(id); }
    else if (act === 'outdent') { outdent(id); }
    else if (act === 'toggle') { var p = locate(id); p.arr[p.i].open = !p.arr[p.i].open; render(); }
    else if (act === 'remove') { focusAfter = null; remove(id); }
  });

  // ---------- перетаскивание
  function zone(ev, li) {
    var r = li.querySelector('.menu-node__head').getBoundingClientRect();
    var y = (ev.clientY - r.top) / Math.max(1, r.height);
    return y < 0.3 ? 'before' : (y > 0.7 ? 'after' : 'inside');
  }
  function clearMarks() {
    list.querySelectorAll('.is-over-before,.is-over-after,.is-over-inside').forEach(function (x) {
      x.classList.remove('is-over-before', 'is-over-after', 'is-over-inside');
    });
  }
  list.addEventListener('dragstart', function (ev) {
    var li = ev.target.closest ? ev.target.closest('.menu-node') : null;
    if (!li || (ev.target.tagName === 'INPUT')) { return; }
    dragged = parseInt(li.getAttribute('data-id'), 10);
    li.classList.add('is-dragging');
    if (ev.dataTransfer) { ev.dataTransfer.effectAllowed = 'move'; ev.dataTransfer.setData('text/plain', 'menu-node'); }
  });
  list.addEventListener('dragend', function () {
    dragged = null; clearMarks();
    list.querySelectorAll('.is-dragging').forEach(function (x) { x.classList.remove('is-dragging'); });
  });
  list.addEventListener('dragover', function (ev) {
    if (dragged === null) { return; }
    var li = ev.target.closest ? ev.target.closest('.menu-node') : null;
    if (!li) { return; }
    ev.preventDefault();
    clearMarks();
    if (parseInt(li.getAttribute('data-id'), 10) !== dragged) {
      li.querySelector('.menu-node__head').parentNode.classList.add('is-over-' + zone(ev, li));
    }
  });
  list.addEventListener('drop', function (ev) {
    if (dragged === null) { return; }
    var li = ev.target.closest ? ev.target.closest('.menu-node') : null;
    if (!li) { return; }
    ev.preventDefault();
    var z = zone(ev, li);
    var id = dragged;
    dragged = null; clearMarks();
    drop(id, parseInt(li.getAttribute('data-id'), 10), z);
  });

  // ---------- «Добавить в меню»
  var tabs = Array.prototype.slice.call(root.querySelectorAll('[data-tab]'));
  tabs.forEach(function (t) {
    t.addEventListener('click', function () {
      tabs.forEach(function (x) { x.setAttribute('aria-selected', x === t ? 'true' : 'false'); });
      root.querySelectorAll('[data-panel]').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== t.getAttribute('data-tab'); });
    });
  });

  function checkRow(group, value, text, note, testid) {
    var lb = el('label', 'check');
    var cb = el('input'); cb.type = 'checkbox'; cb.value = value; cb.name = group;
    cb.setAttribute('data-testid', testid);
    lb.appendChild(cb); lb.appendChild(document.createTextNode(' ' + text));
    if (note) { lb.appendChild(el('span', 'muted', ' (' + note + ')')); }
    return lb;
  }
  var pageList = q('[data-role="page-list"]');
  pages.forEach(function (p) {
    var row = checkRow('page', String(p.id), p.title, p.hidden ? T.hidden_page : '', 'menu-pages-check');
    row.setAttribute('data-title', (p.title + ' ' + p.slug).toLowerCase());
    pageList.appendChild(row);
  });
  if (!pages.length) { pageList.appendChild(el('p', 'muted', T.no_pages)); }
  q('[data-role="page-search"]').addEventListener('input', function (ev) {
    var s = ev.target.value.trim().toLowerCase();
    pageList.querySelectorAll('label').forEach(function (l) { l.hidden = s !== '' && l.getAttribute('data-title').indexOf(s) === -1; });
  });
  var secList = q('[data-role="section-list"]');
  sections.forEach(function (s) { secList.appendChild(checkRow('section', s.key, s.label, '', 'menu-sections-check')); });

  function take(listEl) {
    var out = [];
    listEl.querySelectorAll('input:checked').forEach(function (c) { out.push(c); });
    return out;
  }
  function pushNode(n) { tree.push(wrap(n)); }
  q('[data-role="add-pages"]').addEventListener('click', function () {
    var ch = take(pageList);
    ch.forEach(function (c) {
      var p = pages.filter(function (x) { return String(x.id) === c.value; })[0];
      if (!p) { return; }
      pushNode({ type: 'page', page_id: p.id, section_key: null, url: null, title_ru: '', title_kk: '', target_blank: false, label: p.title, hidden: p.hidden, children: [] });
      c.checked = false;
    });
    if (ch.length) { change(); }
  });
  q('[data-role="add-sections"]').addEventListener('click', function () {
    var ch = take(secList);
    ch.forEach(function (c) {
      var s = sections.filter(function (x) { return x.key === c.value; })[0];
      pushNode({ type: 'section', page_id: null, section_key: s.key, url: null, title_ru: '', title_kk: '', target_blank: false, label: s.label, children: [] });
      c.checked = false;
    });
    if (ch.length) { change(); }
  });
  function showErr(sel, msg) { var e = q(sel); e.textContent = msg; e.hidden = !msg; }
  q('[data-role="add-link"]').addEventListener('click', function () {
    var url = q('[data-role="link-url"]').value.trim();
    var title = q('[data-role="link-title"]').value.trim();
    var ok = /^https?:\/\/[^\s\/]+/i.test(url) || (url.charAt(0) === '/' && url.charAt(1) !== '/');
    if (!ok) { showErr('[data-role="link-error"]', T.need_url); return; }
    if (!title) { showErr('[data-role="link-error"]', T.need_title); return; }
    showErr('[data-role="link-error"]', '');
    pushNode({ type: 'link', page_id: null, section_key: null, url: url, title_ru: title, title_kk: '', target_blank: q('[data-role="link-blank"]').checked, label: '', children: [] });
    q('[data-role="link-url"]').value = ''; q('[data-role="link-title"]').value = ''; q('[data-role="link-blank"]').checked = false;
    change();
  });
  q('[data-role="add-group"]').addEventListener('click', function () {
    var title = q('[data-role="group-title"]').value.trim();
    if (!title) { showErr('[data-role="group-error"]', T.need_title); return; }
    showErr('[data-role="group-error"]', '');
    pushNode({ type: 'group', page_id: null, section_key: null, url: null, title_ru: title, title_kk: '', target_blank: false, label: '', open: false, children: [] });
    q('[data-role="group-title"]').value = '';
    change();
  });

  // ---------- сохранение и уход со страницы
  function plain(n) {
    return { type: n.type, page_id: n.page_id, section_key: n.section_key, url: n.url, title_ru: n.title_ru || '', title_kk: n.title_kk || '',
      target_blank: !!n.target_blank, children: n.children.map(plain) };
  }
  form.addEventListener('submit', function () { input.value = JSON.stringify(tree.map(plain)); dirty = false; });
  document.querySelectorAll('[data-menu-switch]').forEach(function (a) {
    a.addEventListener('click', function (ev) { if (dirty && !window.confirm(T.leave)) { ev.preventDefault(); } });
  });
  window.addEventListener('beforeunload', function (ev) { if (dirty) { ev.preventDefault(); ev.returnValue = ''; } });

  render();
})();
