<?php
/** @var string $icon */
$paths = [
  'home' => '<path d="M3 10.5 12 3l9 7.5V20a1 1 0 0 1-1 1h-5v-6H9v6H4a1 1 0 0 1-1-1z"/>',
  'key'  => '<circle cx="8" cy="15" r="4"/><path d="m11 12 9-9M17 6l3 3"/>',
];
?><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $paths[$icon] ?? '' ?></svg>
