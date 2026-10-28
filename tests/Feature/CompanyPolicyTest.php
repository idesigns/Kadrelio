<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompanyPolicyTest extends TestCase
{
    #[DataProvider('roles')]
    public function test_company_permission_matrix(string $role, string $membership, bool $view, bool $manage, bool $create): void
    {
        $user = User::factory()->create(['role' => $role]);
        $company = Company::factory()->create();
        if ($membership === 'owner') {
            $company->owner_id = $user->id;
            $company->save();
        } elseif ($membership === 'member') {
            $company->users()->attach($user);
        }

        $this->assertSame($view, Gate::forUser($user)->allows('view', $company));
        $this->assertSame($manage, Gate::forUser($user)->allows('update', $company));
        $this->assertSame($create, Gate::forUser($user)->allows('create', Company::class));
    }

    public static function roles(): array
    {
        return [
            'global admin' => ['admin', 'outsider', true, true, true],
            'owner manager' => ['manager', 'owner', true, true, true],
            'assigned manager' => ['manager', 'member', true, true, true],
            'unassigned manager' => ['manager', 'outsider', false, false, true],
            'assigned user' => ['user', 'member', true, false, false],
            'unassigned user' => ['user', 'outsider', false, false, false],
        ];
    }
}
