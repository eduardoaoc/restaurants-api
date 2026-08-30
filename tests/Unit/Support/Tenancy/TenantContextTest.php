<?php

namespace Tests\Unit\Support\Tenancy;

use App\Support\Tenancy\TenantContext;
use Tests\TestCase;

class TenantContextTest extends TestCase
{
    public function test_it_has_no_organization_by_default(): void
    {
        $context = new TenantContext;

        $this->assertFalse($context->hasOrganization());
        $this->assertNull($context->getOrganizationId());
    }

    public function test_it_stores_the_active_organization_id(): void
    {
        $context = new TenantContext;

        $context->setOrganizationId(42);

        $this->assertTrue($context->hasOrganization());
        $this->assertSame(42, $context->getOrganizationId());
    }

    public function test_it_can_be_cleared(): void
    {
        $context = new TenantContext;
        $context->setOrganizationId(42);

        $context->clear();

        $this->assertFalse($context->hasOrganization());
        $this->assertNull($context->getOrganizationId());
    }

    public function test_it_resolves_as_a_shared_singleton(): void
    {
        $first = app(TenantContext::class);
        $first->setOrganizationId(7);

        $second = app(TenantContext::class);

        $this->assertSame(7, $second->getOrganizationId());
    }
}
