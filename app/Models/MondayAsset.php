<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Hidden(['source_url', 'path'])]
class MondayAsset extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['name' => 'encrypted', 'source_url' => 'encrypted'];
    }

    public function items(): BelongsToMany
    {
        return $this->belongsToMany(MondayItem::class, 'monday_asset_item')->withPivot('column_key');
    }
}
