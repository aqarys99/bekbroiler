<?php
/**
 * Просмотр PDF и DOCX: «Читалка» и «Книга» (PDF.js и page-flip из public/assets/vendor, без CDN).
 * @var array $doc @var string $heading @var string $category @var string $date @var string $mode @var array $vendor @var string $listUrl
 */
use App\Site\Theme;

$i18n = [];
foreach (['loading', 'loading_pct', 'error', 'fullscreen', 'fullscreen_off', 'page_of', 'pages_of', 'search_none', 'search_count', 'search_busy', 'of'] as $k) {
    $i18n[$k] = tt('docview.' . $k);
}
$file = $doc['url'];
$ico = static fn(string $d): string => '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
?>
<?php if ($doc['kind'] === 'docx'): ?>
<section class="page docview">
  <div class="container">
    <p class="back"><a href="<?= e($listUrl) ?>" data-testid="docview-back">← <?= e(tt('docview.back')) ?></a></p>
    <h1 data-testid="docview-title"><?= e($heading) ?></h1>
    <p class="muted" data-testid="docview-meta"><?= e($category) ?><?= $date !== '' ? ' · ' . e($date) : '' ?></p>
    <p class="dv-nojs" data-dv-nojs><?= e(tt('docview.nojs')) ?> <a class="btn btn-primary" href="<?= e($file) ?>" data-testid="docview-open-file"><?= e(tt('docview.open_file')) ?></a></p>
    <div class="dv-docx" data-docx-viewer hidden data-src="<?= e($file) ?>" data-jszip="<?= e($vendor['jszip']) ?>" data-docx="<?= e($vendor['docx']) ?>">
      <div class="dv-top">
        <span class="dv-sp"></span>
        <a class="dv-btn" href="<?= e($file) ?>" download data-testid="docview-download"><span><?= e(tt('docview.download')) ?></span></a>
      </div>
      <div class="dv-state" data-dv-loading role="status"><span class="dv-spin" aria-hidden="true"></span><span><?= e(tt('docview.loading')) ?></span></div>
      <div class="dv-state dv-state--err" data-dv-error role="alert" hidden>
        <b><?= e(tt('docview.error')) ?></b>
        <span><?= e(tt('docview.error_hint')) ?></span>
        <a class="btn btn-primary" href="<?= e($file) ?>" download data-testid="docview-error-download"><?= e(tt('docview.download')) ?></a>
      </div>
      <div class="dv-docx__body" data-docx-body tabindex="0" role="region" aria-label="<?= e($heading) ?>" data-testid="docview-docx"></div>
    </div>
  </div>
