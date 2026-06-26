<?php

namespace App\Support;

use FilesystemIterator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ZipArchive;

/**
 * Импорт сканов работ муниципального этапа. Файлы именуются шифром участника (напр. «A-014.pdf»);
 * каждый сопоставляется с работой из переданной карты «шифр → работа» (зона загрузчика — все работы
 * олимпиады для админа или только своего АТЕ для координатора) и сохраняется. Несопоставленные/дубли/
 * неверный формат — в список пропусков. Источник — ZIP-архив (веб-загрузка) или папка на сервере
 * (для очень больших объёмов — без распаковки в память).
 */
class ScanArchiveImporter
{
    private const ALLOWED = ['pdf', 'jpg', 'jpeg', 'png'];

    /**
     * Импорт из ZIP-архива (путь к файлу).
     *
     * @param  Collection<string, \App\Models\HumanOlympiad>  $byBarcode  шифр → работа
     * @return array{applied:int, skipped:array<int,string>}
     */
    public static function import(int $olympiadId, string $zipPath, Collection $byBarcode): array
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            return ['applied' => 0, 'skipped' => ['Не удалось открыть архив.']];
        }

        $applied = 0;
        $skipped = [];
        $seen = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if ($name === false || str_ends_with($name, '/')) {
                continue; // каталог
            }
            self::process($olympiadId, basename($name), fn () => $zip->getFromIndex($i), $byBarcode, $applied, $skipped, $seen);
        }
        $zip->close();

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * Импорт из папки на сервере (рекурсивно). Для больших наборов: архив распаковывают на сервере
     * и указывают путь к папке — файлы читаются по одному с диска.
     *
     * @param  Collection<string, \App\Models\HumanOlympiad>  $byBarcode  шифр → работа
     * @return array{applied:int, skipped:array<int,string>}
     */
    public static function importDirectory(int $olympiadId, string $dir, Collection $byBarcode): array
    {
        $applied = 0;
        $skipped = [];
        $seen = [];

        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $path = $file->getPathname();
            self::process($olympiadId, basename($path), fn () => file_get_contents($path), $byBarcode, $applied, $skipped, $seen);
        }

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    /**
     * Обрабатывает один файл: проверяет формат/дубль/наличие шифра, читает содержимое (ленивo,
     * через $read) и сохраняет скан. Состояние ($applied/$skipped/$seen) мутируется по ссылке.
     */
    private static function process(int $olympiadId, string $base, callable $read, Collection $byBarcode, int &$applied, array &$skipped, array &$seen): void
    {
        $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
        $cipher = trim(pathinfo($base, PATHINFO_FILENAME));
        if ($cipher === '') {
            return;
        }
        if (! in_array($ext, self::ALLOWED, true)) {
            $skipped[] = "{$base}: недопустимый формат (нужен pdf/jpg/png)";

            return;
        }
        if (isset($seen[$cipher])) {
            $skipped[] = "{$base}: дубль шифра «{$cipher}» в архиве";

            return;
        }
        $work = $byBarcode->get($cipher);
        if (! $work) {
            $skipped[] = "{$base}: шифр «{$cipher}» не найден среди работ";

            return;
        }
        $contents = $read();
        if ($contents === false) {
            $skipped[] = "{$base}: не удалось прочитать файл";

            return;
        }

        $path = "scans/municipal/{$olympiadId}/{$work->id}.{$ext}";
        Storage::put($path, $contents);
        $work->update(['scan_path' => $path]);
        $seen[$cipher] = true;
        $applied++;
    }
}
