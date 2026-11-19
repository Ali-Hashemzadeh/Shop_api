<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Modules\Shipment\Domain\Models\PostalService1405;
use Modules\Shipment\Domain\Postal\Post1405Catalog;

/**
 * Seeds the 21 Iran Post 1405 service definitions. PRODUCTION-SAFE:
 *   - insert-if-missing keyed on `code`; re-running never duplicates a row.
 *   - an existing row is NEVER overwritten. If it differs from the catalog the
 *     difference is reported (console + log) for an operator to review.
 *   - nothing is deleted or truncated.
 */
class PostalService1405Seeder extends Seeder
{
    public function run(): void
    {
        $inserted = 0;
        $conflicts = [];

        foreach (Post1405Catalog::services() as $code => $definition) {
            $expected = $definition->toRow(Post1405Catalog::TARIFF_YEAR);
            $existing = PostalService1405::query()->find($code);

            if ($existing === null) {
                PostalService1405::query()->create($expected);
                $inserted++;

                continue;
            }

            foreach ($expected as $column => $value) {
                if ((string) $existing->{$column} !== (string) $value) {
                    $conflicts[] = "{$code}.{$column}: kept database value ".var_export($existing->{$column}, true).', catalog has '.var_export($value, true);
                }
            }
        }

        $this->command?->info("Postal 1405 services: {$inserted} inserted, ".(count(Post1405Catalog::services()) - $inserted).' already present.');

        foreach ($conflicts as $conflict) {
            $this->command?->warn("  Not overwritten — {$conflict}");
        }

        if ($conflicts !== []) {
            Log::warning('PostalService1405Seeder kept existing rows that differ from the catalog.', ['conflicts' => $conflicts]);
        }
    }
}
