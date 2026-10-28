<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Hidden(['team'])]
class MondayImportBatch extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['manifest' => 'array', 'report' => 'array', 'team' => 'encrypted:array'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function boards(): HasMany
    {
        return $this->hasMany(MondayBoard::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MondayAsset::class);
    }

    public function updates(): HasMany
    {
        return $this->hasMany(MondayUpdate::class);
    }
}
