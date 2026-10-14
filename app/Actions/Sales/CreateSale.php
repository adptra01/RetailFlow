<?php

namespace App\Actions\Sales;

use App\Enums\AuditAction;
use App\Enums\PaymentMethod;
use App\Enums\PaymentVerification;
use App\Enums\SyncOperationType;
use App\Exceptions\Payments\QrisNotConfigured;
use App\Exceptions\Sales\CreditLimitExceeded;
use App\Exceptions\Sales\InsufficientStock;
use App\Exceptions\Sales\InvalidPaymentAmount;
use App\Exceptions\Sales\InvalidPaymentVerification;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Receivable;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Models\TransactionPayment;
use App\Support\Money\Money;
use App\Support\Sync\Outbox;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Single domain entry point for creating a sale (plan §24 Fase 2).
 *
 * Guarantees, enforced here and nowhere else:
 *  - atomicity: the whole sale (order, items, stock, ledger, audit) commits
 *    or rolls back together (§17.3);
 *  - idempotency: a replay with the same client_request_id returns the
 *    original sale instead of creating a duplicate;
 *  - stock safety: quantity is validated against locked rows, converted into
 *    base stock units via the unit conversion factor, and never decremented
 *    past zero (FR-08) unless the owner enables the negative-stock policy
 *    (§11.4, config `pos.inventory.allow_negative_stock`);
 *  - discount honesty (FR-06): snapshots are taken on the line items so later
 *    price/discount changes never alter historical sales;
 *  - receivable basics (FR-07): when payment is partial the sale becomes a
 *    credit sale — a customer is required, only the unpaid balance moves
 *    into the customer balance, the receivable gets a due date
 *    (`pos.receivables.default_due_days`, overridable per call), and the
 *    customer's credit limit is enforced or warned about per store policy
 *    (`pos.receivables.enforce_credit_limit`);
 *  - traceability: every unit of stock leaving the shelf is recorded in the
 *    ledger with reference_type "sale" (FR-08);
 *  - offline-ready (§17.3 langkah 9 / §11.1): bila penjualan berasal dari
 *    perangkat (`device_id` ada), satu operasi outbox ditulis DI DALAM
 *    transaksi yang sama (transactional outbox) agar penjualan offline tidak
 *    hilang saat crash dan dapat dikirim ke server. Pemanggil dapat
 *    mematikannya dengan `enqueue_for_sync => false` (dipakai sisi server saat
 *    menerapkan operasi dari perangkat, agar tidak memantul);
 *  - honest money: all amounts are integer minor units (FR-04 / §17.1);
 *  - honest payment (FR-05): `payment_method` divalidasi terhadap katalog
 *    `PaymentMethod`. Metode yang hanya berupa instruksi pembayaran (transfer
 *    manual, QRIS statis) tidak pernah menghasilkan `payment_verification`
 *    "terverifikasi" — status itu hanya datang dari gateway resmi. QRIS statis
 *    menuntut nominal tepat (bayar == total, tanpa kembalian) dan menolak
 *    transaksi bila tenant belum mengunggah QRIS.
 *  - mixed payment (FR-05 "Pembayaran campuran"): bila `payments` memuat lebih
 *    dari satu tender, penjualan dicatat dengan `payment_method = mixed` dan
 *    tiap tender disimpan di `transaction_payments`. Jumlah tender harus sama
 *    dengan `paid_minor`, total tender non-tunai tidak boleh melebihi total
 *    (kembalian hanya boleh berasal dari tunai), dan tender QRIS statis tetap
 *    menuntut tenant yang sudah mengunggah QRIS.
 *
 * Money math (IDR minor exponent 0, see config/pos.php):
 *  - item subtotal      = unit_price_minor * quantity
 *  - item discount      = discount_minor (per line, default 0)
 *  - transaction total  = subtotal - discount_minor (nominal wins over percent)
 *  - paid > total       = cash only, change returned; non-cash overpay rejected
 *  - paid == total      = paid in full
 *  - paid < total       = credit sale (customer required), balance += unpaid
 *  - split              = paid_minor = sum(tenders); non-cash tenders <= total
 */
