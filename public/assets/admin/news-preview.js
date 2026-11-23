/* Предпросмотр новости: переключение вкладок «Русский» / «Қазақша». */
(function () {
  'use strict';
  var tabs = Array.prototype.slice.call(document.querySelectorAll('.pv-tab'));
  if (!tabs.length) { return; }
  function show(code, focus) {
    tabs.forEach(function (b) {
      var on = b.getAttribute('data-tab') === code;
      b.setAttribute('aria-selected', on ? 'true' : 'false');
      b.tabIndex = on ? 0 : -1;
      if (on && focus) { b.focus(); }
    });
    Array.prototype.forEach.call(document.querySelectorAll('[data-panel]'), function (p) {
      p.hidden = p.getAttribute('data-panel') !== code;
    });
  }
  tabs.forEach(function (b, i) {
    b.addEventListener('click', function () { show(b.getAttribute('data-tab')); });
    b.addEventListener('keydown', function (ev) {
      var n = null;
      if (ev.key === 'ArrowRight') { n = (i + 1) % tabs.length; }
      else if (ev.key === 'ArrowLeft') { n = (i + tabs.length - 1) % tabs.length; }
      else if (ev.key === 'Home') { n = 0; }
      else if (ev.key === 'End') { n = tabs.length - 1; }
      if (n !== null) { ev.preventDefault(); show(tabs[n].getAttribute('data-tab'), true); }
    });
  });
})();
