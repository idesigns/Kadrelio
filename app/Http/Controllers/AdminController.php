<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\GroupPagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminController extends Controller
{
    public function index(Request $request): View
    {
        abort_unless($request->user()->isAdmin(), 403);
        $requestedGroup = $request->header('X-Board-Group');
        abort_if($requestedGroup !== null && $requestedGroup !== 'users', 404);
        $users = GroupPagination::paginate($request, User::with('companies:id,name')->orderBy('name')->orderBy('id'), 'users', User::count());

        return view($requestedGroup === null ? 'admin.users' : 'admin.user-list', compact('users'));
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->user()->isAdmin(), 403);
        $data = $request->validate(['role' => ['required', 'in:admin,manager,user']]);
        abort_if($user->id === $request->user()->id && $data['role'] !== 'admin', 422, 'Nie możesz odebrać sobie dostępu administratora.');
        $user->role = $data['role'];
        $user->save();

        return back()->with('success', 'Rola użytkownika została zapisana.');
    }
}