class CreateSale
{
    /**
     * @param  array{
     *     items: list<array{
     *         product_id: int,
     *         quantity: int,
     *         unit_price_minor: int,
     *         product_unit_id?: int|null,
     *         unit_name?: string|null,
     *         conversion_factor?: float|int|null,
     *         discount_minor?: int|null,
     *         promotion_id?: int|null,
     *         promotion_name?: string|null,
     *     }>,
     *     payment_method?: string,
     *     paid_minor?: int,
     *     payment_verification?: string|null,
     *     payments?: list<array{
     *         payment_method: string,
     *         amount_minor: int,
     *         reference?: string|null,
     *         notes?: string|null,
     *     }>,
     *     discount_minor?: int|null,
     *     discount_percent?: int|float|null,
     *     promotion_id?: int|null,
     *     promotion_name?: string|null,
     *     promotion_discount_minor?: int|null,
     *     client_request_id?: string|null,
     *     uuid?: string|null,
     *     user_id: int,
     *     customer_id?: int|null,
     *     customer_name?: string|null,
     *     location_id?: string|null,
     *     device_id?: string|null,
     *     notes?: string|null,
     *     tenant_id?: string|null,
     *     due_at?: string|null,
     *     due_days?: int|null,
     *     allow_negative_stock?: bool|null,
     *     enqueue_for_sync?: bool|null,
     * } $data
     */
    public function handle(array $data): Transaction
    {
        $clientRequestId = $data['client_request_id'] ?? null;

        // §12: kunci idempotensi dicari DI DALAM tenant pemanggil. Tanpa
        // pembatasan ini, `client_request_id` milik tenant lain bisa
        // mengembalikan penjualan tenant lain (kebocoran lintas tenant).
        $tenantId = $data['tenant_id'] ?? app(TenantContext::class)->id();

        if (is_string($clientRequestId) && $clientRequestId !== '') {
            $existing = $this->findByIdempotencyKey($clientRequestId, $tenantId);

            if ($existing) {
                return $existing;
            }
        }

        try {
            return DB::transaction(fn () => $this->persist($data, $clientRequestId));
        } catch (QueryException $e) {
            // Concurrent duplicate submission: the unique constraint fires,
            // so the winner's sale is returned — tetap hanya bila satu tenant.
            if ($this->isIdempotencyViolation($e) && is_string($clientRequestId) && $clientRequestId !== '') {
                return $this->findByIdempotencyKey($clientRequestId, $tenantId) ?? throw $e;
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function persist(array $data, ?string $clientRequestId): Transaction
    {
        $items = $this->normalizeItems($data['items']);
        $subtotal = $this->computeSubtotal($items);
        $discount = $this->resolveTransactionDiscount($data, $subtotal);
        $total = $subtotal->subtract($discount);

        $tenantId = $data['tenant_id'] ?? app(TenantContext::class)->id();

        // FR-05: satu tempat yang memutuskan metode, nominal, kembalian, dan
        // daftar tender (tunggal maupun campuran).
        $settlement = $this->settlePayment($data, $total, $tenantId);

        $paymentMethod = $settlement['method'];
        $verification = $settlement['verification'];
        $paid = $settlement['paid'];
        $change = $settlement['change'];

        // §11.4: kebijakan stok negatif dapat diaktifkan owner. Bila aktif,
        // penjualan tetap diterapkan meski membuat stok negatif; konfliknya
        // dicatat terpisah oleh pemanggil (tidak disembunyikan).
        $allowNegativeStock = (bool) ($data['allow_negative_stock']
            ?? config('pos.inventory.allow_negative_stock', false));

        /** @var array<int, Product> $products */
        $products = Product::query()
            ->whereKey(array_column($items, 'product_id'))
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);

            if (! $product) {
                throw new \InvalidArgumentException("Produk #{$item['product_id']} tidak ditemukan.");
            }

            $this->assertStock($product, $this->baseQuantity($item), $allowNegativeStock);
        }

        $customer = isset($data['customer_id'])
            ? Customer::query()->find($data['customer_id'])
            : null;

        $isCredit = $paid->minor() < $total->minor();

        // FR-07: receivables only exist when there is an unpaid balance.
        if ($isCredit && ! $customer) {
            throw new InvalidPaymentAmount('Pilih pelanggan untuk penjualan kredit.');
        }

        // FR-07: penjualan kredit punya tenggat, dan batas kredit pelanggan
        // diperiksa sebelum apa pun ditulis (kebijakan toko yang menentukan
        // blokir atau peringatan; lihat `assertCreditLimit`).
        $dueAt = null;

        if ($isCredit && $customer) {
            $unpaid = $total->subtract($paid);

            $this->assertCreditLimit($customer, $unpaid, $tenantId);

            $dueAt = $this->resolveDueAt($data);
        }

        $transaction = new Transaction;

        // §17.1/§11.1: identitas sinkronisasi stabil. Bila penjualan berasal
        // dari perangkat, uuid lokal perangkat dipakai apa adanya sehingga
        // record di server dan di perangkat menjadi identitas yang sama — dan
        // operasi lanjutan (mis. void) dapat menunjuk penjualan ini.
        // Set langsung, bukan mass assignment: `uuid` sengaja di luar `$fillable`.
        if (isset($data['uuid']) && $data['uuid'] !== '') {
            $uuid = (string) $data['uuid'];

            if (! Str::isUuid($uuid)) {
                throw new \InvalidArgumentException('uuid penjualan tidak valid.');
            }

            $transaction->uuid = $uuid;
        }

        $transaction->fill([
            'user_id' => $data['user_id'],
            'customer_id' => $customer?->id,
            'customer_name' => $data['customer_name'] ?? $customer?->name ?? '',
            'invoice_number' => $this->generateInvoiceNumber(),
            'payment_method' => $paymentMethod->value,
            'payment_status' => $isCredit ? 'credit' : 'paid',
            'payment_verification' => $verification->value,
            'notes' => $data['notes'] ?? null,
            'client_request_id' => $clientRequestId,
            'subtotal_minor' => $subtotal->minor(),
            'discount_minor' => $discount->minor(),
            // FR-06: snapshot promosi saat penjualan terjadi — perubahan
            // konfigurasi promosi tidak pernah mengubah transaksi historis.
            'promotion_id' => isset($data['promotion_id']) ? (int) $data['promotion_id'] : null,
            'promotion_name' => $data['promotion_name'] ?? null,
            'promotion_discount_minor' => isset($data['promotion_discount_minor'])
                ? max(0, (int) $data['promotion_discount_minor'])
                : null,
            'total_minor' => $total->minor(),
            'paid_minor' => $paid->minor(),
            'change_minor' => $change->minor(),
            'tenant_id' => $tenantId,
            'location_id' => $data['location_id'] ?? null,
            'device_id' => $data['device_id'] ?? null,
        ]);

        $transaction->save();

        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            $unitPrice = new Money($item['unit_price_minor']);
            $lineSubtotal = $unitPrice->multiply($item['quantity']);
            $lineDiscount = new Money($item['discount_minor']);

            TransactionItem::create([
                'transaction_id' => $transaction->id,
                'product_id' => $product->id,
                'product_unit_id' => $item['product_unit_id'],
                'unit_name' => $item['unit_name'],
                'quantity' => $item['quantity'],
                'unit_price_minor' => $unitPrice->minor(),
                'discount_minor' => $lineDiscount->minor(),
                'promotion_id' => isset($item['promotion_id']) ? (int) $item['promotion_id'] : null,
                'promotion_name' => $item['promotion_name'] ?? null,
                'subtotal_minor' => $lineSubtotal->minor(),
                'tenant_id' => $tenantId,
            ]);

            $this->decrementStock($product, $item, $transaction, $data['user_id'], $tenantId);
        }

        // FR-05: tiap serah-bayar dicatat sebagai satu tender. Metode tunggal
        // pun menulis satu baris agar `transaction_payments` menjadi sumber
        // tunggal laporan per metode (termasuk penjualan campuran).
        foreach ($settlement['tenders'] as $tender) {
            TransactionPayment::create([
                'transaction_id' => $transaction->id,
                'payment_method' => $tender['payment_method']->value,
                'amount_minor' => $tender['amount_minor'],
                'payment_verification' => $tender['payment_verification']->value,
                'reference' => $tender['reference'],
                'notes' => $tender['notes'],
                'tenant_id' => $tenantId,
            ]);
        }

        if ($isCredit && $customer) {
            $unpaid = $total->subtract($paid);

            // Transitional mirror: the customer balance is a derived cache and
            // is only ever touched by domain actions (FR-07).
            $customer->increment('balance', $unpaid->major());

            // §17.3 step 6: a receivable is born from the unpaid balance.
            Receivable::create([
                'tenant_id' => $tenantId,
                'customer_id' => $customer->id,
                'transaction_id' => $transaction->id,
                'original_amount_minor' => $unpaid->minor(),
                'remaining_minor' => $unpaid->minor(),
                'due_at' => $dueAt,
                'status' => 'open',
            ]);
        }

        AuditLog::create([
            'tenant_id' => $tenantId,
            'actor_id' => $data['user_id'],
            'device_id' => $data['device_id'] ?? null,
            'action' => AuditAction::SaleCreated->value,
            'entity_type' => 'sale',
            'entity_id' => $transaction->uuid,
            'metadata' => [
                'client_request_id' => $clientRequestId,
                'invoice_number' => $transaction->invoice_number,
                'payment_status' => $transaction->payment_status,
                'payment_method' => $paymentMethod->value,
                'payment_verification' => $verification->value,
                'payment_count' => count($settlement['tenders']),
            ],
        ]);

        $this->enqueueForSync($data, $transaction, $items, $settlement, $tenantId, $clientRequestId, $dueAt);

        return $transaction->load('items');
    }

