<?php

namespace Tests\Support;

use App\Exceptions\Payments\InvalidWebhookSignature;
use App\Exceptions\Payments\PaymentGatewayUnavailable;
use App\Payments\PaymentChargeRequest;
use App\Payments\PaymentChargeResult;
use App\Payments\PaymentGateway;
use App\Payments\PaymentStatusResult;
use App\Payments\ProviderPaymentStatus;
use App\Payments\ProviderWebhookEvent;
use App\Support\Money\Money;

/**
 * Adaptor payment gateway palsu untuk pengujian (bukan kode produksi).
 *
 * Membuktikan port `PaymentGateway` dapat diimplementasikan tanpa vendor nyata,
 * dan memberi irisan berikutnya (webhook + rekonsiliasi) sebuah penyedia
 * terkendali: ia menandatangani body dengan HMAC-SHA256 seperti penyedia
 * sungguhan, sehingga verifikasi tanda tangan dapat diuji sungguhan.
 */
final class FakePaymentGateway implements PaymentGateway
{
    /** Semua permintaan yang masuk, termasuk percobaan ulang. */
    /** @var list<PaymentChargeRequest> */
    public array $attempts = [];

    /** Transaksi yang benar-benar dibuat, dipetakan per reference. */
    /** @var array<string, PaymentChargeRequest> */
    public array $charges = [];

    /** @var list<array{reference: string, minor: int}> */
    public array $refunds = [];

    /** @var array<string, PaymentStatusResult> */
    private array $statuses = [];

    /** @var array<string, string> client_request_id → reference (idempotensi) */
    private array $referenceByRequest = [];

    /** Penyedia "terputus" (simulasi timeout jaringan — §14 penanganan timeout). */
    private bool $reachable = true;

    public function __construct(
        private readonly string $key = 'fake',
        private readonly bool $configured = true,
        private ProviderPaymentStatus $chargeStatus = ProviderPaymentStatus::Pending,
        private readonly bool $refundsSupported = true,
        private readonly string $webhookSecret = 'test-webhook-secret',
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function createCharge(PaymentChargeRequest $request): PaymentChargeResult
    {
        if (! $this->configured) {
            throw PaymentGatewayUnavailable::providerNotConfigured($this->key);
        }

        $this->attempts[] = $request;

        // Idempotensi: kunci yang sama mengembalikan transaksi yang sama
        // (FR-04), bukan membuat transaksi kedua.
        if (isset($this->referenceByRequest[$request->clientRequestId])) {
            $reference = $this->referenceByRequest[$request->clientRequestId];

            return $this->resultFor($reference);
        }

        $reference = 'fake-ref-'.(count($this->charges) + 1);

        $this->referenceByRequest[$request->clientRequestId] = $reference;
        $this->charges[$reference] = $request;
        $this->statuses[$reference] = new PaymentStatusResult(
            $reference,
            $this->chargeStatus,
            $this->referencePayload($reference, $request),
        );

        return $this->resultFor($reference);
    }

    public function fetchStatus(string $providerReference): PaymentStatusResult
    {
        // Penyedia tidak dapat dijangkau: §14 "timeout bukan berarti gagal" —
        // adaptor sungguhan memetakan kegagalan jaringan ke Unknown.
        if (! $this->reachable) {
            return new PaymentStatusResult($providerReference, ProviderPaymentStatus::Unknown);
        }

        return $this->statuses[$providerReference]
            ?? new PaymentStatusResult($providerReference, ProviderPaymentStatus::Unknown);
    }

    public function verifyAndParseWebhook(string $rawBody, array $headers): ProviderWebhookEvent
    {
        if (! $this->configured) {
            throw PaymentGatewayUnavailable::providerNotConfigured($this->key);
        }

        $signature = $headers['x-fake-signature'] ?? null;

        if ($signature === null) {
            throw InvalidWebhookSignature::missingHeader($this->key, 'X-Fake-Signature');
        }

        if (! hash_equals($this->sign($rawBody), $signature)) {
            throw InvalidWebhookSignature::forProvider($this->key);
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($rawBody, true, flags: JSON_THROW_ON_ERROR);

        return new ProviderWebhookEvent(
            eventId: (string) $data['event_id'],
            providerReference: (string) $data['reference'],
            // Status yang tidak dikenali dipetakan ke Unknown — bukan ke Paid
            // (aturan port di `PaymentGateway`).
            status: ProviderPaymentStatus::tryFrom((string) $data['status']) ?? ProviderPaymentStatus::Unknown,
            raw: $data,
        );
    }

    public function supportsRefund(): bool
    {
        return $this->refundsSupported;
    }

    public function refund(string $providerReference, Money $amount): PaymentStatusResult
    {
        if (! $this->configured) {
            throw PaymentGatewayUnavailable::providerNotConfigured($this->key);
        }

        if (! $this->refundsSupported) {
            throw PaymentGatewayUnavailable::refundsNotSupported($this->key);
        }

        $this->refunds[] = ['reference' => $providerReference, 'minor' => $amount->minor()];

        $this->statuses[$providerReference] = new PaymentStatusResult(
            $providerReference,
            ProviderPaymentStatus::Refunded,
        );

        return $this->statuses[$providerReference];
    }

    /** Menandatangani body webhook persis seperti penyedia sungguhan. */
    public function sign(string $rawBody): string
    {
        return hash_hmac('sha256', $rawBody, $this->webhookSecret);
    }

    /** Menyusun body webhook bertanda tangan yang sah. */
    public function webhookBody(string $eventId, string $reference, ProviderPaymentStatus $status): string
    {
        return json_encode([
            'event_id' => $eventId,
            'reference' => $reference,
            'status' => $status->value,
        ], JSON_THROW_ON_ERROR);
    }

    /** Mengubah status penyedia (mis. setelah pelanggan membayar). */
    public function markAs(string $providerReference, ProviderPaymentStatus $status): void
    {
        $raw = $this->statuses[$providerReference]->raw ?? [];

        $this->statuses[$providerReference] = new PaymentStatusResult($providerReference, $status, $raw);
    }

    /** Simulasi penyedia tidak terjangkau (semua fetchStatus → Unknown). */
    public function markUnreachable(): void
    {
        $this->reachable = false;
    }

    public function markReachable(): void
    {
        $this->reachable = true;
    }

    private function resultFor(string $reference): PaymentChargeResult
    {
        $status = $this->statuses[$reference];

        return new PaymentChargeResult(
            providerReference: $reference,
            status: $status->status,
            raw: $status->raw,
            qrPayload: 'FAKE-QRIS-PAYLOAD',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function referencePayload(string $reference, PaymentChargeRequest $request): array
    {
        return [
            'reference' => $reference,
            'client_request_id' => $request->clientRequestId,
            'amount_minor' => $request->amount->minor(),
            'currency' => $request->currency,
        ];
    }
}
