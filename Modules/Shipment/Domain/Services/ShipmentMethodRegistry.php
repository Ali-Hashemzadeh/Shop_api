<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Services;

use Modules\Shipment\Domain\DTOs\ShipmentMethodDTO;
use Modules\Shipment\Domain\Enums\ShipmentMethodType;
use Modules\Shipment\Domain\Postal\Post1405Catalog;
use Modules\Shipment\Domain\Postal\PostalServiceDefinition;

/**
 * Read-only accessor for every fulfillment method. Methods are identified by their
 * stable string code — never a database id. Two sources, one shape:
 *
 *   - the 21 Iran Post 1405 postal methods: Post1405Catalog definitions + the
 *     per-method toggles in config('shipping.post_1405.services'). They have no
 *     static price; only the 1405 engine can price them.
 *   - local_delivery / in_person_pickup (+ the retired post_standard/post_express)
 *     from config('shipment.methods').
 *
 * `all()` / `find()` return only SELECTABLE methods (enabled, not retired).
 * `describe()` returns any known definition — enabled or not — so historical orders
 * carrying a retired or since-disabled code stay readable.
 */
class ShipmentMethodRegistry
{
    /**
     * Selectable methods keyed by code: postal 1405 first (catalog order), then the
     * config-backed methods.
     *
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        $methods = [];

        foreach ($this->definitions() as $code => $method) {
            if ($this->isSelectable($method)) {
                $methods[$code] = $method;
            }
        }

        return $methods;
    }

    /**
     * @return array<string, mixed>|null the definition of a selectable method, or null
     */
    public function find(string $code): ?array
    {
        $method = $this->describe($code);

        return $method !== null && $this->isSelectable($method) ? $method : null;
    }

    /**
     * Any known method definition, selectable or not (for reading history).
     *
     * @return array<string, mixed>|null
     */
    public function describe(string $code): ?array
    {
        return $this->definitions()[$code] ?? null;
    }

    public function exists(string $code): bool
    {
        return $this->find($code) !== null;
    }

    public function isPostal1405(string $code): bool
    {
        return Post1405Catalog::find($code) !== null;
    }

    public function requiresAddress(string $code): bool
    {
        return (bool) ($this->find($code)['requires_address'] ?? false);
    }

    public function requiresDeliverySlot(string $code): bool
    {
        return (bool) ($this->find($code)['requires_delivery_slot'] ?? false);
    }

    /** Static configured price; null for postal methods (they are always calculated). */
    public function price(string $code): ?int
    {
        $price = $this->find($code)['price'] ?? null;

        return $price === null ? null : (int) $price;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pickupLocation(string $code): ?array
    {
        $location = $this->find($code)['pickup_location'] ?? null;

        return is_array($location) ? $location : null;
    }

    /**
     * @param  array<string, mixed>  $extra  named ShipmentMethodDTO arguments to override
     */
    public function toDTO(string $code, bool $available = true, ?string $unavailableReason = null, ?int $price = null, array $extra = []): ShipmentMethodDTO
    {
        $method = $this->describe($code) ?? [];
        $isPostal1405 = $this->isPostal1405($code);

        return new ShipmentMethodDTO(...array_merge([
            'code' => $code,
            'title' => (string) ($method['title'] ?? $code),
            'type' => (string) ($method['type'] ?? ''),
            // Postal: only an explicitly computed price, never a config fallback.
            'price' => $isPostal1405 ? $price : ($price ?? (isset($method['price']) ? (int) $method['price'] : null)),
            'requiresAddress' => (bool) ($method['requires_address'] ?? false),
            'requiresDeliverySlot' => (bool) ($method['requires_delivery_slot'] ?? false),
            'supportsTracking' => (bool) ($method['supports_tracking'] ?? false),
            'estimatedMinDays' => $method['estimated_min_days'] ?? null,
            'estimatedMaxDays' => $method['estimated_max_days'] ?? null,
            'available' => $available,
            'unavailableReason' => $unavailableReason,
            'pickupLocation' => $this->pickupLocation($code),
            'tariffYear' => $method['tariff_year'] ?? null,
            'scope' => $method['scope'] ?? null,
            'packageKind' => $method['package_kind'] ?? null,
        ], $extra));
    }

    /** @param array<string, mixed> $method */
    private function isSelectable(array $method): bool
    {
        return (bool) ($method['enabled'] ?? false) && ! (bool) ($method['retired'] ?? false);
    }

    /**
     * Every known method, keyed by code.
     *
     * @return array<string, array<string, mixed>>
     */
    private function definitions(): array
    {
        $definitions = [];
        $toggles = (array) config('shipping.post_1405.services', []);
        $year = (int) config('shipping.post_1405.tariff_year', Post1405Catalog::TARIFF_YEAR);

        foreach (Post1405Catalog::services() as $code => $service) {
            $definitions[$code] = $this->postalDefinition($service, (bool) ($toggles[$code] ?? false), $year);
        }

        foreach ((array) config('shipment.methods', []) as $code => $method) {
            // A config entry can never shadow a 1405 postal code.
            if (is_array($method) && ! isset($definitions[$code])) {
                $definitions[$code] = $method;
            }
        }

        return $definitions;
    }

    /** @return array<string, mixed> */
    private function postalDefinition(PostalServiceDefinition $service, bool $enabled, int $year): array
    {
        return [
            'title' => $service->titleFa,
            'type' => ShipmentMethodType::Postal->value,
            // No static price exists for a 1405 method.
            'price' => null,
            'requires_address' => true,
            'requires_delivery_slot' => false,
            'supports_tracking' => true,
            // No ETA is invented beyond the published service label (e.g. "D+3").
            'estimated_min_days' => null,
            'estimated_max_days' => null,
            'enabled' => $enabled,
            'tariff_year' => $year,
            'scope' => $service->scope->value,
            'package_kind' => $service->packageKind,
        ];
    }
}
