<?php

namespace App\Payments;

/**
 * Peristiwa callback/webhook yang sudah DIVERIFIKASI oleh adaptor penyedia
 * (plan §14 "Callbacks/webhooks — memvalidasi tanda tangan dan mencegah
 * replay").
 *
 * Adaptor bertanggung jawab memverifikasi tanda tangan sebelum objek ini
 * dibuat: bila verifikasi gagal, adaptor melempar
 * `InvalidWebhookSignature` dan TIDAK mengembalikan peristiwa. Dengan begitu
 * lapisan di atasnya hanya pernah melihat data yang sudah tepercaya.
 *
 * `eventId` dipakai untuk idempotensi callback: satu peristiwa penyedia hanya
 * boleh diterapkan sekali, walau penyedia mengirim ulang.
 */
final readonly class ProviderWebhookEvent
{
    /**
     * @param  array<string, mixed>  $raw
     */
    public function __construct(
        public string $eventId,
        public string $providerReference,
        public ProviderPaymentStatus $status,
        public array $raw = [],
    ) {}
}
