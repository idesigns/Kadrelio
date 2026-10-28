<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['first_name', 'last_name', 'email', 'phone', 'pesel', 'department', 'personal_data'])]
#[Hidden(['pesel', 'personal_data', 'form_token', 'pesel_hash'])]
class Employee extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['pesel' => 'encrypted', 'personal_data' => 'encrypted:array', 'profile_completed_at' => 'datetime', 'form_expires_at' => 'datetime'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(WorkflowRecord::class);
    }

    public function mondayItems(): HasMany
    {
        return $this->hasMany(MondayItem::class);
    }

    public function fullName(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function initials(): string
    {
        return mb_strtoupper(mb_substr($this->first_name, 0, 1).mb_substr($this->last_name, 0, 1));
    }

    public static function identityHash(string $pesel): string
    {
        return hash_hmac('sha256', $pesel, config('app.key'));
    }
}
