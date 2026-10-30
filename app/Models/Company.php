<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'tax_id', 'address', 'hr_email', 'privacy_url', 'color'])]
#[Hidden(['recruitment_password'])]
class Company extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['recruitment_password' => 'hashed'];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withTimestamps();
    }

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(WorkflowRecord::class);
    }

    public function templates(): HasMany
    {
        return $this->hasMany(DocumentTemplate::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(CompanyInvitation::class);
    }

    public function mondayBoards(): HasMany
    {
        return $this->hasMany(MondayBoard::class);
    }

    public function hasFormConfiguration(): bool
    {
        return in_array(parse_url($this->privacy_url, PHP_URL_SCHEME), ['http', 'https'], true)
            && filter_var($this->privacy_url, FILTER_VALIDATE_URL)
            && filter_var($this->hr_email, FILTER_VALIDATE_EMAIL);
    }

    #[Scope]
    protected function visibleTo(Builder $query, User $user): Builder
    {
        return $query->when(! $user->isAdmin(), fn (Builder $query): Builder => $query->where(fn (Builder $query): Builder => $query->where('owner_id', $user->id)->orWhereHas('users', fn (Builder $query): Builder => $query->whereKey($user->id))));
    }
}
