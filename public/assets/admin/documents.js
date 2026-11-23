(function () {
  var file = document.getElementById('documents-file');
  var name = document.querySelector('[data-file-name]');
  var title = document.getElementById('documents-title');
  if (file && name) {
    file.addEventListener('change', function () {
      var f = file.files && file.files[0];
      name.textContent = f ? f.name : '';
      if (f && title && title.value.trim() === '') {
        title.value = f.name.replace(/\.[^.]+$/, '');
      }
    });
  }
  document.querySelectorAll('[data-replace-form]').forEach(function (form) {
    var input = form.querySelector('[data-replace-input]');
    var open = form.querySelector('[data-replace-open]');
    if (!input || !open) { return; }
    open.addEventListener('click', function () { input.click(); });
    input.addEventListener('change', function () { if (input.files && input.files.length) { form.submit(); } });
  });
})();
