<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['name', 'kind', 'agreement_type', 'language', 'market', 'department', 'body', 'package_kinds'])]
class DocumentTemplate extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['placeholders' => 'array', 'field_mapping' => 'array', 'package_kinds' => 'array', 'previewed_at' => 'datetime', 'activated_at' => 'datetime'];
    }

    public function fingerprint(): string
    {
        return hash('sha256', json_encode($this->only(['name', 'kind', 'agreement_type', 'language', 'market', 'department', 'body', 'format', 'source_checksum', 'placeholders', 'field_mapping', 'package_kinds']), JSON_THROW_ON_ERROR));
    }

    public function hasCurrentPreview(): bool
    {
        return $this->preview_path && $this->preview_fingerprint && hash_equals($this->fingerprint(), $this->preview_fingerprint);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
