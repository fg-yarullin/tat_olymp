<?php

namespace App\Support;

use App\Models\Olympiad;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Служебная «шапка» в шаблонах импорта, привязанных к конкретной олимпиаде: видимое название
 * и код (ID) олимпиады в верхних строках. При импорте код считывается и сверяется с олимпиадой,
 * в которую грузят — чтобы случайно не загрузить файл «не в ту» олимпиаду.
 *
 * Структура: строка 1 — «Олимпиада | <название>», строка 2 — «Код олимпиады (не изменять) | <id>»,
 * строка 3 — заголовки колонок, строка 4+ — данные.
 */
class OlympiadImportHeader
{
    private const CODE_LABEL = 'Код олимпиады (не изменять)';

    private const STAGES = ['school' => 'Школьный', 'municipal' => 'Муниципальный', 'regional' => 'Региональный'];

    /** Пишет шапку в лист; возвращает номер строки (1-based) для заголовков колонок. */
    public static function write(Worksheet $sheet, Olympiad $olympiad): int
    {
        $stage = self::STAGES[$olympiad->stage] ?? $olympiad->stage;
        $year = $olympiad->academicYear?->name;
        $label = trim("{$olympiad->subject} — {$stage} этап".($year ? " — {$year}" : ''));

        $sheet->setCellValue('A1', 'Олимпиада');
        $sheet->setCellValue('B1', $label);
        $sheet->setCellValue('A2', self::CODE_LABEL);
        $sheet->setCellValueExplicit('B2', (string) $olympiad->id, DataType::TYPE_STRING);

        return 3;
    }

    /**
     * Разбирает строки файла: находит код олимпиады в шапке и возвращает данные (без шапки/заголовка).
     *
     * @param  list<array<int,string>>  $rows
     * @return array{code: int|null, data: list<array<int,string>>, offset: int}
     */
    public static function parse(array $rows): array
    {
        $codeIndex = null;
        $code = null;
        foreach (array_slice($rows, 0, 4) as $i => $r) {
            if (mb_stripos((string) ($r[0] ?? ''), 'Код олимпиады') !== false) {
                $digits = preg_replace('/\D+/', '', (string) ($r[1] ?? ''));
                $code = $digits === '' ? null : (int) $digits;
                $codeIndex = $i;
                break;
            }
        }

        // Данные: после строки кода (+1) и строки заголовков (+1). Без шапки — пропускаем 1 заголовок.
        $headerOffset = $codeIndex !== null ? $codeIndex + 2 : 1;

        return ['code' => $code, 'data' => array_slice($rows, $headerOffset), 'offset' => $headerOffset];
    }
}
