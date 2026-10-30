<?php

namespace App\Providers;

use App\Mail\BrevoTransport;
use App\Models\Company;
use App\Models\User;
use App\Services\OutgoingMail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('viewLogViewer', fn (User $user): bool => $user->isAdmin() && mb_strtolower($user->email) === 'admin@kadrelio.pl');
        Gate::define('deleteLogFile', fn (User $user): bool => false);
        Gate::define('deleteLogFolder', fn (User $user): bool => false);

        Mail::extend('brevo', fn (array $config = []): BrevoTransport => new BrevoTransport((string) config('services.brevo.key')));
        Event::listen(MessageSending::class, function (MessageSending $event): ?bool {
            if (app()->runningUnitTests() || ! app(OutgoingMail::class)->prepare($event->message)) {
                return false;
            }

            return null;
        });
        URL::useOrigin(config('app.url'));
        URL::forceScheme(parse_url(config('app.url'), PHP_URL_SCHEME));
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));
        RateLimiter::for('recruitment-unlock', fn (Request $request): array => [
            Limit::perMinute(5)->by('form:'.$request->path().'|'.$request->ip()),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('recruitment-submit', fn (Request $request): array => [
            Limit::perMinute(10)->by('form:'.$request->path().'|'.$request->ip()),
            Limit::perMinute(20)->by('ip:'.$request->ip()),
        ]);
        View::composer('layouts.app', function (\Illuminate\View\View $view): void {
            if (! Auth::check()) {
                return;
            }
            $companies = Company::visibleTo(Auth::user())->orderBy('name')->get();
            $company = $view->getData()['company'] ?? $companies->firstWhere('id', session('company_id')) ?? $companies->first();
            $company?->loadMissing('users');
            $view->with(['shellCompanies' => $companies, 'company' => $company, 'additionalBoards' => $company?->mondayBoards()->whereIn('kind', ['equipment_assignment', 'equipment_return', 'other'])->orderBy('id')->get(['id', 'company_id', 'name', 'kind']) ?? collect()]);
        });
    }
}
