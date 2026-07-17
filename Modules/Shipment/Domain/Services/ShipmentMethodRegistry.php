<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Services;

use Modules\Shipment\Domain\DTOs\ShipmentMethodDTO;

/**
 * Read-only accessor for the four fixed, config-backed fulfillment methods.
 * Methods are identified by their stable string code — never a database id.
 */
class ShipmentMethodRegistry
{
    /**
     * @return array<string, array<string, mixed>> enabled methods keyed by code
     */
    public function all(): array
    {
        return array_filter(
            config('shipment.methods', []),
            static fn (array $method): bool => (bool) ($method['enabled'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>|null the config for an enabled method, or null
     */
    public function find(string $code): ?array
    {
        $method = config("shipment.methods.{$code}");

        if (! is_array($method) || ! ($method['enabled'] ?? false)) {
            return null;
        }

        return $method;
    }

    public function exists(string $code): bool
    {
        return $this->find($code) !== null;
    }

    public function requiresAddress(string $code): bool
    {
        return (bool) ($this->find($code)['requires_address'] ?? false);
    }

    public function requiresDeliverySlot(string $code): bool
    {
        return (bool) ($this->find($code)['requires_delivery_slot'] ?? false);
    }

    public function price(string $code): int
    {
        return (int) ($this->find($code)['price'] ?? 0);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pickupLocation(string $code): ?array
    {
        $location = $this->find($code)['pickup_location'] ?? null;

        return is_array($location) ? $location : null;
    }

    public function toDTO(string $code, bool $available = true, ?string $unavailableReason = null): ShipmentMethodDTO
    {
        $method = $this->find($code) ?? [];

        return new ShipmentMethodDTO(
            code: $code,
            title: (string) ($method['title'] ?? $code),
            type: (string) ($method['type'] ?? ''),
            price: (int) ($method['price'] ?? 0),
            requiresAddress: (bool) ($method['requires_address'] ?? false),
            requiresDeliverySlot: (bool) ($method['requires_delivery_slot'] ?? false),
            supportsTracking: (bool) ($method['supports_tracking'] ?? false),
            estimatedMinDays: $method['estimated_min_days'] ?? null,
            estimatedMaxDays: $method['estimated_max_days'] ?? null,
            available: $available,
            unavailableReason: $unavailableReason,
            pickupLocation: $this->pickupLocation($code),
        );
    }
}
