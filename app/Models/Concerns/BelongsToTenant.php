<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Scopes a model to the tenant bound in the current request.
 *
 * The queue/CLI context has no tenant bound, so queries stay unscoped there.
 * Once a tenant is resolved (auth, device handshake) every query and every
 * new record is automatically limited to it (plan §12).
 */
trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::creating(function ($model) {
            $tenantId = app(TenantContext::class)->id();

            if ($tenantId && empty($model->tenant_id)) {
                $model->tenant_id = $tenantId;
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder) {
            $context = app(TenantContext::class);

            if ($context->has()) {
                $builder->where(
                    $builder->getModel()->getTable().'.tenant_id',
                    $context->id(),
                );
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