    /**
     * FR-07: "Batas kredit dan peringatan pelanggan dapat dikonfigurasi per
     * toko." Piutang tertagih dihitung dari baris piutang terbuka (sumber
     * tunggal), bukan dari cermin `customers.balance`.
     *
     * Pelanggan tanpa limit (`credit_limit` <= 0) selalu lolos. Bila kebijakan
     * toko hanya memberi peringatan, penjualan tetap dicatat dan UI yang
     * menampilkan warning; bila membatasi, kredit yang melewati limit ditolak.
     *
     * @param  array<string, mixed>  $data
     */
    private function assertCreditLimit(Customer $customer, Money $unpaid, ?string $tenantId): void
    {
        $limitMinor = Money::fromMajor($customer->credit_limit ?? 0)->minor();

        if ($limitMinor <= 0) {
            return;
        }

        $outstandingMinor = (int) Receivable::query()
            ->withoutGlobalScopes()
            ->where('customer_id', $customer->id)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->where('status', 'open')
            ->sum('remaining_minor');

        $projectedMinor = $outstandingMinor + $unpaid->minor();

        if ($projectedMinor <= $limitMinor) {
            return;
        }

        if (! (bool) config('pos.receivables.enforce_credit_limit', false)) {
            return;
        }

        throw CreditLimitExceeded::forCustomer($customer, $limitMinor, $projectedMinor);
    }

