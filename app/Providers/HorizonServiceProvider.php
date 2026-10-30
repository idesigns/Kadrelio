<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    protected function authorization(): void
    {
        $this->gate();
        Horizon::auth(fn (Request $request): bool => $request->user()?->can('viewHorizon') ?? false);
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', fn (User $user): bool => $user->isAdmin() && mb_strtolower($user->email) === 'admin@kadrelio.pl');
    }
}
