<?php

declare(strict_types=1);

namespace Tests\Feature\Analytics;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedAnalyticsPermissions();
    }

    // ── Unauthenticated → 401 ───────────────────────────────────────────────

    public function test_guest_cannot_access_dashboard(): void
    {
        $this->getJson('/api/v1/admin/analytics/dashboard')->assertUnauthorized();
    }

    public function test_guest_cannot_access_sales(): void
    {
        $this->getJson('/api/v1/admin/analytics/sales')->assertUnauthorized();
    }

    public function test_guest_cannot_access_products(): void
    {
        $this->getJson('/api/v1/admin/analytics/products')->assertUnauthorized();
    }

    public function test_guest_cannot_access_customers(): void
    {
        $this->getJson('/api/v1/admin/analytics/customers')->assertUnauthorized();
    }

    public function test_guest_cannot_access_delivery(): void
    {
        $this->getJson('/api/v1/admin/analytics/delivery')->assertUnauthorized();
    }

    // ── Customer without permission → 403 ───────────────────────────────────

    public function test_customer_cannot_access_dashboard(): void
    {
        $this->actingAsCustomer();
        $this->getJson('/api/v1/admin/analytics/dashboard')->assertForbidden();
    }

    public function test_customer_cannot_access_sales(): void
    {
        $this->actingAsCustomer();
        $this->getJson('/api/v1/admin/analytics/sales')->assertForbidden();
    }

    public function test_customer_cannot_access_products(): void
    {
        $this->actingAsCustomer();
        $this->getJson('/api/v1/admin/analytics/products')->assertForbidden();
    }

    public function test_customer_cannot_access_customers(): void
    {
        $this->actingAsCustomer();
        $this->getJson('/api/v1/admin/analytics/customers')->assertForbidden();
    }

    public function test_customer_cannot_access_delivery(): void
    {
        $this->actingAsCustomer();
        $this->getJson('/api/v1/admin/analytics/delivery')->assertForbidden();
    }

    // ── Admin with permission → 200 OK ──────────────────────────────────────

    public function test_admin_with_permission_can_access_all_analytics(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/api/v1/admin/analytics/dashboard')->assertOk();
        $this->getJson('/api/v1/admin/analytics/sales')->assertOk();
        $this->getJson('/api/v1/admin/analytics/products')->assertOk();
        $this->getJson('/api/v1/admin/analytics/customers')->assertOk();
        $this->getJson('/api/v1/admin/analytics/delivery')->assertOk();
    }
}