    /**
     * FR-07: tenggat piutang. Pemanggil boleh memberi `due_at` absolut (dipakai
     * perangkat agar identik dengan waktu lokalnya) atau `due_days` relatif;
     * bila keduanya kosong, tenggat default toko yang dipakai.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveDueAt(array $data): ?Carbon
    {
        if (! empty($data['due_at'])) {
            return Carbon::parse((string) $data['due_at']);
        }

        $days = array_key_exists('due_days', $data)
            ? (int) $data['due_days']
            : (int) config('pos.receivables.default_due_days', 30);

        return $days > 0 ? Carbon::now()->addDays($days) : null;
    }

    /**
     * §17.3 langkah 9 + §11.1: setiap perubahan yang perlu dikirim masuk ke
     * antrean outbox, DI DALAM transaksi domain yang sama (transactional
     * outbox) sehingga tidak hilang saat crash.
     *
     * Hanya berlaku bila penjualan berasal dari perangkat (`device_id` ada).
     * Penerapan ulang sisi server (mis. `SaleSyncHandler`) mematikan ini lewat
     * `enqueue_for_sync => false` agar tidak memantulkan operasi yang sama
     * kembali ke antrean (§11.1 idempotensi).
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     * @param  array{method: PaymentMethod, verification: PaymentVerification, paid: Money, change: Money, tenders: list<array{payment_method: PaymentMethod, payment_verification: PaymentVerification, amount_minor: int, reference: string|null, notes: string|null}>}  $settlement
     */
    private function enqueueForSync(
        array $data,
        Transaction $transaction,
        array $items,
        array $settlement,
        ?string $tenantId,
        ?string $clientRequestId,
        ?Carbon $dueAt = null,
    ): void {
        $deviceId = $data['device_id'] ?? null;

        if ($deviceId === null || $tenantId === null) {
            return;
        }

        if (($data['enqueue_for_sync'] ?? true) === false) {
            return;
        }

        (new Outbox($tenantId))->enqueue(
            (string) $deviceId,
            'sale',
            $transaction->uuid,
            SyncOperationType::Create,
            $this->syncPayload($data, $items, $settlement, $dueAt),
            $clientRequestId,
        );
    }

