<?php
declare(strict_types=1);

namespace App\Core;

use App\Models\Media;

/** Проверка формы «Настройки». Возвращает значения для сохранения и ошибки по полям. */
final class SettingsValidator
{
    public const THEMES = ['sun', 'official', 'nature'];
    public const COLORS = ['#F2A516', '#1F5BD8', '#2E8B57', '#0F8B8D', '#D0447A', '#6A4BC4'];
    public const MAP_HOSTS = ['2gis.kz', '2gis.ru', 'yandex.kz', 'yandex.ru'];
    public const SOCIALS = ['instagram', 'facebook', 'telegram', 'twogis'];

    /** @return array{values:array<string,mixed>,errors:array<string,string>} */
    public static function validate(array $in, ?int $year = null): array
    {
        $year ??= (int)date('Y');
        $v = [];
        $err = [];
        $str = static fn(string $k): string => is_string($in[$k] ?? null) ? trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/u', '', $in[$k]) ?? '') : '';
        $len = static fn(string $s): int => mb_strlen($s);

        $v['site_name'] = $str('site_name');
        if ($len($v['site_name']) < 2 || $len($v['site_name']) > 150) {
            $err['site_name'] = t('validation.site_name.length');
        }
        foreach (['full_name' => 200, 'full_name_kk' => 200, 'director' => 150, 'director_kk' => 150, 'work_hours' => 200, 'license_number' => 60, 'address' => 250, 'address_kk' => 250] as $k => $max) {
            $v[$k] = $str($k);
            if ($len($v[$k]) > $max) {
                $err[$k] = t('settings.error.max', ['max' => $max]);
            }
        }
        $v['founded_year'] = $str('founded_year');
        if ($v['founded_year'] !== '' && (!preg_match('/^\d{4}$/', $v['founded_year']) || (int)$v['founded_year'] < 1900 || (int)$v['founded_year'] > $year)) {
            $err['founded_year'] = t('settings.error.year', ['max' => $year]);
        }
        $v['capacity'] = $str('capacity');
        if ($v['capacity'] !== '' && (!preg_match('/^\d{1,4}$/', $v['capacity']) || (int)$v['capacity'] < 1)) {
            $err['capacity'] = t('settings.error.capacity');
        }
        $langs = is_array($in['teach_langs'] ?? null) ? $in['teach_langs'] : [];
        $v['teach_langs'] = array_values(array_intersect(['ru', 'kk'], array_map('strval', array_filter($langs, 'is_scalar'))));
        $v['license_date'] = $str('license_date');
        if ($v['license_date'] !== '') {
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v['license_date']);
            if (!$d || $d->format('Y-m-d') !== $v['license_date']) {
                $err['license_date'] = t('settings.error.date');
            }
        }
        $v['bin'] = $str('bin');
        if ($v['bin'] !== '' && !preg_match('/^\d{12}$/', $v['bin'])) {
            $err['bin'] = t('settings.error.bin');
        }
        $v['disk_quota_mb'] = str_replace(',', '.', $str('disk_quota_mb'));
        if ($v['disk_quota_mb'] !== '' && (!is_numeric($v['disk_quota_mb']) || (float)$v['disk_quota_mb'] <= 0 || (float)$v['disk_quota_mb'] > 10000000)) {
            $err['disk_quota_mb'] = t('settings.error.quota');
        }

        // оформление
        $v['theme'] = $str('theme') ?: 'sun';
        if (!in_array($v['theme'], self::THEMES, true)) {
            $err['theme'] = t('settings.error.theme');
        }
        $color = $str('color_custom') !== '' ? $str('color_custom') : $str('color_preset');
        $color = $color === '' ? self::COLORS[0] : $color;
        if (!preg_match('/^#[0-9A-Fa-f]{6}$/', $color)) {
            $err['color'] = t('settings.error.color');
        }
        $v['color'] = strtoupper($color);
        foreach (['logo_media_id', 'hero_media_id'] as $k) {
            $id = Media::parseIds($in[$k] ?? null)[0] ?? null;
            $m = $id !== null ? Media::find($id) : null;
            if ($id !== null && ($m === null || $m['type'] !== 'image')) {
                $err[$k] = t('settings.error.image');
                $id = null;
            }
            $v[$k] = $id;
        }
        $v['kk_enabled'] = !empty($in['kk_enabled']);
        $v['default_lang'] = $str('default_lang') ?: 'ru';
        if (!in_array($v['default_lang'], ['ru', 'kk'], true)) {
            $err['default_lang'] = t('settings.error.lang');
        } elseif ($v['default_lang'] === 'kk' && !$v['kk_enabled']) {
            $err['default_lang'] = t('settings.error.lang_kk_off');
        }
        $v['a11y_enabled'] = !empty($in['a11y_enabled']);

