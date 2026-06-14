<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Sanctum\Sanctum;
use Modules\Identity\Domain\Models\User;
use Modules\Catalog\Infrastructure\Persistence\Seeders\CatalogPermissionsSeeder;
use Modules\Media\Infrastructure\Persistence\Seeders\MediaPermissionsSeeder;
use Modules\Identity\Infrastructure\Persistence\Seeders\RolesAndPermissionsSeeder;

abstract class TestCase extends BaseTestCase
{
    protected function seedIdentityRolesAndPermissions(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function seedCatalogPermissions(): void
    {
        $this->seed(CatalogPermissionsSeeder::class);
    }

    protected function seedMediaPermissions(): void
    {
        $this->seed(MediaPermissionsSeeder::class);
    }

    protected function actingAsCustomer(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $user->assignRole('customer');

        Sanctum::actingAs($user);

        return $user;
    }

    protected function actingAsAdmin(?User $user = null): User
    {
        $user ??= User::factory()->create();
        $user->assignRole('admin');

        Sanctum::actingAs($user);

        return $user;
    }
}