    /**
     * Payload operasi sinkronisasi penjualan: cukup untuk membangun ulang
     * penjualan di server lewat `CreateSale` (dipakai `SaleSyncHandler`).
     *
     * Nilai pembayaran diambil dari hasil penyelesaian (bukan dari `$data`
     * mentah) supaya pembayaran campuran ikut utuh: `payment_method` menjadi
     * "mixed" dan `payments` memuat seluruh tender. Untuk metode tunggal
     * `payments` dikirim `null` agar sisi server memakai jalur lama yang sudah
     * teruji (termasuk penjualan kredit tanpa bayar).
     *
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $items
     * @param  array{method: PaymentMethod, verification: PaymentVerification, paid: Money, change: Money, tenders: list<array{payment_method: PaymentMethod, payment_verification: PaymentVerification, amount_minor: int, reference: string|null, notes: string|null}>}  $settlement
     * @return array<string, mixed>
     */
    private function syncPayload(array $data, array $items, array $settlement, ?Carbon $dueAt = null): array
    {
        $tenders = $settlement['tenders'];

        return [
            'user_id' => $data['user_id'],
            'customer_id' => $data['customer_id'] ?? null,
            'customer_name' => $data['customer_name'] ?? null,
            'payment_method' => $settlement['method']->value,
            'payment_verification' => $settlement['verification']->value,
            'paid_minor' => $settlement['paid']->minor(),
            // FR-07: tenggat piutang ikut dikirim agar penjualan offline
            // menghasilkan piutang dengan jatuh tempo yang sama di server.
            'due_at' => $dueAt?->toIso8601String(),
            'payments' => count($tenders) > 1
                ? array_map(static fn (array $tender): array => [
                    'payment_method' => $tender['payment_method']->value,
                    'amount_minor' => $tender['amount_minor'],
                    'payment_verification' => $tender['payment_verification']->value,
                    'reference' => $tender['reference'],
                    'notes' => $tender['notes'],
                ], $tenders)
                : null,
            'discount_minor' => $data['discount_minor'] ?? null,
            'discount_percent' => $data['discount_percent'] ?? null,
            'promotion_id' => $data['promotion_id'] ?? null,
            'promotion_name' => $data['promotion_name'] ?? null,
            'promotion_discount_minor' => $data['promotion_discount_minor'] ?? null,
            'notes' => $data['notes'] ?? null,
            'location_id' => $data['location_id'] ?? null,
            'items' => array_map(static fn (array $item): array => [
                'product_id' => $item['product_id'],
                'quantity' => $item['quantity'],
                'unit_price_minor' => $item['unit_price_minor'],
                'product_unit_id' => $item['product_unit_id'],
                'unit_name' => $item['unit_name'],
                'conversion_factor' => $item['conversion_factor'],
                'discount_minor' => $item['discount_minor'],
                'promotion_id' => $item['promotion_id'] ?? null,
                'promotion_name' => $item['promotion_name'] ?? null,
            ], $items),
        ];
    }

    /**
     * Normalizes and validates raw line items into domain-shaped records.
     *
     * @return list<array{product_id: int, quantity: int, unit_price_minor: int, discount_minor: int, conversion_factor: float, product_unit_id: int|null, unit_name: string|null, promotion_id: int|null, promotion_name: string|null}>
     */
    private function normalizeItems(mixed $rawItems): array
    {
        if (! is_array($rawItems) || count($rawItems) === 0) {
            throw new \InvalidArgumentException('Items tidak boleh kosong.');
        }

        $normalized = [];

        foreach ($rawItems as $item) {
            $productId = (int) ($item['product_id'] ?? 0);
            $quantity = (int) ($item['quantity'] ?? 0);
            $unitPriceMinor = (int) ($item['unit_price_minor'] ?? -1);
            $discountMinor = (int) ($item['discount_minor'] ?? 0);
            $factor = (float) ($item['conversion_factor'] ?? 1);

            if ($productId <= 0 || $quantity <= 0 || $unitPriceMinor < 0 || $discountMinor < 0 || $factor <= 0) {
                throw new \InvalidArgumentException('Data item penjualan tidak valid.');
            }

            $normalized[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_price_minor' => $unitPriceMinor,
                'discount_minor' => $discountMinor,
                'conversion_factor' => $factor,
                'product_unit_id' => isset($item['product_unit_id']) ? (int) $item['product_unit_id'] : null,
                'unit_name' => $item['unit_name'] ?? null,
                'promotion_id' => isset($item['promotion_id']) ? (int) $item['promotion_id'] : null,
                'promotion_name' => $item['promotion_name'] ?? null,
            ];
        }

        return $normalized;
    }

