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
})();
