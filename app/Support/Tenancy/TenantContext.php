<?php

namespace App\Support\Tenancy;

/**
 * Holds the identifier of the organization (tenant) currently in scope.
 *
 * This class is intentionally minimal: it only stores and exposes the
 * active organization id. It does not resolve the tenant, does not
 * enforce authorization, and does not apply any query scoping. Those
 * concerns belong to future blocks.
 */
class TenantContext
{
    private ?int $organizationId = null;

    /**
     * Set the active organization for the current context.
     */
    public function setOrganizationId(int $organizationId): void
    {
        $this->organizationId = $organizationId;
    }

    /**
     * Get the active organization id, if any.
     */
    public function getOrganizationId(): ?int
    {
        return $this->organizationId;
    }

    /**
     * Determine whether an active organization has been set.
     */
    public function hasOrganization(): bool
    {
        return $this->organizationId !== null;
    }

    /**
     * Clear the active organization from the context.
     */
    public function clear(): void
    {
        $this->organizationId = null;
    }
}
