<?php
/** @var string $address @var array $phones @var string $email @var string $whatsapp @var string $hours @var array $socials @var string $map */
$wa = preg_replace('/\D+/', '', $whatsapp);
?>
<section class="page">
  <div class="container">
    <div class="section-head"><span class="eyebrow"><?= e(tt('contacts.eyebrow')) ?></span><h1><?= e(tt('nav.contacts')) ?></h1></div>
    <div class="contact-grid">
      <div data-testid="contacts-info">
        <?php if ($address !== ''): ?><div class="contact-item"><div class="icon i-y" aria-hidden="true">📍</div><div><b><?= e(tt('contacts.address')) ?></b><p><?= e($address) ?></p></div></div><?php endif; ?>
        <?php if ($phones): ?><div class="contact-item"><div class="icon i-g" aria-hidden="true">📞</div><div><b><?= e(tt('contacts.phone')) ?></b>
          <?php foreach ($phones as $ph): ?><p><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string)$ph)) ?>"><?= e($ph) ?></a></p><?php endforeach; ?></div></div><?php endif; ?>
        <?php if ($email !== ''): ?><div class="contact-item"><div class="icon i-b" aria-hidden="true">✉️</div><div><b><?= e(tt('contacts.email')) ?></b><p><a href="mailto:<?= e($email) ?>"><?= e($email) ?></a></p></div></div><?php endif; ?>
        <?php if ($hours !== ''): ?><div class="contact-item"><div class="icon i-p" aria-hidden="true">🕗</div><div><b><?= e(tt('contacts.hours')) ?></b><p><?= e($hours) ?></p></div></div><?php endif; ?>
        <?php if ($wa !== '' || $socials): ?>
          <div class="socials" data-testid="contacts-socials">
            <?php if ($wa !== ''): ?><a class="btn btn-outline" href="https://wa.me/<?= e($wa) ?>" rel="noopener" target="_blank">WhatsApp</a><?php endif; ?>
            <?php foreach ($socials as $s): ?><a class="btn btn-outline" href="<?= e($s['url']) ?>" rel="noopener" target="_blank"><?= e(tt('social.' . $s['key'])) ?></a><?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php if ($map !== ''): ?>
        <div class="map" data-testid="contacts-map"><iframe src="<?= e($map) ?>" title="<?= e(tt('contacts.map')) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" allowfullscreen></iframe></div>
      <?php endif; ?>
    </div>
  </div>
</section>
