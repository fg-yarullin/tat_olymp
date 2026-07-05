<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Фоновый (по частям) импорт: разобранные строки + контекст домена + прогресс/итоги. */
#[Fillable([
    'user_id', 'type', 'label', 'context', 'header', 'rows', 'errors',
    'total', 'processed', 'created_count', 'updated_count', 'skipped_count', 'failed_count', 'status',
])]
class BulkImport extends Model
{
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'header' => 'array',
            'rows' => 'array',
            'errors' => 'array',
        ];
    }
}