        // контакты
        $phones = [];
        foreach (preg_split('/\R/u', is_string($in['phones'] ?? null) ? $in['phones'] : '') ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $phones[] = $line;
            }
        }
        $v['phones'] = $phones;
        foreach ($phones as $p) {
            if (!preg_match('/^\+?[0-9][0-9 ()\-]{4,24}$/', $p)) {
                $err['phones'] = t('settings.error.phone');
            }
        }
        if (count($phones) > 5) {
            $err['phones'] = t('settings.error.phones_many');
        }
        $v['email'] = $str('email');
        if ($v['email'] !== '' && (strlen($v['email']) > 150 || !filter_var($v['email'], FILTER_VALIDATE_EMAIL))) {
            $err['email'] = t('settings.error.email');
        }
        $v['whatsapp'] = $str('whatsapp');
        if ($v['whatsapp'] !== '' && !preg_match('/^\+?[0-9][0-9 ()\-]{4,24}$/', $v['whatsapp'])) {
            $err['whatsapp'] = t('settings.error.phone');
        }
        foreach (self::SOCIALS as $k) {
            $v[$k] = $str($k);
            if ($v[$k] !== '' && self::cleanUrl($v[$k]) === null) {
                $err[$k] = t('settings.error.url');
            }
        }
        $v['map_src'] = '';
        $mapRaw = is_string($in['map_code'] ?? null) ? trim($in['map_code']) : '';
        if ($mapRaw !== '') {
            $src = self::mapSrc($mapRaw);
            if ($src === null) {
                $err['map_code'] = t('settings.error.map');
            } else {
                $v['map_src'] = $src;
            }
        }
        return ['values' => $v, 'errors' => $err];
    }

    public static function cleanUrl(string $u): ?string
    {
        if (strlen($u) > 300 || preg_match('/[\s<>"\'\\\\\x00-\x1f]/', $u)) {
            return null;
        }
        $p = parse_url($u);
        if (!$p || !in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true) || empty($p['host']) || !str_contains($p['host'], '.')) {
            return null;
        }
        return $u;
    }

    /** Достаёт src из iframe (или принимает голый URL); только разрешённые домены и https. */
    public static function mapSrc(string $raw): ?string
    {
        if (strlen($raw) > 3000 || preg_match('/<\s*(script|object|embed|style)/i', $raw)) {
            return null;
        }
        if (str_contains($raw, '<')) {
            if (!preg_match('/^<iframe\b[^>]*\bsrc\s*=\s*(?:"([^"]*)"|\'([^\']*)\')[^>]*>\s*(?:<\/iframe>)?$/is', $raw, $m)) {
                return null;
            }
            $src = html_entity_decode($m[1] !== '' ? $m[1] : ($m[2] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        } else {
            $src = $raw;
        }
        $src = trim($src);
        if ($src === '' || preg_match('/[\s<>"\'\\\\\x00-\x1f]/', $src)) {
            return null;
        }
        $p = parse_url($src);
        if (!$p || strtolower((string)($p['scheme'] ?? '')) !== 'https' || empty($p['host']) || isset($p['user'])) {
            return null;
        }
        $host = strtolower($p['host']);
        foreach (self::MAP_HOSTS as $d) {
            if ($host === $d || str_ends_with($host, '.' . $d)) {
                return $src;
            }
        }
        if (($host === 'google.com' || $host === 'www.google.com') && str_starts_with((string)($p['path'] ?? ''), '/maps')) {
            return $src;
        }
        return null;
    }
}
