<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MondayBoard extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['columns' => 'array', 'groups' => 'array'];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(MondayImportBatch::class, 'monday_import_batch_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MondayItem::class);
    }
}
