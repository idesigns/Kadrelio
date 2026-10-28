<?php

namespace App\Providers;

use App\Models\Company;
use App\Services\OutgoingMail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(MessageSending::class, function (MessageSending $event): ?bool {
            if (! app(OutgoingMail::class)->enabled() || app()->runningUnitTests() || $event->message->getHeaders()->get('X-Kadrelio-Mail-Approved')?->getBodyAsString() !== 'yes') {
                return false;
            }

            return null;
        });
        URL::useOrigin(config('app.url'));
        URL::forceScheme(parse_url(config('app.url'), PHP_URL_SCHEME));
        RateLimiter::for('login', fn (Request $request): Limit => Limit::perMinute(5)->by(mb_strtolower((string) $request->input('email')).'|'.$request->ip()));
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
