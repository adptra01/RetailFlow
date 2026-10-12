<?php

namespace App\Actions\Promotions;

use App\Models\Promotion;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Evaluasi promosi diskon aktif terhadap keranjang (FR-06).
 *
 * Murni komputasi — tidak menulis apa pun. Hasilnya dipakai UI kasir untuk
 * menampilkan prakiraan diskon dan mengisi snapshot saat checkout.
 *
 * Aturan penggabungan (FR-06: "aturan penggabungan diskon harus jelas"):
 *  1. satu promosi TERBAIK per produk (scope=product) — diskon terbesar;
 *     bila sama besar, `priority` tertinggi menang;
 *  2. satu promosi TERBAIK untuk seluruh transaksi (scope=transaction);
 *  3. lapisan produk dan transaksi boleh bergabung (total = kedua lapisan);
 *  4. hanya promosi yang memenuhi `min_purchase_minor` (dihitung dari
 *     SUBTOTAL transaksi) yang berlaku;
 *  5. nilai diskon tidak pernah negatif, dibatasi subtotal yang didiskon,
 *     dan di-cap oleh `max_discount_minor`;
 *  6. periode `starts_at`/`ends_at` divalidasi terhadap waktu evaluasi.
 */
class EvaluatePromotions
{
    /**
     * @param  array{
     *     tenant_id?: string|null,
     *     items: list<array{product_id: int, quantity: int, unit_price_minor: int}>,
     *     at?: Carbon|string|null,
     * }  $data
     * @return array{
     *     subtotal_minor: int,
     *     line_discounts: list<array{product_id: int, promotion_id: int, promotion_name: string, discount_minor: int, discount_type: string, discount_value: int}>,
     *     transaction_discount: array{promotion_id: int, promotion_name: string, discount_minor: int, discount_type: string, discount_value: int}|null,
     *     total_discount_minor: int,
     * }
     */
    public function handle(array $data): array
    {
        $tenantId = $data['tenant_id'] ?? app(TenantContext::class)->id();
        $rawAt = $data['at'] ?? 'now';
        $at = $rawAt instanceof Carbon ? $rawAt : Carbon::parse($rawAt);

        $lines = $this->aggregateLines($data['items'] ?? []);
        $subtotal = array_sum(array_column($lines, 'subtotal_minor'));

        /** @var Collection<int, Promotion> $promotions */
        $promotions = Promotion::query()
            ->with('products')
            ->activeAt($at)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->orderByDesc('priority')
            ->get();

        $lineDiscounts = $this->resolveLineDiscounts($lines, $subtotal, $promotions);
        $transactionDiscount = $this->resolveTransactionDiscount($subtotal, $promotions);

        $total = array_sum(array_column($lineDiscounts, 'discount_minor'))
            + ($transactionDiscount['discount_minor'] ?? 0);

        return [
            'subtotal_minor' => $subtotal,
            'line_discounts' => $lineDiscounts,
            'transaction_discount' => $transactionDiscount,
            'total_discount_minor' => $total,
        ];
    }

    /**
     * Menggabungkan baris keranjang per produk (kuantitas & subtotal minor).
     *
     * @return array<int, array{quantity: int, subtotal_minor: int}>
     */
    private function aggregateLines(mixed $rawItems): array
    {
        $lines = [];

        foreach ((array) $rawItems as $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = max(0, (int) ($item['quantity'] ?? 0));
            $price = max(0, (int) ($item['unit_price_minor'] ?? 0));

            if ($productId <= 0 || $quantity <= 0) {
                continue;
            }

            $lines[$productId] ??= ['quantity' => 0, 'subtotal_minor' => 0];
            $lines[$productId]['quantity'] += $quantity;
            $lines[$productId]['subtotal_minor'] += $quantity * $price;
        }

        return $lines;
    }

    /**
     * @param  array<int, array{quantity: int, subtotal_minor: int}>  $lines
     * @param  Collection<int, Promotion>  $promotions
     * @return list<array{promotion_id: int, promotion_name: string, discount_minor: int, discount_type: string, discount_value: int}>
     */
    private function resolveLineDiscounts(array $lines, int $subtotal, $promotions): array
    {
        $result = [];

        foreach ($lines as $productId => $line) {
            $best = null;

            foreach ($promotions as $promotion) {
                if (! $promotion->isProductScoped()) {
                    continue;
                }

                if (! $promotion->products->contains('id', $productId)) {
                    continue;
                }

                if ($subtotal < $promotion->min_purchase_minor) {
                    continue;
                }

                $minor = $promotion->discountFor($line['subtotal_minor']);

                if ($minor <= 0) {
                    continue;
                }

                // Promosi diurutkan `priority DESC`, jadi diskon sama besar
                // dipecahkan oleh prioritas (yang pertama ditemui menang).
                if ($best === null || $minor > $best['discount_minor']) {
                    $best = $this->shape($promotion, $minor);
                }
            }

            if ($best !== null) {
                $result[] = ['product_id' => $productId] + $best;
            }
        }

        return $result;
    }

    /**
     * @param  Collection<int, Promotion>  $promotions
     * @return array{promotion_id: int, promotion_name: string, discount_minor: int, discount_type: string, discount_value: int}|null
     */
    private function resolveTransactionDiscount(int $subtotal, $promotions): ?array
    {
        $best = null;

        foreach ($promotions as $promotion) {
            if (! $promotion->isTransactionScoped()) {
                continue;
            }

            if ($subtotal < $promotion->min_purchase_minor) {
                continue;
            }

            $minor = $promotion->discountFor($subtotal);

            if ($minor <= 0) {
                continue;
            }

            if ($best === null || $minor > $best['discount_minor']) {
                $best = $this->shape($promotion, $minor);
            }
        }

        return $best;
    }

    /**
     * @return array{promotion_id: int, promotion_name: string, discount_minor: int, discount_type: string, discount_value: int}
     */
    private function shape(Promotion $promotion, int $discountMinor): array
    {
        return [
            'promotion_id' => $promotion->id,
            'promotion_name' => $promotion->name,
            'discount_minor' => $discountMinor,
            'discount_type' => (string) $promotion->discount_type,
            'discount_value' => (int) $promotion->discount_value,
        ];
    }
}
