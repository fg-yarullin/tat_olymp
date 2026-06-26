<?php

namespace App\Console\Commands;

use App\Models\HumanOlympiad;
use App\Models\Olympiad;
use App\Support\ScanArchiveImporter;
use Illuminate\Console\Command;

/**
 * Распределение сканов работ муниципального этапа из файла/папки НА СЕРВЕРЕ (минуя веб-загрузку
 * с её лимитами размера). Файлы именуются шифром участника; сопоставляются с работами олимпиады
 * по `barcode`. Источник — ZIP-архив или папка с уже распакованными сканами.
 *
 * Примеры:
 *   php artisan scans:import 12 /home/u/uploads/scans_fizika.zip
 *   php artisan scans:import 12 /home/u/uploads/scans_fizika/   (папка)
 */
class ImportScans extends Command
{
    protected $signature = 'scans:import {olympiad : ID олимпиады (муниципальный этап)} {path : Путь к ZIP-архиву или папке со сканами на сервере}';

    protected $description = 'Разложить сканы работ МЭ из ZIP/папки на сервере по шифрам участников';

    public function handle(): int
    {
        $olympiad = Olympiad::find((int) $this->argument('olympiad'));
        if (! $olympiad) {
            $this->error('Олимпиада не найдена.');

            return self::FAILURE;
        }
        if ($olympiad->stage !== 'municipal') {
            $this->error('Загрузка сканов доступна только для муниципального этапа.');

            return self::FAILURE;
        }

        $path = $this->argument('path');
        if (! file_exists($path)) {
            $this->error("Путь не найден: {$path}");

            return self::FAILURE;
        }

        $byBarcode = HumanOlympiad::where('olympiad_id', $olympiad->id)
            ->whereNotNull('barcode')->get()->keyBy('barcode');
        if ($byBarcode->isEmpty()) {
            $this->error('У участников этой олимпиады не заданы шифры — сопоставить сканы не с чем.');

            return self::FAILURE;
        }

        $this->info("Олимпиада: {$olympiad->subject} (#{$olympiad->id}). Работ с шифром: {$byBarcode->count()}.");

        if (is_dir($path)) {
            $result = ScanArchiveImporter::importDirectory($olympiad->id, $path, $byBarcode);
        } elseif (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'zip') {
            $result = ScanArchiveImporter::import($olympiad->id, $path, $byBarcode);
        } else {
            $this->error('Ожидается путь к .zip или к папке со сканами.');

            return self::FAILURE;
        }

        $this->info("Загружено сканов: {$result['applied']}.");
        if ($result['skipped'] !== []) {
            $this->warn('Пропущено: '.count($result['skipped']));
            foreach ($result['skipped'] as $line) {
                $this->line('  • '.$line);
            }
        }

        return self::SUCCESS;
    }
}