    /**
     * @param  list<array{quantity: int, unit_price_minor: int, discount_minor: int, conversion_factor: float}>  $items
     */
    private function computeSubtotal(array $items): Money
    {
        $subtotal = Money::zero();

        foreach ($items as $item) {
            $subtotal = $subtotal->add(
                (new Money($item['unit_price_minor']))->multiply($item['quantity']),
            );
        }

        return $subtotal;
    }

    /**
     * FR-06: nominal discount wins over percent when both are given.
     *
     * @param  array<string, mixed>  $data
     */
    private function resolveTransactionDiscount(array $data, Money $subtotal): Money
    {
        $nominal = (int) ($data['discount_minor'] ?? 0);
        $percent = (float) ($data['discount_percent'] ?? 0);

        if ($nominal > 0) {
            return new Money($nominal);
        }

        if ($percent > 0) {
            $minor = (int) round($subtotal->minor() * $percent / 100);

            return new Money($minor);
        }

        return Money::zero();
    }

    /**
     * @param  array{quantity: int, conversion_factor: float}  $item
     * @return int stock units consumed (base unit of measure)
     */
    private function baseQuantity(array $item): int
    {
        return max(1, (int) round($item['quantity'] * $item['conversion_factor']));
    }

    /**
     * FR-05: menyelesaikan pembayaran menjadi metode utama, status verifikasi,
     * nominal dibayar, kembalian, dan daftar tender.
     *
     * Ada dua jalur yang sengaja dipertahankan:
     *  - jalur `payments` (campuran atau satu tender eksplisit);
     *  - jalur lama `payment_method` + `paid_minor` (tanpa `payments`) —
     *    perilakunya tidak berubah sedikit pun, termasuk penjualan kredit tanpa
     *    bayar, agar pemanggil lama dan sinkronisasi tetap kompatibel.
     *
     * @param  array<string, mixed>  $data
     * @return array{method: PaymentMethod, verification: PaymentVerification, paid: Money, change: Money, tenders: list<array{payment_method: PaymentMethod, payment_verification: PaymentVerification, amount_minor: int, reference: string|null, notes: string|null}>}
     */
    private function settlePayment(array $data, Money $total, ?string $tenantId): array
    {
        $verification = $this->resolvePaymentVerification($data['payment_verification'] ?? null);
        $rawPayments = $data['payments'] ?? null;

        if (is_array($rawPayments) && $rawPayments !== []) {
            return $this->settleSplitPayment($rawPayments, $data, $total, $verification, $tenantId);
        }

        $paid = new Money((int) ($data['paid_minor'] ?? 0));
        $method = $this->resolvePaymentMethod($data['payment_method'] ?? null);

        $this->assertPaymentRules($method, $verification, $paid, $total, $tenantId);

        return [
            'method' => $method,
            'verification' => $verification,
            'paid' => $paid,
            'change' => $this->resolveChange($method, $paid, $total),
            'tenders' => [[
                'payment_method' => $method,
                'payment_verification' => $verification,
                'amount_minor' => $paid->minor(),
                'reference' => null,
                'notes' => null,
            ]],
        ];
    }

