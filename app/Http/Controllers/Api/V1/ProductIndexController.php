<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Module;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ProductIndexRequest;
use App\Models\Product;
use App\Support\Api\ApiMeta;
use App\Support\Api\ProductPayload;
use App\Support\Auth\ActionAuthorizer;
use App\Support\Features\FeatureProfileManager;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/products` (§18).
 *
 * Katalog untuk klien kasir dengan paginasi, pencarian, dan penyegaran
 * inkremental lewat `updated_since`.
 *
 * Otorisasi: token pengguna (Sanctum) + izin FR-12 `access.module`; modul
 * `products` harus aktif untuk toko ini (FR-13 — mematikan modul berarti
 * fiturnya tidak terlihat).
 *
 * Harga SELALU dihitung server dari data master (§18: "Nilai harga yang
 * dikirim client tidak boleh dipercaya sebagai sumber final") dan dikirim
 * sebagai integer minor unit (§17.1).
 */
class ProductIndexController extends Controller
{
    public function __invoke(ProductIndexRequest $request): JsonResponse
    {
        $user = $request->user();

        ActionAuthorizer::assert($user, Permission::AccessModule);

        $tenant = $user->tenant;

        if ($tenant === null || ! FeatureProfileManager::for($tenant)->isEnabled(Module::Products)) {
            return response()->json(['message' => 'Modul produk tidak aktif untuk toko ini.'], 403);
        }

        $query = Product::query()
            ->with(['category', 'brand', 'units'])
            ->when(
                ! $request->boolean('include_inactive'),
                fn ($builder) => $builder->where('is_active', true),
            )
            ->when(
                $request->filled('category_id'),
                fn ($builder) => $builder->where('category_id', (int) $request->input('category_id')),
            )
            ->when(
                $request->filled('updated_since'),
                fn ($builder) => $builder->where('updated_at', '>=', $request->date('updated_since')),
            )
            ->when($request->filled('search'), function ($builder) use ($request): void {
                $term = '%'.addcslashes((string) $request->input('search'), '%_\\').'%';

                $builder->where(function ($inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('sku', 'like', $term)
                        ->orWhere('barcode', 'like', $term);
                });
            });

        $sort = (string) ($request->input('sort') ?? 'name');
        $direction = (string) ($request->input('direction') ?? 'asc');
        $perPage = (int) ($request->input('per_page') ?? 50);

        $products = $query
            ->orderBy($sort, $direction)
            ->orderBy('id')
            ->paginate($perPage)
            ->withQueryString();

        $withUnits = $request->boolean('include_units');

        return response()->json(ApiMeta::with([
            'data' => collect($products->items())
                ->map(fn (Product $product) => ProductPayload::from($product, $withUnits))
                ->all(),
            'meta' => [
                'current_page' => $products->currentPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
                'last_page' => $products->lastPage(),
                'has_more' => $products->hasMorePages(),
            ],
        ]));
    }
}
