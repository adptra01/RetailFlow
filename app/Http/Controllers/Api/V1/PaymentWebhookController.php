<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Payments\ApplyPaymentWebhook;
use App\Exceptions\Payments\InvalidWebhookSignature;
use App\Exceptions\Payments\PaymentGatewayUnavailable;
use App\Exceptions\Payments\UnknownPaymentProvider;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/v1/payments/webhooks/{provider}` (§18, plan §14/§15).
 *
 * Otentikasinya adalah TANDA TANGAN, bukan sesi pengguna maupun token
 * perangkat: yang menghubungi endpoint ini adalah penyedia pembayaran, yang
 * tidak punya kredensial pengguna (§15 "Webhook memvalidasi tanda tangan").
 * Karena itu rute ini berada di luar grup middleware `device`, dan badan
 * permintaan yang TIDAK terverifikasi tidak pernah menyentuh domain.
 *
 * Kode jawaban sengaja dibedakan agar penyedia berhenti mengirim ulang
 * peristiwa yang tidak akan pernah berhasil:
 *   401 — tanda tangan tidak sah (jangan diproses: §15);
 *   404 — kunci penyedia tidak terdaftar pada instalasi ini;
 *   503 — penyedia belum dikonfigurasi, jadi tidak ada tanda tangan yang dapat
 *         diverifikasi (FR-01: aplikasi tetap berjalan tanpa gateway);
 *   200 — peristiwa sah; `outcome` menjelaskan apa yang terjadi
 *         (applied/duplicate/ignored/unmatched).
 */
class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, ApplyPaymentWebhook $action): JsonResponse
    {
        try {
            $result = $action->handle($provider, $request->getContent(), $this->headers($request));
        } catch (UnknownPaymentProvider $exception) {
            return response()->json($this->error($provider, $exception->getMessage()), 404);
        } catch (InvalidWebhookSignature $exception) {
            return response()->json($this->error($provider, $exception->getMessage()), 401);
        } catch (PaymentGatewayUnavailable $exception) {
            return response()->json($this->error($provider, $exception->getMessage()), 503);
        }

        return response()->json($result->toArray());
    }

    /**
     * Ratakan header menjadi nama lowercase → nilai (kontrak adaptor).
     *
     * @return array<string, string>
     */
    private function headers(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower($name)] = $values[0] ?? '';
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function error(string $provider, string $message): array
    {
        return [
            'provider' => $provider,
            'message' => $message,
        ];
    }
}
