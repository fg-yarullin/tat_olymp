<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Чтение загруженного файла-таблицы в массив строк (каждая строка — массив строковых ячеек).
 * Поддерживает XLSX, ODS и CSV — чтобы неопытным пользователям не приходилось возиться с CSV.
 * Разбор колонок и валидация остаются в контроллерах; этот класс лишь приводит файл к строкам.
 */
class SpreadsheetReader
{
    /**
     * @param  string  $path  путь к файлу на диске
     * @param  string  $ext   клиентское расширение (csv/txt/xlsx/ods)
     * @return list<array<int,string>>
     */
    public static function rows(string $path, string $ext): array
    {
        $ext = strtolower(trim($ext));

        if (in_array($ext, ['csv', 'txt'], true)) {
            return self::csvRows($path);
        }

        $type = $ext === 'ods' ? 'Ods' : 'Xlsx';
        $reader = IOFactory::createReader($type);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getActiveSheet();

        // formatData=true: даты/числа возвращаются как отображаются (важно для дат и шифров).
        $rows = [];
        foreach ($sheet->toArray(null, true, true, false) as $row) {
            $rows[] = array_map(fn ($c) => $c === null ? '' : trim((string) $c), $row);
        }

        return self::trimTrailingEmpty($rows);
    }

    /** Чтение CSV: снятие BOM, автоопределение разделителя «;»/«,». */
    private static function csvRows(string $path): array
    {
        $rows = [];
        $handle = fopen($path, 'r');
        $delimiter = null;
        while (($line = fgets($handle)) !== false) {
            if ($delimiter === null) {
                $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);
                $delimiter = substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
            }
            if (trim($line) === '') {
                continue;
            }
            $rows[] = array_map(fn ($c) => trim((string) $c), str_getcsv($line, $delimiter));
        }
        fclose($handle);

        return $rows;
    }

    /** Убирает полностью пустые строки в конце (типично для xlsx/ods). */
    private static function trimTrailingEmpty(array $rows): array
    {
        while ($rows !== [] && count(array_filter(end($rows), fn ($c) => $c !== '')) === 0) {
            array_pop($rows);
        }

        return $rows;
    }
}
