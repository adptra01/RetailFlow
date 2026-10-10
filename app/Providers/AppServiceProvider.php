<?php

namespace App\Providers;

use App\Backup\BackupManager;
use App\Models\Product;
use App\Models\User;
use App\Observers\StockObserver;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
        $this->app->singleton(BackupManager::class, fn () => BackupManager::forDefault());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        Product::observe(StockObserver::class);

        // FR-12: izin dari katalog spatie permission bisa dipakai lewat `can()`.
        // Hanya MEMBERIKAN akses bila izin dimiliki; null = lanjut evaluasi
        // policy/gate normal, sehingga tidak mengintervensi kebijakan lain.
        // `hasPermissionTo()` melempar PermissionDoesNotExist untuk nama izin
        // yang belum terdaftar (mis. izin legacy di view Blade pada DB yang
        // belum di-seed) — ditangkap agar jatuh ke eval normal, bukan error.
        Gate::before(function (?User $user, string $ability): ?bool {
            if ($user === null) {
                return null;
            }

            try {
                return $user->hasPermissionTo($ability) ? true : null;
            } catch (PermissionDoesNotExist) {
                return null;
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // Surface N+1 queries and silent attribute issues during local development.
        Model::preventLazyLoading($this->app->environment('local'));

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
