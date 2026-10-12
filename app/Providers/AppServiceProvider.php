<?php

namespace App\Providers;

use App\Backup\BackupManager;
use App\Models\Product;
use App\Models\User;
use App\Observers\StockObserver;
use App\Payments\GatewayRegistry;
use App\Payments\NullPaymentGateway;
use App\Payments\PaymentGateway;
use App\Printing\NullReceiptPrinter;
use App\Printing\ReceiptPrinter;
use App\Support\Devices\DeviceContext;
use App\Support\Sync\DatabaseSyncChangeFeed;
use App\Support\Sync\Handlers\SaleReturnSyncHandler;
use App\Support\Sync\Handlers\SaleSyncHandler;
use App\Support\Sync\HttpSyncTransport;
use App\Support\Sync\SyncChangeFeed;
use App\Support\Sync\SyncOperationApplier;
use App\Support\Sync\SyncTransport;
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
        $this->app->singleton(DeviceContext::class);
        $this->app->singleton(BackupManager::class, fn () => BackupManager::forDefault());

        // Port printer: adaptor default no-op (printer opsional, §20).
        $this->app->bind(ReceiptPrinter::class, NullReceiptPrinter::class);

        // Port payment gateway (Mode B, §14): registry penyedia + adaptor
        // default instalasi. Tanpa gateway terpasang, adaptor `none` dipakai
        // sehingga aplikasi tetap berjalan dan menolak dengan jelas (FR-01).
        $this->app->singleton(GatewayRegistry::class, fn ($app) => new GatewayRegistry(
            providers: config('pos.payments.gateways', []),
            defaultKey: (string) config('pos.payments.default_gateway', NullPaymentGateway::KEY),
            resolver: fn (string $class) => $app->make($class),
        ));

        $this->app->bind(PaymentGateway::class, fn ($app) => $app->make(GatewayRegistry::class)->default());

        // Sumber perubahan untuk pull: jurnal `sync_changes` (item Sprint 4.4
        // "Cursor sinkronisasi"). `NullSyncChangeFeed` tersedia sebagai
        // adaptor kosong untuk pengujian/fallback.
        $this->app->bind(SyncChangeFeed::class, DatabaseSyncChangeFeed::class);

        // Transport push ke server (item Sprint 4.5 "Retry dengan backoff").
        $this->app->bind(SyncTransport::class, HttpSyncTransport::class);

        // Registri handler penerapan operasi push ke domain (item Sprint 4.6
        // "Idempotensi server"). Tambahkan handler baru seiring bertambahnya
        // tipe entitas yang disinkronkan.
        $this->app->singleton(SyncOperationApplier::class, fn () => new SyncOperationApplier([
            new SaleSyncHandler,
            new SaleReturnSyncHandler,
        ]));
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
