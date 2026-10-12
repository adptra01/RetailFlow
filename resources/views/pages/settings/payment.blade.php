<?php

use App\Actions\Payments\ConfigureQris;
use App\Enums\Permission;
use App\Exceptions\Auth\ActionForbidden;
use App\Exceptions\Payments\InvalidQrisPayload;
use App\Models\Tenant;
use App\Support\Payments\QrisPayload;
use App\Support\Qr\SvgQrCode;
use Flux\Flux;

use function Laravel\Folio\middleware;
use function Laravel\Folio\name;
use function Livewire\Volt\mount;
use function Livewire\Volt\state;

name('settings.payment');

middleware('auth');
middleware('verified');
// Konfigurasi payment gateway adalah tindakan sensitif FR-12: hanya pemegang
// izin `payment_gateway.configure` (Owner/Admin).
middleware('can:'.Permission::ConfigurePaymentGateway->value);

state([
    'qris_payload' => '',
    'qris_merchant_name' => '',
    'reason' => '',
    'saved_payload' => null,
    'saved_merchant' => null,
]);

mount(function () {
    $tenant = $this->tenant();

    if ($tenant === null) {
        return;
    }

    $this->saved_payload = $tenant->qris_payload;
    $this->saved_merchant = $tenant->qris_merchant_name;
    $this->qris_payload = (string) ($tenant->qris_payload ?? '');
    $this->qris_merchant_name = (string) ($tenant->qris_merchant_name ?? '');
});

/**
 * Tenant pengguna aktif. Null bila akun belum terhubung ke tenant — halaman
 * fail-closed: tanpa tenant, tidak ada yang ditampilkan maupun disimpan.
 */
$tenant = function (): ?Tenant {
    $tenantId = auth()->user()?->tenant_id;

    return $tenantId === null ? null : Tenant::query()->find($tenantId);
};

$hasSaved = function (): bool {
    return is_string($this->saved_payload) && trim($this->saved_payload) !== '';
};

$savedSvg = function (): ?string {
    return $this->hasSaved() ? SvgQrCode::render($this->saved_payload, 240) : null;
};

/**
 * Pratinjau QR dari isi form — hanya muncul bila payload lolos validasi.
 */
$inputSvg = function (): ?string {
    $payload = QrisPayload::normalize((string) $this->qris_payload);

    if ($payload === '' || ! QrisPayload::isValid($payload)) {
        return null;
    }

    return SvgQrCode::render($payload, 240);
};

$inputState = function (): string {
    $payload = QrisPayload::normalize((string) $this->qris_payload);

    if ($payload === '') {
        return 'empty';
    }

    return QrisPayload::isValid($payload) ? 'valid' : 'invalid';
};

$inputMerchantName = function (): ?string {
    $payload = QrisPayload::normalize((string) $this->qris_payload);

    return QrisPayload::isValid($payload) ? QrisPayload::parse($payload)->merchantName() : null;
};

$save = function () {
    $this->validate([
        'qris_payload' => 'required|string|max:'.QrisPayload::MAX_LENGTH,
        'qris_merchant_name' => 'nullable|string|max:150',
        'reason' => 'nullable|string|max:255',
    ]);

    $tenant = $this->tenant();

    if ($tenant === null) {
        Flux::toast(variant: 'error', text: __('Akun ini belum terhubung ke tenant.'));

        return;
    }

    try {
        $tenant = app(ConfigureQris::class, ['tenantId' => $tenant->id])->configure(
            auth()->user(),
            $this->qris_payload,
            $this->qris_merchant_name !== '' ? $this->qris_merchant_name : null,
            $this->reason !== '' ? $this->reason : null,
        );
    } catch (InvalidQrisPayload $e) {
        $this->addError('qris_payload', $e->getMessage());

        return;
    } catch (ActionForbidden $e) {
        Flux::toast(variant: 'error', text: $e->getMessage());

        return;
    }

    $this->saved_payload = $tenant->qris_payload;
    $this->saved_merchant = $tenant->qris_merchant_name;
    $this->qris_payload = (string) ($tenant->qris_payload ?? '');
    $this->qris_merchant_name = (string) ($tenant->qris_merchant_name ?? '');
    $this->reason = '';

    Flux::toast(variant: 'success', text: __('QRIS statis tersimpan.'));
};

$clear = function () {
    $tenant = $this->tenant();

    if ($tenant === null) {
        Flux::toast(variant: 'error', text: __('Akun ini belum terhubung ke tenant.'));

        return;
    }

    try {
        app(ConfigureQris::class, ['tenantId' => $tenant->id])
            ->clear(auth()->user(), $this->reason !== '' ? $this->reason : null);
    } catch (ActionForbidden $e) {
        Flux::toast(variant: 'error', text: $e->getMessage());

        return;
    }

    $this->saved_payload = null;
    $this->saved_merchant = null;
    $this->qris_payload = '';
    $this->qris_merchant_name = '';
    $this->reason = '';

    Flux::toast(variant: 'success', text: __('Konfigurasi QRIS dihapus.'));
};

?>

