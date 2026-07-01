<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** Фоновый импорт пользователей по частям: разобранные строки + прогресс/итоги. */
#[Fillable([
    'user_id', 'label', 'allowed_roles', 'header', 'rows', 'errors',
    'total', 'processed', 'created_count', 'updated_count', 'failed_count', 'status',
])]
class UserImport extends Model
{
    protected function casts(): array
    {
        return [
            'allowed_roles' => 'array',
            'header' => 'array',
            'rows' => 'array',
            'errors' => 'array',
        ];
    }
}
