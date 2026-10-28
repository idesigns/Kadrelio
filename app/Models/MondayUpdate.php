<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Hidden(['payload'])]
class MondayUpdate extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['payload' => 'encrypted:array', 'posted_at' => 'datetime'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(MondayItem::class, 'monday_item_id');
    }
}
