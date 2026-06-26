<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['protocol_template_id', 'position', 'header', 'group_header', 'source_key'])]
class ProtocolColumn extends Model
{
    protected function casts(): array
    {
        return ['position' => 'integer'];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(ProtocolTemplate::class, 'protocol_template_id');
    }
}
