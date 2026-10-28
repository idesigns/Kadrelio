<?php

namespace App\Http\Controllers;

use App\Mail\HrMessage;
use App\Models\Company;
use App\Models\CompanyInvitation;
use App\Models\User;
use App\Services\OutgoingMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class InvitationController extends Controller
{
    public function index(Company $company): View
    {
        Gate::authorize('view', $company);
        Gate::authorize('update', $company);
        $members = $company->users()->orderBy('name')->get();
        $invitations = $company->invitations()->whereNull('accepted_at')->whereFuture('expires_at')->latest()->get();

        return view('companies.members', compact('company', 'members', 'invitations'));
    }

    public function store(Request $request, Company $company): RedirectResponse
    {
        Gate::authorize('view', $company);
        Gate::authorize('update', $company);
        $request->merge(['email' => mb_strtolower((string) $request->input('email'))]);
        $data = $request->validate(['name' => ['required', 'string', 'max:120'], 'email' => ['required', 'email', 'max:255']]);
        $invitation = new CompanyInvitation;
        $invitation->company()->associate($company);
        $invitation->name = $data['name'];
        $invitation->email = $data['email'];
        $invitation->token = Str::random(64);
        $invitation->expires_at = now()->addDays(config('hr.link_days'));
        $invitation->save();
        $queued = app(OutgoingMail::class)->queue($invitation->email, new HrMessage('Zaproszenie do '.$company->name, 'Otrzymujesz dostęp do przestrzeni firmy '.$company->name.' w Kadrelio. Zaakceptuj zaproszenie, aby dołączyć do zespołu.', route('invitations.show', $invitation->token), 'Dołącz do firmy'));

        return back()->with('success', $queued ? 'Zaproszenie dodano do kolejki e-mail. Możesz też skopiować link z listy zaproszeń.' : 'Zaproszenie zapisano. Wysyłka e-mail jest wyłączona; możesz skopiować link z listy.');
    }

    public function show(CompanyInvitation $invitation): View
    {
        abort_if($invitation->expires_at->isPast() || $invitation->accepted_at, 410, 'To zaproszenie wygasło lub zostało już przyjęte.');
        $exists = User::where('email', $invitation->email)->exists();

        return view('auth.invitation', compact('invitation', 'exists'));
    }

    public function accept(Request $request, CompanyInvitation $invitation): RedirectResponse
    {
        abort_if($invitation->expires_at->isPast() || $invitation->accepted_at, 410);
        $data = [];
        $existing = User::where('email', $invitation->email)->first();
        if ($existing) {
            if (! $request->user() || $request->user()->id !== $existing->id) {
                return redirect()->guest(route('login'))->with('success', 'Zaloguj się na konto '.$invitation->email.', a następnie ponownie otwórz link zaproszenia.');
            }
            $user = $existing;
        } else {
            $data = $request->validate(['password' => ['required', 'confirmed', Password::min(10)->letters()->numbers()]]);
            $user = null;
        }
        $user = DB::transaction(function () use ($invitation, $user, $data): User {
            $locked = CompanyInvitation::whereKey($invitation->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->accepted_at || $locked->expires_at->isPast(), 410);
            if (! $user) {
                $user = new User(['name' => $locked->name, 'email' => $locked->email, 'password' => $data['password']]);
                $user->role = 'user';
                $user->save();
            }
            $locked->company->users()->syncWithoutDetaching([$user->id]);
            $locked->accepted_at = now();
            $locked->save();

            return $user;
        });
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('companies.board', [$invitation->company_id, 'contracts'])->with('success', 'Dołączono do firmy.');
    }
}
