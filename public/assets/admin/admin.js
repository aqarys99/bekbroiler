(function () {
  // Показ/скрытие пароля: переключает все поля [data-pw] своей формы
  document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
    btn.addEventListener('click', function () {
      var form = btn.closest('form');
      if (!form) { return; }
      var show = btn.getAttribute('aria-pressed') !== 'true';
      form.querySelectorAll('input[data-pw]').forEach(function (i) { i.type = show ? 'text' : 'password'; });
      btn.setAttribute('aria-pressed', show ? 'true' : 'false');
      btn.setAttribute('aria-label', btn.getAttribute(show ? 'data-label-hide' : 'data-label-show'));
    });
  });

  // Подтверждение действия
  document.querySelectorAll('form[data-confirm]').forEach(function (form) {
    form.addEventListener('submit', function (ev) {
      if (!window.confirm(form.getAttribute('data-confirm'))) {
        ev.preventDefault();
        ev.stopImmediatePropagation();
      }
    });
  });

  // Защита от двойной отправки
  document.querySelectorAll('form[data-once]').forEach(function (form) {
    form.__sent = false;
    form.addEventListener('submit', function (ev) {
      if (ev.defaultPrevented) { return; }
      if (form.__sent) { ev.preventDefault(); return; }
      form.__sent = true;
      // отключаем в следующем тике, чтобы форма успела уйти
      setTimeout(function () {
        form.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
      }, 0);
    });
  });

  // «Назад» из кэша: вернуть кнопки в рабочее состояние
  window.addEventListener('pageshow', function (ev) {
    if (!ev.persisted) { return; }
    document.querySelectorAll('form[data-once]').forEach(function (f) { f.__sent = false; });
    document.querySelectorAll('form[data-once] button[type="submit"]').forEach(function (b) { b.disabled = false; });
  });
  // Автоотправка GET-формы при изменении поля [data-autosubmit]
  document.querySelectorAll('[data-autosubmit]').forEach(function (el) {
    el.addEventListener('change', function () { if (el.form) { el.form.submit(); } });
  });

  // Мобильное меню (< 900px)
  (function () {
    var nav = document.querySelector('[data-admin-nav]');
    var toggle = document.querySelector('.nav-toggle');
    var backdrop = document.querySelector('[data-nav-backdrop]');
    if (!nav || !toggle) { return; }
    var mq = window.matchMedia('(max-width: 899px)');
    var open = false;

    function focusables() {
      return Array.prototype.slice.call(nav.querySelectorAll('a[href], button:not([disabled])'));
    }
    function setOpen(v, restoreFocus) {
      open = v;
      nav.classList.toggle('is-open', v);
      document.body.classList.toggle('nav-open', v);
      toggle.setAttribute('aria-expanded', v ? 'true' : 'false');
      if (backdrop) { backdrop.hidden = !v; }
      if (v) {
        var f = focusables();
        if (f.length) { f[0].focus(); }
      } else if (restoreFocus) {
        toggle.focus();
      }
    }
    toggle.addEventListener('click', function () { setOpen(!open, true); });
    if (backdrop) { backdrop.addEventListener('click', function () { setOpen(false, true); }); }
    nav.addEventListener('click', function (ev) {
      if (open && ev.target.closest && ev.target.closest('a[href]')) { setOpen(false, false); }
    });
    document.addEventListener('keydown', function (ev) {
      if (!open) { return; }
      if (ev.key === 'Escape') { ev.preventDefault(); setOpen(false, true); return; }
      if (ev.key === 'Tab') {
        var f = focusables();
        if (!f.length) { return; }
        var first = f[0], last = f[f.length - 1];
        if (!nav.contains(document.activeElement)) { ev.preventDefault(); first.focus(); }
        else if (ev.shiftKey && document.activeElement === first) { ev.preventDefault(); last.focus(); }
        else if (!ev.shiftKey && document.activeElement === last) { ev.preventDefault(); first.focus(); }
      }
    });
    function onChange() { if (!mq.matches && open) { setOpen(false, false); } }
    if (mq.addEventListener) { mq.addEventListener('change', onChange); } else if (mq.addListener) { mq.addListener(onChange); }
    window.addEventListener('pageshow', function () { if (open) { setOpen(false, false); } });
  })();
})();
