<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\DTOs;

/**
 * The physical contents of a would-be shipment, as primitives: one line per SKU with
 * its quantity and per-unit shipping weight. Built by the caller (Cart for the method
 * list, Order from freshly re-read Catalog variants at checkout) so Shipment never
 * imports another module's DTO or model.
 *
 * A unit weight of null or <= 0 is "unknown" — never treated as zero. Postal pricing
 * refuses to quote a parcel containing an unknown weight.
 */
final class ShipmentParcelDTO
{
    /**
     * @param  list<array{sku: string, quantity: int, weight_grams: ?int}>  $lines
     */
    public function __construct(
        public readonly array $lines,
    ) {}

    /**
     * @param  iterable<array{sku: string, quantity: int, weight_grams: ?int}>  $lines
     */
    public static function fromLines(iterable $lines): self
    {
        $normalized = [];

        foreach ($lines as $line) {
            $normalized[] = [
                'sku' => (string) $line['sku'],
                'quantity' => (int) $line['quantity'],
                'weight_grams' => isset($line['weight_grams']) ? (int) $line['weight_grams'] : null,
            ];
        }

        return new self($normalized);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        foreach ($this->lines as $line) {
            if ($line['quantity'] > 0) {
                return false;
            }
        }

        return true;
    }

    /**
     * SKUs whose unit weight is missing or non-positive, unique, in cart order.
     *
     * @return list<string>
     */
    public function skusMissingWeight(): array
    {
        $skus = [];

        foreach ($this->lines as $line) {
            if ($line['quantity'] > 0 && ($line['weight_grams'] === null || $line['weight_grams'] <= 0)) {
                $skus[$line['sku']] = true;
            }
        }

        return array_keys($skus);
    }

    /** Σ unit weight × quantity, counting only lines with a known positive weight. */
    public function totalWeightGrams(): int
    {
        $total = 0;

        foreach ($this->lines as $line) {
            if ($line['quantity'] > 0 && $line['weight_grams'] !== null && $line['weight_grams'] > 0) {
                $total += $line['weight_grams'] * $line['quantity'];
            }
        }

        return $total;
    }
}
