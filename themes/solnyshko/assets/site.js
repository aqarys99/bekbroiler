/* Тема «Солнышко»: меню, версия для слабовидящих (cookie), просмотр фото на весь экран. Без библиотек. */
(function () {
  'use strict';

  // меню на телефоне
  var burger = document.querySelector('.burger');
  var menu = document.querySelector('.menu');
  if (burger && menu) {
    burger.addEventListener('click', function () {
      var open = menu.classList.toggle('open');
      burger.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    menu.querySelectorAll('a').forEach(function (a) {
      a.addEventListener('click', function () {
        menu.classList.remove('open');
        burger.setAttribute('aria-expanded', 'false');
      });
    });
  }

  // версия для слабовидящих: выбор хранится в cookie на год, страницы с сервера кэшируются отдельно
  var a11y = document.querySelector('[data-a11y-toggle]');
  if (a11y) {
    a11y.addEventListener('click', function () {
      var on = document.documentElement.classList.contains('a11y');
      document.cookie = 'a11y=' + (on ? '0' : '1') + '; path=/; max-age=31536000; SameSite=Lax';
      window.location.reload();
    });
  }

  // просмотр фото
  var links = Array.prototype.slice.call(document.querySelectorAll('a[data-lb]'));
  if (!links.length) { return; }
  var kk = document.documentElement.lang === 'kk';
  var L = kk ? { close: 'Жабу', prev: 'Артқа', next: 'Алға' } : { close: 'Закрыть', prev: 'Назад', next: 'Вперёд' };
  var box = null, img, cap, count, group = [], idx = 0, opener = null;

  function build() {
    box = document.createElement('div');
    box.className = 'lb';
    box.hidden = true;
    box.setAttribute('role', 'dialog');
    box.setAttribute('aria-modal', 'true');
    box.innerHTML = '<span class="lb-count"></span><button type="button" class="lb-close" aria-label="' + L.close + '">×</button>' +
      '<button type="button" class="lb-prev" aria-label="' + L.prev + '">‹</button><img alt=""><div class="lb-cap"></div>' +
      '<button type="button" class="lb-next" aria-label="' + L.next + '">›</button>';
    document.body.appendChild(box);
    img = box.querySelector('img');
    cap = box.querySelector('.lb-cap');
    count = box.querySelector('.lb-count');
    box.querySelector('.lb-close').addEventListener('click', close);
    box.querySelector('.lb-prev').addEventListener('click', function () { show(idx - 1); });
    box.querySelector('.lb-next').addEventListener('click', function () { show(idx + 1); });
    box.addEventListener('click', function (e) { if (e.target === box) { close(); } });
    var x0 = null;
    box.addEventListener('touchstart', function (e) { x0 = e.touches[0].clientX; }, { passive: true });
    box.addEventListener('touchend', function (e) {
      if (x0 === null) { return; }
      var dx = e.changedTouches[0].clientX - x0;
      x0 = null;
      if (Math.abs(dx) > 50) { show(idx + (dx < 0 ? 1 : -1)); }
    });
  }

  function show(i) {
    idx = (i + group.length) % group.length;
    var a = group[idx];
    img.src = a.getAttribute('href');
    img.alt = a.getAttribute('data-caption') || '';
    cap.textContent = a.getAttribute('data-caption') || '';
    count.textContent = (idx + 1) + ' / ' + group.length;
    box.querySelector('.lb-prev').style.display = box.querySelector('.lb-next').style.display = group.length > 1 ? '' : 'none';
  }

  function open(a) {
    if (!box) { build(); }
    opener = a;
    var name = a.getAttribute('data-lb');
    group = links.filter(function (l) { return l.getAttribute('data-lb') === name; });
    box.hidden = false;
    document.body.style.overflow = 'hidden';
    show(group.indexOf(a));
    box.querySelector('.lb-close').focus();
  }

  function close() {
    box.hidden = true;
    img.removeAttribute('src');
    document.body.style.overflow = '';
    if (opener) { opener.focus(); }
  }

  links.forEach(function (a) {
    a.addEventListener('click', function (e) {
      if (e.ctrlKey || e.metaKey || e.shiftKey) { return; }
      e.preventDefault();
      open(a);
    });
  });
  document.addEventListener('keydown', function (e) {
    if (!box || box.hidden) { return; }
    if (e.key === 'Escape') { close(); }
    else if (e.key === 'ArrowLeft') { show(idx - 1); }
    else if (e.key === 'ArrowRight') { show(idx + 1); }
    else if (e.key === 'Tab') {
      var f = Array.prototype.slice.call(box.querySelectorAll('button')).filter(function (b) { return b.style.display !== 'none'; });
      var i = f.indexOf(document.activeElement);
      e.preventDefault();
      f[(i + (e.shiftKey ? -1 : 1) + f.length) % f.length].focus();
    }
  });
})();
