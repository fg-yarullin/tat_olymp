<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Student;
use App\Support\Concerns\ParsesImportValues;
use Illuminate\Console\Command;

/**
 * Массовый импорт учащихся из CSV (формат шаблона) для миграции из старой базы.
 * Потоковое чтение (любой размер), upsert чанками по СНИЛС (идемпотентно — можно
 * перезапускать), дедупликация в файле по СНИЛС на лету, отклонённые строки в CSV.
 *
 * Колонки: ФИО; Дата рождения; СНИЛС; Код ОО; Класс; Статус; ОВЗ(1/0); Пол(м/ж)
 */
class ImportStudents extends Command
{
    use ParsesImportValues;

    protected $signature = 'students:import
        {file : путь к CSV-файлу}
        {--chunk=2000 : размер пакета вставки}
        {--errors= : путь для CSV с отклонёнными строками}';

    protected $description = 'Массовый импорт учащихся из CSV (потоково, upsert по СНИЛС)';

    private const STATUSES = ['active', 'graduated', 'transferring'];

    // Ключ upsert — (school_id, snils); их в список обновляемых колонок не включаем.
    private const UPDATE_COLUMNS = ['fio', 'birth_date', 'gender', 'real_grade', 'status', 'ovz', 'updated_at'];

    public function handle(): int
    {
        $path = $this->argument('file');
        if (! is_file($path)) {
            $this->error("Файл не найден: $path");

            return self::FAILURE;
        }

        $schoolByCode = School::pluck('id', 'oo_code');
        $chunkSize = max(100, (int) $this->option('chunk'));
        $errorsPath = $this->option('errors')
            ?: storage_path('app/import_errors_students_'.now()->format('Ymd_His').'.csv');

        $total = $this->countDataRows($path);
        $this->info("Строк данных: $total. Размер пакета: $chunkSize.");
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        $in = fopen($path, 'r');
        $errOut = fopen($errorsPath, 'w');
        fwrite($errOut, "\xEF\xBB\xBF");
        $delimiter = $this->detectDelimiterAndHeader($in, $errOut);

        $seen = [];        // канонический СНИЛС -> true (дедуп в файле)
        $chunk = [];
        $now = now()->toDateTimeString();
        $imported = $rejected = $dupes = 0;
        $line = 1; // заголовок уже прочитан

        while (($row = fgetcsv($in, 0, $delimiter)) !== false) {
            $line++;
            if ($row === [null] || $row === [false]) {
                continue;
            }
            $bar->advance();

            [$attributes, $error] = $this->mapRow($row, $schoolByCode, $now);
            if ($error !== null) {
                $this->writeError($errOut, $delimiter, $row, $error);
                $rejected++;
                continue;
            }
            // Дедуп в файле — по (школа, СНИЛС): один и тот же СНИЛС в разных ОО допустим.
            $key = $attributes['school_id'].'|'.$attributes['snils'];
            if (isset($seen[$key])) {
                $this->writeError($errOut, $delimiter, $row, 'дубль СНИЛС в школе (пропущена)');
                $dupes++;
                continue;
            }
            $seen[$key] = true;

            $chunk[] = $attributes;
            if (count($chunk) >= $chunkSize) {
                Student::upsert($chunk, ['school_id', 'snils'], self::UPDATE_COLUMNS);
                $imported += count($chunk);
                $chunk = [];
            }
        }
        if ($chunk) {
            Student::upsert($chunk, ['school_id', 'snils'], self::UPDATE_COLUMNS);
            $imported += count($chunk);
        }

        fclose($in);
        fclose($errOut);
        $bar->finish();
        $this->newLine(2);

        $this->table(['Загружено (upsert)', 'Отклонено', 'Дублей в файле'], [[$imported, $rejected, $dupes]]);
        if ($rejected || $dupes) {
            $this->warn("Отклонённые/дублирующие строки: $errorsPath");
        } else {
            @unlink($errorsPath);
        }

        return self::SUCCESS;
    }

    /** Преобразует строку файла в атрибуты ученика либо возвращает причину отказа. */
    private function mapRow(array $r, $schoolByCode, string $now): array
    {
        $fio = trim((string) ($r[0] ?? ''));
        $birth = $this->parseDate((string) ($r[1] ?? ''));
        $snils = $this->normalizeSnils((string) ($r[2] ?? ''));
        $ooCode = trim((string) ($r[3] ?? ''));
        $grade = (int) trim((string) ($r[4] ?? 0));
        $status = trim((string) ($r[5] ?? 'active')) ?: 'active';
        $ovzRaw = trim((string) ($r[6] ?? ''));
        $gender = $this->parseGender((string) ($r[7] ?? ''));

        if ($fio === '') {
            return [null, 'пустое ФИО'];
        }
        if ($snils === null) {
            return [null, 'СНИЛС обязателен и должен содержать 11 цифр'];
        }
        if ($birth === null) {
            return [null, 'некорректная дата рождения'];
        }
        if (! $schoolByCode->has($ooCode)) {
            return [null, "неизвестный код ОО «{$ooCode}»"];
        }
        if ($grade < 1 || $grade > 11) {
            return [null, 'класс вне диапазона 1–11'];
        }
        if (! in_array($status, self::STATUSES, true)) {
            return [null, "недопустимый статус «{$status}»"];
        }

        return [[
            'fio' => $fio,
            'birth_date' => $birth,
            'snils' => $snils,
            'gender' => $gender,
            'school_id' => $schoolByCode[$ooCode],
            'real_grade' => $grade,
            'status' => $status,
            'ovz' => $ovzRaw === '' ? null : ($ovzRaw === '1'),
            'created_at' => $now,
            'updated_at' => $now,
        ], null];
    }

    /** Считает строки данных (без заголовка) для прогресс-бара. */
    private function countDataRows(string $path): int
    {
        $count = 0;
        $h = fopen($path, 'r');
        while (fgets($h) !== false) {
            $count++;
        }
        fclose($h);

        return max(0, $count - 1);
    }

    /** Снимает BOM, определяет разделитель по заголовку и копирует заголовок в файл ошибок. */
    private function detectDelimiterAndHeader($in, $errOut): string
    {
        $first = fgets($in);
        $first = preg_replace('/^\xEF\xBB\xBF/', '', (string) $first);
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = str_getcsv(rtrim($first, "\r\n"), $delimiter);
        fputcsv($errOut, array_merge($header, ['Ошибка']), $delimiter);

        return $delimiter;
    }

    private function writeError($errOut, string $delimiter, array $row, string $reason): void
    {
        fputcsv($errOut, array_merge($row, [$reason]), $delimiter);
    }
}
