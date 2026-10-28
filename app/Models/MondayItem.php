<?php

namespace App\Models;

use App\Support\MondayFields;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Hidden(['source_data', 'fields', 'identity_hash'])]
class MondayItem extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['source_data' => 'encrypted:array', 'fields' => 'encrypted:array', 'issues' => 'array', 'document_date' => 'date', 'original_date' => 'date', 'reviewed_at' => 'datetime'];
    }

    #[Scope]
    protected function needsReview(Builder $query): Builder
    {
        return $query->where(fn (Builder $query): Builder => $query->whereIn('relation_state', ['unmatched', 'ambiguous', 'missing'])->orWhere(fn (Builder $query): Builder => $query->whereNotNull('issues')->whereNull('reviewed_at')));
    }

    public function board(): BelongsTo
    {
        return $this->belongsTo(MondayBoard::class, 'monday_board_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function record(): BelongsTo
    {
        return $this->belongsTo(WorkflowRecord::class, 'workflow_record_id');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(MondayUpdate::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(MondayRevision::class);
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(MondayAsset::class, 'monday_asset_item')->withPivot('column_key');
    }

    public function value(string ...$labels): string
    {
        return MondayFields::value($this->source_data['headers'], $this->fields, ...$labels);
    }

    public function relationLabel(): string
    {
        return ['matched' => 'Powiązano', 'manual' => 'Powiązano ręcznie', 'unmatched' => 'Brak dopasowania', 'ambiguous' => 'Kilka dopasowań', 'missing' => 'Brak danych do powiązania', 'not_required' => 'Nie wymaga powiązania'][$this->relation_state];
    }
}