</section>
<link rel="stylesheet" href="<?= e(Theme::assetUrl('pdf-viewer.css')) ?>">
<script src="<?= e(Theme::assetUrl('docx-viewer.js')) ?>" defer></script>
<?php else: ?>
<link rel="stylesheet" href="<?= e(Theme::assetUrl('pdf-viewer.css')) ?>">
<section class="page docview">
  <div class="container">
    <p class="back"><a href="<?= e($listUrl) ?>" data-testid="docview-back">← <?= e(tt('docview.back')) ?></a></p>
    <h1 data-testid="docview-title"><?= e($heading) ?></h1>
    <p class="muted" data-testid="docview-meta"><?= e($category) ?><?= $date !== '' ? ' · ' . e($date) : '' ?></p>

    <p class="dv-nojs" data-dv-nojs><?= e(tt('docview.nojs')) ?> <a class="btn btn-primary" href="<?= e($file) ?>" data-testid="docview-open-file"><?= e(tt('docview.open_file')) ?></a></p>

    <div class="dv-app" data-pdf-viewer hidden
         data-src="<?= e($file) ?>" data-mode="<?= e($mode) ?>"
         data-pdfjs="<?= e($vendor['pdfjs']) ?>" data-pageflip="<?= e($vendor['pageflip']) ?>"
         data-i18n="<?= e(json_encode($i18n, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP)) ?>">
      <div class="dv-top">
        <div class="dv-seg" role="group" aria-label="<?= e(tt('docview.mode')) ?>">
          <button type="button" data-mode-btn="reader" aria-pressed="false" data-testid="docview-mode-reader"><?= e(tt('docview.reader')) ?></button>
          <button type="button" data-mode-btn="book" aria-pressed="false" data-testid="docview-mode-book"><?= e(tt('docview.book')) ?></button>
        </div>
        <span class="dv-sp"></span>
        <a class="dv-btn" href="<?= e($file) ?>" download data-testid="docview-download"><?= $ico('<path d="M12 4v12M7 11l5 5 5-5M5 20h14"/>') ?><span><?= e(tt('docview.download')) ?></span></a>
        <button type="button" class="dv-btn" data-act="fullscreen" aria-pressed="false" data-testid="docview-fullscreen"><?= $ico('<path d="M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5"/>') ?><span data-fs-label><?= e(tt('docview.fullscreen')) ?></span></button>
      </div>

      <div class="dv-state" data-dv-loading role="status">
        <span class="dv-spin" aria-hidden="true"></span>
        <span data-dv-loading-text><?= e(tt('docview.loading')) ?></span>
        <span class="dv-bar" aria-hidden="true"><i data-dv-progress></i></span>
      </div>
      <div class="dv-state dv-state--err" data-dv-error role="alert" hidden>
        <b><?= e(tt('docview.error')) ?></b>
        <span><?= e(tt('docview.error_hint')) ?></span>
        <a class="btn btn-primary" href="<?= e($file) ?>" download data-testid="docview-error-download"><?= e(tt('docview.download')) ?></a>
      </div>

      <div class="dv-reader" data-pane="reader" hidden>
        <div class="dv-tools" role="toolbar" aria-label="<?= e(tt('docview.reader_label')) ?>">
          <button type="button" class="dv-ib" data-act="thumbs" aria-pressed="true" aria-label="<?= e(tt('docview.thumbs_toggle')) ?>" title="<?= e(tt('docview.thumbs_toggle')) ?>" data-testid="docview-thumbs-toggle"><?= $ico('<rect x="4" y="4" width="6" height="7" rx="1"/><rect x="14" y="4" width="6" height="7" rx="1"/><rect x="4" y="14" width="6" height="7" rx="1"/><rect x="14" y="14" width="6" height="7" rx="1"/>') ?></button>
          <span class="dv-pg">
            <label class="sr-only" for="dv-pageinput"><?= e(tt('docview.go_to')) ?></label>
            <input id="dv-pageinput" type="number" min="1" value="1" inputmode="numeric" data-pageinput data-testid="docview-page-input">
            <span data-pagetotal data-testid="docview-page-total"></span>
          </span>
          <span class="dv-group">
            <button type="button" class="dv-ib" data-act="zoom-out" aria-label="<?= e(tt('docview.zoom_out')) ?>" title="<?= e(tt('docview.zoom_out')) ?>" data-testid="docview-zoom-out"><?= $ico('<path d="M5 12h14"/>') ?></button>
            <span class="dv-zoom" data-zoomlabel aria-live="polite" data-testid="docview-zoom-label">100%</span>
            <button type="button" class="dv-ib" data-act="zoom-in" aria-label="<?= e(tt('docview.zoom_in')) ?>" title="<?= e(tt('docview.zoom_in')) ?>" data-testid="docview-zoom-in"><?= $ico('<path d="M5 12h14M12 5v14"/>') ?></button>
            <button type="button" class="dv-ib dv-ib--txt" data-act="zoom-fit" data-testid="docview-zoom-fit"><?= e(tt('docview.zoom_fit')) ?></button>
          </span>
          <form class="dv-search" role="search" data-searchform>
            <label class="sr-only" for="dv-search"><?= e(tt('docview.search')) ?></label>
            <input id="dv-search" type="search" placeholder="<?= e(tt('docview.search_ph')) ?>" autocomplete="off" data-searchinput data-testid="docview-search-input">
            <span class="dv-count" data-searchcount aria-live="polite" data-testid="docview-search-count"></span>
            <button type="button" class="dv-ib" data-act="search-prev" aria-label="<?= e(tt('docview.search_prev')) ?>" title="<?= e(tt('docview.search_prev')) ?>" data-testid="docview-search-prev"><?= $ico('<path d="m6 15 6-6 6 6"/>') ?></button>
            <button type="button" class="dv-ib" data-act="search-next" aria-label="<?= e(tt('docview.search_next')) ?>" title="<?= e(tt('docview.search_next')) ?>" data-testid="docview-search-next"><?= $ico('<path d="m6 9 6 6 6-6"/>') ?></button>
          </form>
          <a class="dv-ib dv-ib--txt" href="<?= e($file) ?>" target="_blank" rel="noopener" aria-label="<?= e(tt('docview.print')) ?>" title="<?= e(tt('docview.print')) ?>" data-testid="docview-print"><?= $ico('<path d="M7 9V4h10v5M7 17H4v-7h16v7h-3M7 14h10v6H7z"/>') ?><span><?= e(tt('docview.print')) ?></span></a>
        </div>
        <div class="dv-body">
          <nav class="dv-thumbs" data-thumbs aria-label="<?= e(tt('docview.thumbs')) ?>" data-testid="docview-thumbs"></nav>
          <div class="dv-scroll" data-scroll tabindex="0" role="region" aria-label="<?= e(tt('docview.reader_label')) ?>" data-testid="docview-scroll"><div class="dv-pages" data-pages></div></div>
        </div>
      </div>

      <div class="dv-bookpane" data-pane="book" hidden>
        <div class="dv-bookstage" data-bookstage role="region" aria-label="<?= e(tt('docview.book_label')) ?>" tabindex="0" data-testid="docview-book">
          <div data-bookel></div>
        </div>
        <div class="dv-bookbar">
          <button type="button" class="dv-ib" data-act="book-prev" aria-label="<?= e(tt('docview.prev')) ?>" title="<?= e(tt('docview.prev')) ?>" data-testid="docview-book-prev"><?= $ico('<path d="m15 6-6 6 6 6"/>') ?></button>
          <span class="dv-bookpage" data-bookpage aria-live="polite" data-testid="docview-book-page"></span>
          <button type="button" class="dv-ib" data-act="book-next" aria-label="<?= e(tt('docview.next')) ?>" title="<?= e(tt('docview.next')) ?>" data-testid="docview-book-next"><?= $ico('<path d="m9 6 6 6-6 6"/>') ?></button>
        </div>
      </div>
    </div>
  </div>
</section>
<script src="<?= e(Theme::assetUrl('pdf-viewer.js')) ?>" defer></script>
<?php endif; ?>
