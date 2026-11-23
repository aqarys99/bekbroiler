<?php
declare(strict_types=1);

namespace App\Core;

use DateTimeImmutable;
use DateTimeZone;

/** Текущее время и пояс сайта (I-29). Все даты в БД — UTC, строка Y-m-d H:i:s. */
final class Clock
{
    public const FORMAT = 'Y-m-d H:i:s';

    public static function testFile(): string
    {
        return Config::storageDir() . '/test_clock.json';
    }

    public static function nowUtc(): DateTimeImmutable
    {
        if (getenv('APP_ENV') === 'testing') {
            $file = self::testFile();
            if (is_file($file)) {
                $data = json_decode((string)file_get_contents($file), true);
                $now = is_array($data) && is_string($data['now'] ?? null) ? $data['now'] : null;
                if ($now !== null) {
                    $d = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $now, new DateTimeZone('UTC'));
                    if ($d !== false) {
                        return $d;
                    }
                }
            }
        }
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public static function timezone(): DateTimeZone
    {
        try {
            return new DateTimeZone((string)config('timezone', 'Asia/Almaty'));
        } catch (\Exception) {
            return new DateTimeZone('Asia/Almaty');
        }
    }

    /** UTC-строка из БД -> время в поясе сайта. */
    public static function toLocal(string $utc): DateTimeImmutable
    {
        $d = DateTimeImmutable::createFromFormat('!' . self::FORMAT, $utc, new DateTimeZone('UTC'))
            ?: new DateTimeImmutable($utc, new DateTimeZone('UTC'));
        return $d->setTimezone(self::timezone());
    }

    /** Строгий разбор «Y-m-d» + «H:i» в поясе сайта; несуществующая дата -> null. Результат в UTC. */
    public static function fromLocal(string $date, string $time): ?DateTimeImmutable
    {
        $date = trim($date);
        $time = trim($time);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) {
            return null;
        }
        $tz = self::timezone();
        $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $tz);
        $err = DateTimeImmutable::getLastErrors();
        if ($d === false || ($err && ($err['warning_count'] > 0 || $err['error_count'] > 0))) {
            return null;
        }
        if ($d->format('Y-m-d H:i') !== $date . ' ' . $time) {
            return null;
        }
        return $d->setTimezone(new DateTimeZone('UTC'));
    }

    /** Смещение пояса сайта на момент $at, вид «+05:00». */
    public static function offsetLabel(?DateTimeImmutable $at = null): string
    {
        return ($at ?? self::nowUtc())->setTimezone(self::timezone())->format('P');
    }
}
