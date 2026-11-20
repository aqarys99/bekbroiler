<?php /** @var bool $debug @var ?\Throwable $exception @var string $trace */ ?>
<div class="auth__head">
  <h1 data-testid="error-500"><?= e(t('error.500.title')) ?></h1>
  <p><?= e(t('error.500.text')) ?></p>
</div>
<?php if (!empty($debug) && $exception !== null): ?>
<details open data-testid="error-500-details" style="font-size:13px">
  <summary style="min-height:44px;display:flex;align-items:center;font-weight:600"><?= e(t('error.500.details')) ?></summary>
  <pre style="white-space:pre-wrap;overflow-wrap:anywhere;margin:0;padding:12px;background:var(--bg);border-radius:10px"><?= e(get_class($exception) . ': ' . $exception->getMessage() . "\n" . $exception->getFile() . ':' . $exception->getLine() . "\n\n" . $trace) ?></pre>
</details>
<?php endif; ?>
<a class="btn" href="<?= e(url('/')) ?>" data-testid="error-500-home"><?= e(t('error.home')) ?></a>
