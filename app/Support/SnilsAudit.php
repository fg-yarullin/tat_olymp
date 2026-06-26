<?php

namespace App\Support;

/**
 * Эвристики «подозрительного» СНИЛС: школы нередко вписывают выдуманные номера.
 */
class SnilsAudit
{
    /** Подозрителен ли СНИЛС (выдуманный паттерн). Пустой — не считается. */
    public static function isSuspicious(?string $raw): bool
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if ($d === '') {
            return false;
        }
        if (strlen($d) !== 11) {
            return true; // не 11 цифр
        }
        if (count(array_unique(str_split($d))) <= 2) {
            return true; // одна-две разных цифры: 11111111111, 10000000001
        }

        return self::isSequential($d);
    }

    /** Короткая причина для интерфейса. */
    public static function reason(?string $raw): string
    {
        $d = preg_replace('/\D+/', '', (string) $raw);
        if (strlen($d) !== 11) {
            return 'не 11 цифр';
        }
        if (count(array_unique(str_split($d))) <= 2) {
            return 'мало разных цифр';
        }
        if (self::isSequential($d)) {
            return 'последовательность';
        }

        return '';
    }

    private static function isSequential(string $d): bool
    {
        $asc = $desc = true;
        for ($i = 0; $i < 10; $i++) {
            $cur = (int) $d[$i];
            $next = (int) $d[$i + 1];
            if ($next !== ($cur + 1) % 10) {
                $asc = false;
            }
            if ($next !== ($cur + 9) % 10) {
                $desc = false;
            }
        }

        return $asc || $desc;
    }
}
