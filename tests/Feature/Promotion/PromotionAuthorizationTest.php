<?php

declare(strict_types=1);

namespace Tests\Feature\Promotion;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Catalog\Domain\Contracts\CatalogManagerInterface;
use Modules\Identity\Domain\Models\User;
use Modules\Promotion\Domain\Enums\DiscountTargetType;
use Modules\Promotion\Domain\Models\Campaign;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;

/**
 * The six promotion permissions, and the routes each one guards.
 *
 * Every admin route is checked three ways: guest → 401, authenticated customer
 * without the permission → 403, holder of the permission → success. Hiding the
 * controls in a frontend is not authorization.
 */
class PromotionAuthorizationTest extends PromotionTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedIdentityRolesAndPermissions();
        $this->seedCatalogPermissions();
        $this->seedPromotionPermissions();
    }

    /** A customer granted exactly one promotion permission and nothing more. */
    private function customerWith(string ...$permissions): User
    {
        $user = $this->actingAsCustomer();

        foreach ($permissions as $permission) {
            $user->givePermissionTo(Permission::findByName($permission, 'web'));
        }

        return $user->fresh();
    }

    /** @return array<int, array{0: string, 1: string}> method + url */
    public static function adminReadRoutes(): array
    {
        return [
            'discount list' => ['get', '/api/v1/admin/promotions/discounts'],
            'coupon list' => ['get', '/api/v1/admin/promotions/coupons'],
            'campaign list' => ['get', '/api/v1/admin/promotions/campaigns'],
            'redemption history' => ['get', '/api/v1/admin/promotions/redemptions'],
        ];
    }

    // ── The six permissions are seeded and granted only to admin ──────────────

    #[Test]
    public function all_six_permissions_are_seeded(): void
    {
        foreach ([
            'promotion.view-admin',
            'promotion.create',
            'promotion.update',
            'promotion.delete',
            'promotion.coupon.manage',
            'promotion.campaign.manage',
        ] as $permission) {
            $this->assertDatabaseHas('permissions', ['name' => $permission, 'guard_name' => 'web']);
        }
    }

    #[Test]
    public function the_admin_role_holds_every_promotion_permission_and_the_customer_role_holds_none(): void
    {
        $admin = $this->actingAsAdmin();
        $customer = User::factory()->create();
        $customer->assignRole('customer');

        foreach ([
            'promotion.view-admin',
            'promotion.create',
            'promotion.update',
            'promotion.delete',
            'promotion.coupon.manage',
            'promotion.campaign.manage',
        ] as $permission) {
            $this->assertTrue($admin->can($permission), "admin should hold {$permission}");
            $this->assertFalse($customer->can($permission), "customer must not hold {$permission}");
        }
    }

    // ── promotion.view-admin ──────────────────────────────────────────────────

    #[Test]
    public function admin_read_routes_reject_guests_and_permissionless_customers(): void
    {
        foreach (self::adminReadRoutes() as $label => [$method, $url]) {
            $this->{$method.'Json'}($url)->assertStatus(401, "guest on {$label}");
        }

        $this->actingAsCustomer();

        foreach (self::adminReadRoutes() as $label => [$method, $url]) {
            $this->{$method.'Json'}($url)->assertStatus(403, "customer on {$label}");
        }
    }

    #[Test]
    public function view_admin_grants_every_promotion_read_route(): void
    {
        $this->customerWith('promotion.view-admin');

        foreach (self::adminReadRoutes() as $label => [$method, $url]) {
            $this->{$method.'Json'}($url)->assertOk("view-admin should reach {$label}");
        }
    }

    // ── promotion.create / update / delete ────────────────────────────────────

    #[Test]
    public function discount_create_requires_promotion_create(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $payload = [
            'name' => 'New Rule',
            'trigger_type' => 'automatic',
            'scope' => 'targeted',
            'discount_type' => 'percentage',
            'percentage_bps' => 1000,
            'targets' => [['target_type' => 'product', 'target_id' => $product->id]],
        ];

        $this->postJson('/api/v1/admin/promotions/discounts', $payload)->assertStatus(401);

        // view-admin alone must not be enough to write.
        $this->customerWith('promotion.view-admin');
        $this->postJson('/api/v1/admin/promotions/discounts', $payload)->assertStatus(403);

        $this->customerWith('promotion.create');
        $this->postJson('/api/v1/admin/promotions/discounts', $payload)->assertCreated();
    }

    #[Test]
    public function discount_update_requires_promotion_update(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $discount = $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 1000);

        $this->customerWith('promotion.create');
        $this->patchJson("/api/v1/admin/promotions/discounts/{$discount->id}", ['name' => 'Renamed'])
            ->assertStatus(403);

        $this->customerWith('promotion.update');
        $this->patchJson("/api/v1/admin/promotions/discounts/{$discount->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonPath('name', 'Renamed');
    }

    #[Test]
    public function discount_delete_requires_promotion_delete_and_only_soft_deletes(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $discount = $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 1000);

        $this->customerWith('promotion.update');
        $this->deleteJson("/api/v1/admin/promotions/discounts/{$discount->id}")->assertStatus(403);

        $this->customerWith('promotion.delete');
        $this->deleteJson("/api/v1/admin/promotions/discounts/{$discount->id}")->assertStatus(204);

        // History preserved: the row survives, soft-deleted, and stops pricing.
        $this->assertDatabaseHas('discounts', ['id' => $discount->id]);
        $this->assertNotNull($discount->fresh()->deleted_at);
        $this->assertSame(100_000_000, app(CatalogManagerInterface::class)
            ->findProduct($product->uuid)->variants[0]->effectivePrice());
    }

    // ── promotion.coupon.manage ───────────────────────────────────────────────

    #[Test]
    public function coupon_mutations_require_coupon_manage_while_reads_need_only_view_admin(): void
    {
        $coupon = $this->coupon('EXISTING', bps: 1000);
        $payload = ['discount_id' => $coupon->discount_id, 'code' => 'NEWCODE'];

        $this->postJson('/api/v1/admin/promotions/coupons', $payload)->assertStatus(401);

        // Reading is fine with view-admin; writing is not.
        $this->customerWith('promotion.view-admin');
        $this->getJson("/api/v1/admin/promotions/coupons/{$coupon->id}")->assertOk();
        $this->postJson('/api/v1/admin/promotions/coupons', $payload)->assertStatus(403);
        $this->patchJson("/api/v1/admin/promotions/coupons/{$coupon->id}", ['is_active' => false])->assertStatus(403);
        $this->deleteJson("/api/v1/admin/promotions/coupons/{$coupon->id}")->assertStatus(403);

        $this->customerWith('promotion.coupon.manage');
        $this->postJson('/api/v1/admin/promotions/coupons', $payload)->assertCreated();
        $this->patchJson("/api/v1/admin/promotions/coupons/{$coupon->id}", ['is_active' => false])->assertOk();
        $this->deleteJson("/api/v1/admin/promotions/coupons/{$coupon->id}")->assertStatus(204);
    }

    // ── promotion.campaign.manage ─────────────────────────────────────────────

    #[Test]
    public function campaign_mutations_require_campaign_manage_while_reads_need_only_view_admin(): void
    {
        $campaign = Campaign::query()->create(['name' => 'Existing', 'slug' => 'existing', 'is_active' => true]);
        $payload = ['name' => 'New Campaign', 'slug' => 'new-campaign'];

        $this->postJson('/api/v1/admin/promotions/campaigns', $payload)->assertStatus(401);

        $this->customerWith('promotion.view-admin');
        $this->getJson("/api/v1/admin/promotions/campaigns/{$campaign->id}")->assertOk();
        $this->postJson('/api/v1/admin/promotions/campaigns', $payload)->assertStatus(403);
        $this->patchJson("/api/v1/admin/promotions/campaigns/{$campaign->id}", ['name' => 'X'])->assertStatus(403);
        $this->deleteJson("/api/v1/admin/promotions/campaigns/{$campaign->id}")->assertStatus(403);

        $this->customerWith('promotion.campaign.manage');
        $this->postJson('/api/v1/admin/promotions/campaigns', $payload)->assertCreated();
        $this->patchJson("/api/v1/admin/promotions/campaigns/{$campaign->id}", ['name' => 'X'])->assertOk();
        $this->deleteJson("/api/v1/admin/promotions/campaigns/{$campaign->id}")->assertStatus(204);
    }

    // ── Customers need no promotion permission for customer-facing routes ─────

    #[Test]
    public function public_and_customer_promotion_surfaces_require_no_promotion_permission(): void
    {
        $product = $this->makeProduct('Phone', 100_000_000);
        $this->automaticDiscount([[DiscountTargetType::PRODUCT, $product->id]], bps: 2000);
        Campaign::query()->create(['name' => 'Summer', 'slug' => 'summer', 'is_active' => true, 'show_on_landing' => true]);

        // Fully unauthenticated: live promotional pricing and campaigns are public.
        $this->getJson('/api/v1/catalog/products')->assertOk();
        $this->getJson('/api/v1/catalog/products/'.$product->uuid)
            ->assertOk()
            ->assertJsonPath('variants.0.effective_price', 80_000_000);
        $this->getJson('/api/v1/catalog/campaigns')->assertOk();
        $this->getJson('/api/v1/catalog/campaigns/summer/products')->assertOk();

        // A plain customer with zero promotion permissions still sees all of it.
        $this->actingAsCustomer();
        $this->getJson('/api/v1/catalog/campaigns')->assertOk();
    }
}
