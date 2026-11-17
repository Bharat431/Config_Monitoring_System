<?php

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

final class Time
{
    public static function dbNow(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function isoNow(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public static function dbAfter(int $seconds): string
    {
        return gmdate('Y-m-d H:i:s', time() + max(0, $seconds));
    }

    public static function dbAfterMs(int $milliseconds): string
    {
        return gmdate('Y-m-d H:i:s', time() + (int) ceil(max(0, $milliseconds) / 1000));
    }

    /** Current Unix time in milliseconds. */
    public static function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /** Epoch milliseconds `delay` ms from now. */
    public static function afterMs(int $milliseconds): int
    {
        return self::nowMs() + max(0, $milliseconds);
    }

    public static function isoFromDb(?string $dbDate): ?string
    {
        if ($dbDate === null || $dbDate === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($dbDate, new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\TH:i:s\Z');
        } catch (\Throwable) {
            return $dbDate;
        }
    }

    public static function dbFromIso(string $iso): string
    {
        try {
            return (new DateTimeImmutable($iso))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return self::dbNow();
        }
    }

    public static function ageSeconds(?string $dbDate): ?int
    {
        if ($dbDate === null || $dbDate === '') {
            return null;
        }

        $ts = strtotime($dbDate . ' UTC');

        return $ts === false ? null : max(0, time() - $ts);
    }
}
