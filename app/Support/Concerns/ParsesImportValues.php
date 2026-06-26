<?php

namespace App\Support\Concerns;

/**
 * Общие правила нормализации значений при импорте (веб и консоль), чтобы форматы
 * не расходились: дата, пол, СНИЛС, уровень предмета.
 */
trait ParsesImportValues
{
    /**
     * Нормализует дату к ISO «ГГГГ-ММ-ДД». Принимает ДД-ММ-ГГГГ и ДД.ММ.ГГ, разделители
     * «-», «.», «/», а также ISO. Сначала 4-значный год (иначе «12» примут за 0012),
     * затем двузначный (окно PHP 1970–2069). Строгая проверка: 32.13.12 отвергается.
     */
    protected function parseDate(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        foreach (['d-m-Y', 'd.m.Y', 'd/m/Y', 'Y-m-d'] as $format) {
            $date = $this->tryDate($format, $raw);
            if ($date !== null && (int) $date->format('Y') >= 1000) {
                return $date->format('Y-m-d');
            }
        }

        foreach (['d-m-y', 'd.m.y', 'd/m/y'] as $format) {
            $date = $this->tryDate($format, $raw);
            if ($date !== null) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    private function tryDate(string $format, string $raw): ?\DateTime
    {
        $date = \DateTime::createFromFormat('!'.$format, $raw);
        $e = \DateTime::getLastErrors();

        if ($date !== false && (! $e || ($e['warning_count'] === 0 && $e['error_count'] === 0))) {
            return $date;
        }

        return null;
    }

    /**
     * Нормализует дату-время к «ГГГГ-ММ-ДД ЧЧ:ММ:СС». Принимает «ДД.ММ.ГГГГ ЧЧ:ММ»,
     * ISO и т.п.; дата без времени трактуется как начало дня. null при ошибке.
     */
    protected function parseDateTime(string $raw): ?string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        $formats = ['d.m.Y H:i', 'd-m-Y H:i', 'd/m/Y H:i', 'Y-m-d H:i', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s'];
        foreach ($formats as $format) {
            $date = \DateTime::createFromFormat('!'.$format, $raw);
            $e = \DateTime::getLastErrors();
            if ($date !== false && (! $e || ($e['warning_count'] === 0 && $e['error_count'] === 0)) && (int) $date->format('Y') >= 1000) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        // Дата без времени — начало дня.
        $date = $this->parseDate($raw);

        return $date !== null ? $date.' 00:00:00' : null;
    }

    /** Нормализует пол: принимает м/ж, муж/жен, male/female, m/f. Иначе null (не указано). */
    protected function parseGender(string $raw): ?string
    {
        $v = mb_strtolower(trim($raw));
        if ($v === '') {
            return null;
        }
        if (in_array($v, ['м', 'муж', 'мужской', 'male', 'm'], true)) {
            return 'male';
        }
        if (in_array($v, ['ж', 'жен', 'женский', 'female', 'f'], true)) {
            return 'female';
        }

        return null;
    }

    /** Уровень олимпиады: «республиканский»/republican → republican, иначе regional (по умолчанию). */
    protected function parseLevel(string $raw): string
    {
        $v = mb_strtolower(trim($raw));

        return in_array($v, ['республиканский', 'республика', 'республ', 'republican'], true)
            ? 'republican'
            : 'regional';
    }

    /** Канонизирует СНИЛС к 11 цифрам (без дефисов/пробелов). null, если не 11 цифр. */
    protected function normalizeSnils(string $raw): ?string
    {
        $digits = preg_replace('/\D+/', '', $raw);

        return strlen($digits) === 11 ? $digits : null;
    }
}
