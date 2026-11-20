(function () {
  var toggle = document.querySelector('[data-testid="install-password-toggle"]');
  if (toggle) {
    toggle.addEventListener('click', function () {
      var show = toggle.getAttribute('aria-pressed') !== 'true';
      document.querySelectorAll('[data-pw]').forEach(function (i) { i.type = show ? 'text' : 'password'; });
      toggle.setAttribute('aria-pressed', show ? 'true' : 'false');
      toggle.setAttribute('aria-label', toggle.getAttribute(show ? 'data-label-hide' : 'data-label-show'));
    });
  }
  var form = document.querySelector('[data-install-form]');
  if (form) {
    form.addEventListener('submit', function () {
      var btn = form.querySelector('[data-testid="install-submit"]');
      if (!btn) { return; }
      // отключаем в следующем тике, чтобы значение кнопки/форма успели уйти
      setTimeout(function () { btn.disabled = true; btn.textContent = btn.getAttribute('data-submitting'); }, 0);
    });
  }
})();
