<?php

namespace App\Providers;

use App\Models\PersonalAccessToken;
use App\Modules\Merchants\Domain\MembershipPolicy;
use App\Modules\Merchants\Domain\MerchantContext;
use App\Modules\Merchants\Infrastructure\Membership;
use App\Modules\Pricing\Application\StaticCarrierRates;
use App\Modules\Pricing\Domain\CarrierRates;
use App\Modules\WalletBilling\Domain\PaymentProvider;
use App\Modules\WalletBilling\Infrastructure\DisabledPaymentProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CarrierRates::class, StaticCarrierRates::class);
        $this->app->bind(PaymentProvider::class, DisabledPaymentProvider::class);
        $this->app->scoped(MerchantContext::class, fn () => new MerchantContext);
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
        Gate::policy(Membership::class, MembershipPolicy::class);
        $clear = fn () => app(MerchantContext::class)->clear();
        Event::listen(JobProcessing::class, $clear);
        Event::listen(JobProcessed::class, $clear);
        Event::listen(JobExceptionOccurred::class, $clear);
        RateLimiter::for('auth-login', fn (Request $request) => [
            Limit::perMinute(20)->by('login-ip:'.$request->ip()),
            Limit::perMinute(5)->by('login:'.hash('sha256', strtolower((string) $request->input('email'))).'|'.$request->ip()),
        ]);
        RateLimiter::for('auth-password-reset', fn (Request $request) => Limit::perMinute(10)->by('reset:'.$request->ip()));
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perMinute(5)->by('register:'.$request->ip()));
        RateLimiter::for('auth-recovery', fn (Request $request) => [
            Limit::perMinute(10)->by('recovery-ip:'.$request->ip()),
            Limit::perMinute(3)->by('recovery:'.hash('sha256', strtolower((string) $request->input('email', $request->user()?->email ?? '')))),
        ]);
        RateLimiter::for('auth-credentials', fn (Request $request) => Limit::perMinute(5)->by('credentials:'.$request->user()?->id.'|'.$request->ip()));
        RateLimiter::for('auth-actions', fn (Request $request) => Limit::perMinute(120)->by('actions:'.$request->user()?->id.'|'.$request->ip()));
        RateLimiter::for('auth-mfa', fn (Request $request) => [Limit::perMinute(6)->by('mfa-user:'.$request->user()?->id), Limit::perMinute(20)->by('mfa-ip:'.$request->ip())]);
        RateLimiter::for('auth-invite', fn (Request $request) => Limit::perMinute(20)->by('invite:'.$request->user()?->id.'|'.$request->ip()));
        RateLimiter::for('health', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
    }
}
