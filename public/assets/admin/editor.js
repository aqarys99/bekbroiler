/* Минимальный редактор текста на contenteditable (фича 04). Разметка — admin-views/partials/editor.php.
   Кнопки: Ж, К, заголовок раздела (h2), список, ссылка, фото из медиатеки. Видео нет (фича 07).
   Источник данных для сервера — скрытая textarea[name]; HTML чистит сервер (HtmlSanitizer), клиент ничего не доверяет себе.
   API: root.__editor = { getHtml(), setHtml(html), isEmpty(), focus() }. Изменения шлют bubbling-событие input. */
(function () {
  'use strict';

  var SAFE_LINK = /^(https?:\/\/|mailto:)/i;
  var texts = null;

  function i18n() {
    if (texts) { return texts; }
    texts = {};
    var holder = document.querySelector('[data-config]');
    try { texts = JSON.parse(holder.getAttribute('data-config')).i18n || {}; } catch (e) { texts = {}; }
    return texts;
  }

  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  /** Адрес ссылки -> безопасный адрес или null (сообщение «только http, https, mailto»). */
  function normalizeUrl(raw) {
    var u = String(raw).replace(/[\u0000-\u001F\u007F\s]+/g, '');
    if (u === '') { return ''; }
    if (SAFE_LINK.test(u)) { return u; }
    if (/^[a-z][a-z0-9+.\-]*:(?!\d)/i.test(u)) { return null; }       // javascript:, data:, ftp: …
    if (/^[^\/@:]+@[^\/@:]+\.[^\/@:]+$/.test(u)) { return 'mailto:' + u; }
    return 'https://' + u.replace(/^\/+/, '');
  }

  function init(root) {
    if (root.__editor) { return; }
    var area = root.querySelector('.editor__area');
    var src = root.querySelector('.editor__source');
    var tb = root.querySelector('.editor__tb');
    if (!area || !src || !tb) { return; }
    var form = root.closest('form');
    var buttons = Array.prototype.slice.call(tb.querySelectorAll('.editor__btn'));
    var saved = null; // последняя позиция курсора внутри редактора

    try { document.execCommand('defaultParagraphSeparator', false, 'p'); } catch (e) { /* не критично */ }
    try { document.execCommand('styleWithCSS', false, false); } catch (e) { /* не критично */ }

    // ---------- состояние ----------
    function hasContent() {
      return !!area.querySelector('img') || area.textContent.replace(/[​\s]/g, '') !== '';
    }
    function getHtml() { return hasContent() ? area.innerHTML : ''; }
    function sync() {
      src.value = getHtml();
      area.classList.toggle('is-empty', !hasContent());
    }
    function fire() {
      sync();
      area.dispatchEvent(new Event('input', { bubbles: true }));
    }

    function inArea(node) { return !!node && area.contains(node); }
    function selRange() {
      var s = window.getSelection();
      if (!s || !s.rangeCount) { return null; }
      var r = s.getRangeAt(0);
      return inArea(r.startContainer) && inArea(r.endContainer) ? r : null;
    }
    function remember() { var r = selRange(); if (r) { saved = r.cloneRange(); } }
    function restore() {
      area.focus();
      if (!saved) { return; }
      var s = window.getSelection();
      s.removeAllRanges();
      s.addRange(saved);
    }
    function elementOf(node) { return node && node.nodeType === 3 ? node.parentNode : node; }
    function closestIn(node, sel) {
      var n = elementOf(node);
      while (n && n !== area) {
        if (n.matches && n.matches(sel)) { return n; }
        n = n.parentNode;
      }
      return null;
    }
    function topBlock(node) {
      var n = node;
      while (n && n.parentNode !== area) { n = n.parentNode; }
      return n && n.nodeType === 1 ? n : null;
    }

    function ensureParagraph() {
      if (area.firstChild === null || (area.childNodes.length === 1 && area.firstChild.nodeName === 'BR')) {
        area.innerHTML = '<p><br></p>';
        var r = document.createRange();
        r.setStart(area.firstChild, 0); r.collapse(true);
        var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
      }
    }

    // ---------- кнопки ----------
    function setPressed(cmd, on) {
      var b = tb.querySelector('[data-cmd="' + cmd + '"]');
      if (b && b.hasAttribute('aria-pressed')) { b.setAttribute('aria-pressed', on ? 'true' : 'false'); }
    }
    function updateState() {
      var r = selRange();
      if (!r) { return; }
      var q = function (c) { try { return document.queryCommandState(c); } catch (e) { return false; } };
      setPressed('bold', q('bold'));
      setPressed('italic', q('italic'));
      setPressed('list', !!closestIn(r.startContainer, 'li') && q('insertUnorderedList'));
      var blk = topBlock(r.startContainer);
      setPressed('heading', !!blk && /^H[1-6]$/.test(blk.nodeName));
    }

    function exec(cmd, val) {
      restore();
      document.execCommand(cmd, false, val === undefined ? null : val);
      fire();
      remember();
      updateState();
    }

    function doHeading() {
      restore();
      ensureParagraph();
      var r = selRange();
      if (!r) { return; }
      if (closestIn(r.startContainer, 'li')) { return; } // внутри списка пункт в заголовок не превращается
      var blk = topBlock(r.startContainer);
      var isH = blk && /^H[1-6]$/.test(blk.nodeName);
      document.execCommand('formatBlock', false, isH ? 'p' : 'h2');
      fire(); remember(); updateState();
    }

    function doLink() {
      restore();
      var r = selRange();
      var anchor = r ? closestIn(r.startContainer, 'a') : null;
      var collapsed = !r || r.collapsed;
      var t = i18n();
      var url = window.prompt(t.linkPrompt || 'https://', anchor ? anchor.getAttribute('href') || '' : '');
      if (url === null) { restore(); return; }
      url = url.trim();
      if (url === '') {
        if (anchor) {
          var rr = document.createRange(); rr.selectNodeContents(anchor);
          var s = window.getSelection(); s.removeAllRanges(); s.addRange(rr);
          document.execCommand('unlink', false, null);
          fire(); remember();
        }
        return;
      }
      var norm = normalizeUrl(url);
      if (norm === null) { window.alert(t.linkBad || ''); restore(); return; }
      restore();
      if (anchor) {
        anchor.setAttribute('href', norm);
        fire();
      } else if (collapsed) {
        document.execCommand('insertHTML', false, '<a href="' + esc(norm) + '">' + esc(url) + '</a>&nbsp;');
        fire();
      } else {
        document.execCommand('createLink', false, norm);
        fire();
      }
      remember(); updateState();
    }

    function insertPhotos(items) {
      if (!items.length) { return; }
      var r = saved && inArea(saved.startContainer) ? saved : null;
      var ref = null;
      if (r) {
        ref = topBlock(r.endContainer);
      }
      var frag = document.createDocumentFragment();
      var last = null;
      items.forEach(function (it) {
        var p = document.createElement('p');
        var img = document.createElement('img');
        img.setAttribute('data-media-id', String(it.id));
        img.setAttribute('src', it.thumb_jpg || it.thumb_webp || '');
        img.setAttribute('alt', it.alt || '');
        p.appendChild(img);
        frag.appendChild(p);
        last = p;
      });
      var tail = document.createElement('p'); tail.appendChild(document.createElement('br'));
      frag.appendChild(tail);
      var empty = ref && ref.nodeName === 'P' && ref.textContent.replace(/[​\s]/g, '') === '' && !ref.querySelector('img');
      if (ref && empty) { area.replaceChild(frag, ref); }
      else if (ref) { area.insertBefore(frag, ref.nextSibling); }
      else { area.appendChild(frag); }
      var nr = document.createRange(); nr.setStart(tail, 0); nr.collapse(true);
      area.focus();
      var s = window.getSelection(); s.removeAllRanges(); s.addRange(nr);
      saved = nr.cloneRange();
      fire();
    }

    function doPhoto() {
      remember();
      var api = window.AdminMediaPicker;
      if (!api) { return; }
      api.open({
        multiple: true,
        selected: [],
        onConfirm: insertPhotos,
        onClose: function () { area.focus(); if (saved) { var s = window.getSelection(); s.removeAllRanges(); s.addRange(saved); } }
      });
    }

    var actions = {
      bold: function () { exec('bold'); },
      italic: function () { exec('italic'); },
      heading: doHeading,
      list: function () { restore(); ensureParagraph(); exec('insertUnorderedList'); },
      link: doLink,
      photo: doPhoto
    };

    tb.addEventListener('mousedown', function (ev) {
      if (ev.target.closest('.editor__btn')) { ev.preventDefault(); } // фокус и выделение остаются в тексте
    });
    buttons.forEach(function (b, i) {
      b.tabIndex = i === 0 ? 0 : -1;
      b.addEventListener('click', function () {
        var a = actions[b.getAttribute('data-cmd')];
        if (a) { a(); }
      });
    });
    tb.addEventListener('keydown', function (ev) {
      var i = buttons.indexOf(document.activeElement);
      if (i === -1) { return; }
      var n = null;
      if (ev.key === 'ArrowRight') { n = (i + 1) % buttons.length; }
      else if (ev.key === 'ArrowLeft') { n = (i + buttons.length - 1) % buttons.length; }
      else if (ev.key === 'Home') { n = 0; }
      else if (ev.key === 'End') { n = buttons.length - 1; }
      if (n === null) { return; }
      ev.preventDefault();
      buttons.forEach(function (b) { b.tabIndex = -1; });
      buttons[n].tabIndex = 0;
      buttons[n].focus();
    });

    // ---------- текст ----------
    area.addEventListener('input', function () { sync(); });
    area.addEventListener('focus', function () { ensureParagraph(); sync(); });
    area.addEventListener('blur', function () { remember(); sync(); });
    area.addEventListener('keyup', function () { remember(); updateState(); });
    area.addEventListener('mouseup', function () { remember(); updateState(); });
    area.addEventListener('keydown', function (ev) {
      if ((ev.ctrlKey || ev.metaKey) && !ev.shiftKey && !ev.altKey && ev.key.toLowerCase() === 'k') {
        ev.preventDefault();
        doLink();
      }
    });
    document.addEventListener('selectionchange', function () {
      if (selRange()) { remember(); updateState(); }
    });

    // вставка из буфера — только простой текст
    function plainToHtml(text) {
      var t = String(text).replace(/\r\n?/g, '\n').replace(/^\n+|\n+$/g, '');
      if (t === '') { return ''; }
      var paras = t.split(/\n{2,}/);
      if (paras.length === 1) { return esc(paras[0]).replace(/\n/g, '<br>'); }
      return paras.map(function (p) { return '<p>' + esc(p).replace(/\n/g, '<br>') + '</p>'; }).join('');
    }
    area.addEventListener('paste', function (ev) {
      ev.preventDefault();
      var cd = ev.clipboardData || window.clipboardData;
      var text = cd ? cd.getData('text/plain') : '';
      var html = plainToHtml(text);
      if (html === '') { return; }
      ensureParagraph();
      document.execCommand('insertHTML', false, html);
      fire();
    });
    area.addEventListener('drop', function (ev) {
      // форматированный текст и файлы не принимаем; простой текст вставляем как есть
      ev.preventDefault();
      var dt = ev.dataTransfer;
      var text = dt ? dt.getData('text/plain') : '';
      var html = plainToHtml(text);
      if (html === '') { return; }
      restore();
      document.execCommand('insertHTML', false, html);
      fire();
    });

    if (form) {
      form.addEventListener('submit', sync, true);
    }

    root.__editor = {
      getHtml: getHtml,
      isEmpty: function () { return !hasContent(); },
      setHtml: function (html) { area.innerHTML = html || ''; fire(); },
      focus: function () { area.focus(); }
    };
    sync();
  }

  document.querySelectorAll('[data-editor]').forEach(init);
})();
