<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class CompanyPolicy
{
    public function view(User $user, Company $company): Response
    {
        return Company::visibleTo($user)->whereKey($company->id)->exists() ? Response::allow() : Response::denyAsNotFound();
    }

    public function create(User $user): bool
    {
        return $user->canCreateCompanies();
    }

    public function update(User $user, Company $company): bool
    {
        return $user->canCreateCompanies() && $this->view($user, $company)->allowed();
    }
}