<x-layouts::app :title="__('Payment Settings')">
    @volt
    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
        <flux:breadcrumbs>
            <flux:breadcrumbs.item href="#">{{ __('Home') }}</flux:breadcrumbs.item>
            <flux:breadcrumbs.item>{{ __('Payment Settings') }}</flux:breadcrumbs.item>
        </flux:breadcrumbs>

        <div>
            <flux:heading size="xl">{{ __('Payment Settings') }}</flux:heading>
            <flux:subheading>
                {{ __('Kelola QRIS statis toko. POS akan menampilkan QR ini saat kasir memilih metode QRIS.') }}
            </flux:subheading>
        </div>

        @if ($this->tenant() === null)
            <flux:callout variant="warning" icon="exclamation-triangle">
                <flux:callout.heading>{{ __('Akun belum terhubung ke tenant') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Konfigurasi QRIS bersifat per-toko. Hubungi administrator untuk menautkan akun ini ke tenant.') }}
                </flux:callout.text>
            </flux:callout>
        @else
            <flux:callout icon="information-circle">
                <flux:callout.heading>{{ __('QRIS statis hanya instruksi pembayaran') }}</flux:callout.heading>
                <flux:callout.text>
                    {{ __('Sistem tidak menyatakan pembayaran terverifikasi hanya karena kasir menekan tombol dibayar. Transaksi QRIS dicatat sebagai "dicatat kasir" (belum terverifikasi). Verifikasi otomatis baru tersedia setelah integrasi gateway resmi.') }}
                </flux:callout.text>
            </flux:callout>

            <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
                {{-- Form konfigurasi --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="lg">{{ __('QRIS Merchant') }}</flux:heading>
                    <flux:subheading>{{ __('Tempel string QRIS statis (bukan gambar).') }}</flux:subheading>

                    <form wire:submit="save" class="mt-6 space-y-5">
                        <flux:textarea wire:model.blur="qris_payload" :label="__('String QRIS statis')" rows="4"
                            :placeholder="__('00020101021126...')"
                            description="{{ __('Umumnya dimulai dengan 00020101021126… dan diakhiri checksum tag 63.') }}" />

                        @if ($this->inputState() === 'invalid')
                            <flux:callout variant="danger" icon="x-circle">
                                <flux:callout.text>
                                    {{ __('String belum valid sebagai QRIS statis Indonesia. Periksa kembali hasil salin/scan.') }}
                                </flux:callout.text>
                            </flux:callout>
                        @elseif ($this->inputState() === 'valid')
                            <flux:callout variant="success" icon="check-circle">
                                <flux:callout.text>
                                    {{ __('QRIS statis valid') }}@if ($this->inputMerchantName()) — {{ $this->inputMerchantName() }}@endif.
                                </flux:callout.text>
                            </flux:callout>
                        @endif

                        <flux:input wire:model="qris_merchant_name" :label="__('Nama merchant (opsional)')"
                            :placeholder="__('Kosongkan untuk memakai nama di dalam QRIS')" maxlength="150" />

                        <flux:input wire:model="reason" :label="__('Alasan perubahan (opsional)')"
                            :placeholder="__('Contoh: QRIS baru dari bank')" maxlength="255" />

                        <div class="flex items-center justify-between gap-3 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                            <div class="flex gap-2">
                                <flux:button variant="primary" type="submit" icon="check">
                                    {{ __('Simpan QRIS') }}
                                </flux:button>

                                @if ($this->hasSaved())
                                    <flux:button variant="danger" type="button" icon="trash" wire:click="clear"
                                        wire:confirm="{{ __('Hapus konfigurasi QRIS toko ini?') }}">
                                        {{ __('Hapus') }}
                                    </flux:button>
                                @endif
                            </div>
                        </div>
                    </form>
                </div>

                {{-- Pratinjau / status --}}
                <div class="rounded-xl border border-zinc-200 bg-white p-6 dark:border-zinc-700 dark:bg-zinc-800">
                    <flux:heading size="lg">{{ __('Pratinjau') }}</flux:heading>

                    @php($preview = $this->inputSvg() ?? $this->savedSvg())

                    @if ($preview === null)
                        <div class="mt-6 flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-600">
                            <flux:icon.qr-code class="size-10 text-zinc-400" />
                            <flux:text>{{ __('Belum ada QRIS yang dikonfigurasi') }}</flux:text>
                            <flux:text class="text-xs text-zinc-500">
                                {{ __('Tempel string QRIS di sebelah kiri untuk melihat pratinjau.') }}
                            </flux:text>
                        </div>
                    @else
                        <div class="mt-6 flex flex-col items-center gap-3">
                            <div class="rounded-lg bg-white p-3">
                                {!! $preview !!}
                            </div>
                            <div class="text-center">
                                <flux:text class="font-medium">
                                    {{ $this->inputState() === 'valid' ? ($this->inputMerchantName() ?? $this->saved_merchant) : $this->saved_merchant }}
                                </flux:text>
                                <flux:text class="text-xs text-zinc-500">{{ __('Pindai untuk membayar') }}</flux:text>
                            </div>
                        </div>
                    @endif

                    <div class="mt-6 space-y-1 border-t border-zinc-200 pt-4 dark:border-zinc-700">
                        <flux:text class="text-sm">
                            {{ __('Status') }}:
                            @if ($this->hasSaved())
                                <flux:badge color="green" size="sm">{{ __('Aktif') }}</flux:badge>
                            @else
                                <flux:badge color="zinc" size="sm">{{ __('Belum dikonfigurasi') }}</flux:badge>
                            @endif
                        </flux:text>
                    </div>
                </div>
            </div>
        @endif
    </div>
    @endvolt
</x-layouts::app>