    /**
     * Pembayaran campuran (FR-05).
     *
     * Aturan yang ditegakkan:
     *  - setiap tender harus bernominal positif dan metodenya ada di katalog;
     *  - bila `paid_minor` ikut dikirim, ia wajib sama dengan jumlah tender
     *    (tidak boleh ada dua sumber kebenaran yang berbeda);
     *  - total tender non-tunai tidak boleh melebihi total, sehingga kembalian
     *    selalu berasal dari tunai;
     *  - tender instruksi (transfer manual, QRIS statis) tidak pernah
     *    "terverifikasi", dan QRIS statis menuntut tenant yang sudah
     *    mengunggah QRIS;
     *  - satu tender tunggal lewat jalur ini tetap diperlakukan sebagai metode
     *    tunggal (bukan "mixed"), dan QRIS tunggal tetap wajib tepat total.
     *
     * @param  list<mixed>  $rawPayments
     * @param  array<string, mixed>  $data
     * @return array{method: PaymentMethod, verification: PaymentVerification, paid: Money, change: Money, tenders: list<array{payment_method: PaymentMethod, payment_verification: PaymentVerification, amount_minor: int, reference: string|null, notes: string|null}>}
     */
    private function settleSplitPayment(
        array $rawPayments,
        array $data,
        Money $total,
        PaymentVerification $verification,
        ?string $tenantId,
    ): array {
        $tenders = [];
        $paidMinor = 0;
        $nonCashMinor = 0;
        $qrisRequested = false;

        foreach ($rawPayments as $row) {
            if (! is_array($row)) {
                throw new InvalidPaymentAmount('Data pembayaran tidak valid.');
            }

            $method = $this->resolvePaymentMethod($row['payment_method'] ?? null);
            $amount = (int) ($row['amount_minor'] ?? 0);

            if ($amount <= 0) {
                throw new InvalidPaymentAmount('Nominal tiap metode pembayaran harus lebih dari nol.');
            }

            // FR-05: transfer manual & QRIS statis hanyalah instruksi; kasir
            // tidak dapat menyatakannya terverifikasi.
            if ($method->isInstructionOnly() && $verification->isVerified()) {
                throw InvalidPaymentVerification::notAllowedForInstructionOnly($method->value);
            }

            if (! $method->isCash()) {
                // Total non-tunai dibatasi agar kembalian selalu dari tunai.
                $nonCashMinor += $amount;
            }

            $qrisRequested = $qrisRequested || $method === PaymentMethod::QrisStatic;

            $paidMinor += $amount;

            $tenders[] = [
                'payment_method' => $method,
                'payment_verification' => $verification,
                'amount_minor' => $amount,
                'reference' => $this->cleanPaymentText($row['reference'] ?? null),
                'notes' => $this->cleanPaymentText($row['notes'] ?? null),
            ];
        }

        if (isset($data['paid_minor']) && (int) $data['paid_minor'] !== $paidMinor) {
            throw new InvalidPaymentAmount('Total pembayaran tidak sama dengan jumlah metode pembayaran.');
        }

        if ($nonCashMinor > $total->minor()) {
            throw new InvalidPaymentAmount('Pembayaran non-tunai tidak boleh melebihi total.');
        }

        $isSingle = count($tenders) === 1;

        if ($isSingle && $tenders[0]['payment_method'] === PaymentMethod::QrisStatic && $paidMinor !== $total->minor()) {
            throw new InvalidPaymentAmount('Pembayaran QRIS statis harus tepat sejumlah total (tidak ada kembalian).');
        }

        if ($qrisRequested) {
            $tenant = $tenantId !== null ? Tenant::find($tenantId) : null;

            if ($tenant === null || ! $tenant->hasQrisConfigured()) {
                throw QrisNotConfigured::forTenant($tenantId);
            }
        }

        $paid = new Money($paidMinor);
        $change = $paidMinor > $total->minor() ? new Money($paidMinor - $total->minor()) : Money::zero();

        return [
            'method' => $isSingle ? $tenders[0]['payment_method'] : PaymentMethod::Mixed,
            'verification' => $verification,
            'paid' => $paid,
            'change' => $change,
            'tenders' => $tenders,
        ];
    }

