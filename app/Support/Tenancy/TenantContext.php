<?php

namespace App\Support\Tenancy;

use App\Models\Tenant;

/**
 * Request-scoped holder for the tenant currently being served.
 *
 * Resolution happens once per request (auth, device handshake, or explicit
 * binding). It is intentionally not persisted, so background workers and
 * tests remain unscoped until a tenant is bound (plan §12).
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function get(): ?Tenant
    {
        return $this->tenant;
    }

    public function id(): ?string
    {
        return $this->tenant?->getKey();
    }

    public function has(): bool
    {
        return $this->tenant !== null;
    }
}
