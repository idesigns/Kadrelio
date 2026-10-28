<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $attributes = ['role' => 'manager'];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed'];
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)->withTimestamps();
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function canCreateCompanies(): bool
    {
        return in_array($this->role, ['admin', 'manager'], true);
    }

    public function roleLabel(): string
    {
        return ['admin' => 'Administrator', 'manager' => 'Manager', 'user' => 'Użytkownik'][$this->role];
    }

    public function initials(): string
    {
        return mb_strtoupper(collect(explode(' ', $this->name))->filter()->take(2)->map(fn (string $part): string => mb_substr($part, 0, 1))->implode(''));
    }
}