    /**
     * Teks pendukung tender (referensi/catatan) dibersihkan dan dipotong agar
     * tidak melebihi kolom database. `null`/kosong menjadi `null`.
     */
    private function cleanPaymentText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, 191);
    }

    /**
     * FR-05: hanya tunai yang menerima kelebihan bayar (kembalian); metode
     * lain harus tepat. QRIS statis bahkan tidak boleh kurang: nominal QR
     * sudah tetap, jadi bayar != total ditolak (lihat `assertPaymentRules`).
     */
    private function resolveChange(PaymentMethod $paymentMethod, Money $paid, Money $total): Money
    {
        if ($paid->minor() > $total->minor() && ! $paymentMethod->allowsChange()) {
            throw new InvalidPaymentAmount('Pembayaran non-tunai tidak boleh melebihi total.');
        }

        if ($paid->minor() < $total->minor()) {
            // Credit/partial sale: no change, unpaid portion tracked as balance.
            return Money::zero();
        }

        return $paid->subtract($total);
    }

    /**
     * `payment_method` adalah batas tulis: nilai di luar katalog FR-05 ditolak
     * (jalur baca tetap toleran lewat `PaymentMethod::labelFor`). Nilai kosong
     * tetap berarti tunai, seperti perilaku sebelumnya.
     */
    private function resolvePaymentMethod(mixed $method): PaymentMethod
    {
        $method = is_string($method) ? $method : '';

        if ($method === '') {
            return PaymentMethod::Cash;
        }

        // "mixed" adalah hasil komposisi tender, bukan pilihan kasir: ia hanya
        // boleh muncul lewat daftar `payments`, bukan lewat `payment_method`.
        if ($method === PaymentMethod::Mixed->value) {
            throw new \InvalidArgumentException('Metode "mixed" hanya dihasilkan dari daftar pembayaran, bukan dipilih langsung.');
        }

        return PaymentMethod::tryFrom($method)
            ?? throw new \InvalidArgumentException("Metode pembayaran tidak dikenal: {$method}.");
    }

    /**
     * FR-05: status verifikasi bersifat opsional; default "dicatat kasir".
     * Nilai di luar katalog juga ditolak (hanya gateway yang boleh menulis
     * `verified`/`failed`/`refunded`, dan itu lewat jalur gateway, bukan POS).
     */
    private function resolvePaymentVerification(mixed $verification): PaymentVerification
    {
        if ($verification === null || $verification === '') {
            return PaymentVerification::CashierRecorded;
        }

        if ($verification instanceof PaymentVerification) {
            return $verification;
        }

        return PaymentVerification::tryFrom((string) $verification)
            ?? throw new \InvalidArgumentException("Status verifikasi pembayaran tidak dikenal: {$verification}.");
    }

    /**
     * Aturan pembayaran FR-05 yang tidak bergantung pada nominal saja.
     */
    private function assertPaymentRules(
        PaymentMethod $paymentMethod,
        PaymentVerification $verification,
        Money $paid,
        Money $total,
        ?string $tenantId,
    ): void {
        if (! $paymentMethod->isInstructionOnly()) {
            return;
        }

        // FR-05: QRIS statis/transfer manual tidak pernah "terverifikasi"
        // hanya karena kasir menekan tombol "dibayar".
        if ($verification->isVerified()) {
            throw InvalidPaymentVerification::notAllowedForInstructionOnly($paymentMethod->value);
        }

        if ($paymentMethod !== PaymentMethod::QrisStatic) {
            return;
        }

        // Nominal QRIS statis tetap: bayar harus tepat, tanpa kembalian.
        if ($paid->minor() !== $total->minor()) {
            throw new InvalidPaymentAmount('Pembayaran QRIS statis harus tepat sejumlah total (tidak ada kembalian).');
        }

        // Tidak ada QRIS tanpa QRIS yang diunggah pemilik.
        $tenant = $tenantId !== null ? Tenant::find($tenantId) : null;

        if ($tenant === null || ! $tenant->hasQrisConfigured()) {
            throw QrisNotConfigured::forTenant($tenantId);
        }
    }

    private function assertStock(Product $product, int $baseQuantity, bool $allowNegativeStock = false): void
    {
        if ($product->is_unlimited_stock || $allowNegativeStock) {
            return;
        }

        if ($product->stock < $baseQuantity) {
            throw new InsufficientStock($product->id, $product->stock, $baseQuantity);
        }
    }

    /**
     * @param  array{quantity: int, conversion_factor: float}  $item
     */
    private function decrementStock(Product $product, array $item, Transaction $transaction, int $userId, ?string $tenantId): void
    {
        $baseQty = $this->baseQuantity($item);
        $before = $product->stock;

        $product->stock = $before - $baseQty;
        // saveQuietly() on purpose: the ledger entry below is authoritative
        // and carries the sale reference. The observer only covers manual
        // adjustments, so using it here would duplicate movements (C5).
        $product->saveQuietly();

        StockMovement::create([
            'product_id' => $product->id,
            'quantity_change' => -$baseQty,
            'before_stock' => $before,
            'after_stock' => $product->stock,
            'reference_type' => 'sale',
            'reference_id' => $transaction->id,
            'user_id' => $userId,
            'notes' => 'Sale '.$transaction->invoice_number,
            'tenant_id' => $tenantId,
        ]);
    }

    private function generateInvoiceNumber(): string
    {
        $retries = 5;
        $exists = true;

        do {
            $number = 'INV-'.now()->format('ymdHis').'-'.strtoupper(Str::random(4));
            $exists = Transaction::query()->withoutGlobalScopes()->where('invoice_number', $number)->exists();
        } while ($exists && --$retries > 0);

        if ($exists) {
            throw new \RuntimeException('Gagal menghasilkan nomor invoice yang unik.');
        }

        return $number;
    }

    private function findByIdempotencyKey(string $clientRequestId, ?string $tenantId = null): ?Transaction
    {
        return Transaction::query()
            ->withoutGlobalScopes()
            ->where('client_request_id', $clientRequestId)
            ->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))
            ->first();
    }

    private function isIdempotencyViolation(QueryException $e): bool
    {
        $code = (int) ($e->errorInfo[1] ?? 0);

        return $code === 1062
            || $code === 1555
            || str_contains($e->getMessage(), 'client_request_id');
    }
}
