/* Просмотр DOCX в браузере (docx-preview + jszip с нашего домена). При сбое — сообщение и «Скачать». */
(function () {
  'use strict';
  var root = document.querySelector('[data-docx-viewer]');
  if (!root) { return; }
  var nojs = document.querySelector('[data-dv-nojs]');
  var loading = root.querySelector('[data-dv-loading]');
  var err = root.querySelector('[data-dv-error]');
  var body = root.querySelector('[data-docx-body]');
  if (nojs) { nojs.hidden = true; }
  root.hidden = false;

  function fail() { loading.hidden = true; err.hidden = false; body.hidden = true; }
  function load(src) {
    return new Promise(function (ok, bad) {
      var s = document.createElement('script');
      s.src = src; s.onload = ok; s.onerror = bad;
      document.head.appendChild(s);
    });
  }

  load(root.getAttribute('data-jszip')).then(function () {
    return load(root.getAttribute('data-docx'));
  }).then(function () {
    return fetch(root.getAttribute('data-src'), { credentials: 'same-origin' });
  }).then(function (r) {
    if (!r.ok) { throw new Error('http'); }
    return r.blob();
  }).then(function (blob) {
    return window.docx.renderAsync(blob, body, null, { inWrapper: true, breakPages: true, ignoreLastRenderedPageBreak: false });
  }).then(function () {
    loading.hidden = true;
  }).catch(fail);
})();
