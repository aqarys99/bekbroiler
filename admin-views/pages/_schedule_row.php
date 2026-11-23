<?php /** @var array $srow @var ?string $serr */ ?>
<div class="sched__row" data-role="row" data-testid="pages-schedule-row">
  <input class="input" type="text" name="sched_time[]" maxlength="30" value="<?= e($srow['time_range']) ?>" placeholder="07:30–08:20" aria-label="<?= e(t('pages.schedule.time')) ?>" data-testid="pages-schedule-time">
  <input class="input" type="text" name="sched_text[]" maxlength="200" value="<?= e($srow['text']) ?>" placeholder="<?= e(t('pages.schedule.text')) ?>" aria-label="<?= e(t('pages.schedule.text')) ?>" data-testid="pages-schedule-text">
  <input class="input" type="text" name="sched_text_kk[]" maxlength="200" value="<?= e($srow['text_kk']) ?>" placeholder="<?= e(t('pages.schedule.text_kk')) ?>" aria-label="<?= e(t('pages.schedule.text_kk')) ?>" data-testid="pages-schedule-text-kk">
  <span class="sched__btns" data-role="btns"></span>
  <?php if ($serr): ?><span class="field__error sched__err" data-testid="pages-schedule-error"><?= e($serr) ?></span><?php endif; ?>
</div>
