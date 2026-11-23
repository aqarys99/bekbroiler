(function () {
  var tabs = document.querySelector('[data-settings-tabs]');
  if (!tabs) { return; }
  var buttons = tabs.querySelectorAll('[data-tab]');
  var panels = document.querySelectorAll('[data-panel]');
  function show(id) {
    buttons.forEach(function (b) { b.setAttribute('aria-selected', b.getAttribute('data-tab') === id ? 'true' : 'false'); });
    panels.forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== id; });
  }
  buttons.forEach(function (b) { b.addEventListener('click', function () { show(b.getAttribute('data-tab')); }); });
  show(tabs.getAttribute('data-active') || 'main');

  var custom = document.getElementById('s-color_custom');
  var radios = document.querySelectorAll('input[name="color_preset"]');
  radios.forEach(function (r) { r.addEventListener('change', function () { custom.value = ''; }); });
  custom.addEventListener('input', function () { radios.forEach(function (r) { r.checked = false; }); });

  document.querySelectorAll('.theme-card input').forEach(function (r) {
    r.addEventListener('change', function () {
      document.querySelectorAll('.theme-card').forEach(function (c) { c.classList.toggle('is-on', c.contains(r) && r.checked); });
    });
  });
  // сообщение «не сохранено» не нужно: форма одна, обычная отправка
})();
