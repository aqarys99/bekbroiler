// Плеер YouTube по клику (без inline-скриптов, чтобы работала Content-Security-Policy)
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-video-yt]');
  if (!b) { return; }
  var f = document.createElement('iframe');
  f.src = 'https://www.youtube-nocookie.com/embed/' + encodeURIComponent(b.getAttribute('data-video-yt')) + '?autoplay=1&rel=0';
  f.title = b.getAttribute('data-title') || '';
  f.allow = 'autoplay; encrypted-media; picture-in-picture';
  f.allowFullscreen = true;
  f.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;border:0';
  b.replaceWith(f);
});
