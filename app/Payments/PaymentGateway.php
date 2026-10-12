<?php

namespace App\Payments;

use App\Exceptions\Payments\InvalidWebhookSignature;
use App\Exceptions\Payments\PaymentGatewayUnavailable;
use App\Support\Money\Money;

/**
 * Port payment provider adapter (Mode B — Gateway, plan §14).
 *
 * Aplikasi HARUS tetap berjalan tanpa gateway terpasang (FR-01: "Menggunakan
 * aplikasi tanpa menghubungkan payment gateway"), jadi port ini selalu punya
 * implementasi default `NullPaymentGateway` yang menolak dengan jelas alih-alih
 * diam-diam menyatakan pembayaran berhasil.
 *
 * Aturan untuk setiap implementasi:
 *  - kredensial TIDAK diambil dari database lokal (FR-05) melainkan dari
 *    environment/secret store;
 *  - operasi pembuatan/refund bersifat idempotent terhadap `clientRequestId`
 *    (FR-04) — percobaan ulang tidak boleh menghasilkan dua transaksi;
 *  - status yang tidak dikenali dipetakan ke `ProviderPaymentStatus::Unknown`,
 *    bukan ke `Paid`.
 */
interface PaymentGateway
{
    /**
     * Kunci penyedia pada registry (mis. `none`). Harus sama dengan kunci yang
     * dipakai di `config('pos.payments.gateways')`.
     */
    public function key(): string;

    /**
     * Apakah kredensial penyedia tersedia pada instalasi ini. Bila false,
     * operasi yang memerlukan gateway akan ditolak dengan pesan yang jelas.
     */
    public function isConfigured(): bool;

    /**
     * Membuat transaksi pembayaran di penyedia.
     *
     * @throws PaymentGatewayUnavailable bila penyedia belum dikonfigurasi
     */
    public function createCharge(PaymentChargeRequest $request): PaymentChargeResult;

    /**
     * Mengambil status terkini sebuah transaksi pembayaran (rekonsiliasi).
     * Tidak melempar ketika penyedia belum dikonfigurasi: mengembalikan
     * `ProviderPaymentStatus::Unknown` agar pemanggil tidak menyimpulkan
     * pembayaran berhasil.
     */
    public function fetchStatus(string $providerReference): PaymentStatusResult;

    /**
     * Memverifikasi tanda tangan callback/webhook lalu menerjemahkannya.
     *
     * @param  array<string, string>  $headers
     *
     * @throws InvalidWebhookSignature bila tanda tangan tidak sah
     * @throws PaymentGatewayUnavailable bila penyedia belum dikonfigurasi
     */
    public function verifyAndParseWebhook(string $rawBody, array $headers): ProviderWebhookEvent;

    /**
     * Apakah penyedia mendukung refund (plan §14: "Refund bila didukung").
     */
    public function supportsRefund(): bool;

    /**
     * Mengembalikan dana (bila didukung).
     *
     * @throws PaymentGatewayUnavailable bila penyedia belum dikonfigurasi atau
     *                                   tidak mendukung refund
     */
    public function refund(string $providerReference, Money $amount): PaymentStatusResult;
}
