<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Postal;

/**
 * One Iran Post 1405 service, as published in the tariff PDF plus the operational
 * attributes the shop needs to decide eligibility. Immutable.
 *
 * The first block mirrors a `postal_services_1405` row. The second block is
 * operational policy and lives only in code (it is not tariff data):
 *   - maxWeightGrams     hard acceptance limit stated by the PDF (null = none stated)
 *   - maxCartonSize      largest accepted carton number (null = no carton rule)
 *   - coverageFamily     key into config('shipping.post_1405.coverage') when the
 *                        service needs an explicit route/city allow-list
 *   - electronicService  whether PDF Table 1 row 14 (electronic id + SMS) applies
 *   - compensationKind   'envelope' | 'parcel' | null — which PDF Table 7 ceiling applies
 */
final class PostalServiceDefinition
{
    public const POLICY_OPEN = 'open';

    public const POLICY_ROUTE_ALLOWLIST = 'route_allowlist';

    public const POLICY_URBAN_COVERAGE = 'urban_coverage';

    public const POLICY_DERIVED_UNRESOLVED = 'derived_unresolved';

    public const RULE_DISTANCE_BRACKET = 'distance_bracket';

    public const RULE_DISTANCE_BRACKET_EXTRA_KG = 'distance_bracket_extra_kg';

    public const RULE_PRIORITY_FLAT = 'priority_flat';

    public const RULE_PRIORITY_BASE_PLUS_KG = 'priority_base_plus_kg';

    public const RULE_DERIVED = 'derived';

    public function __construct(
        public readonly string $code,
        public readonly string $titleFa,
        public readonly PostalScope $scope,
        public readonly string $contentKind,
        public readonly string $packageKind,
        public readonly string $ratingRule,
        public readonly string $pdfReference,
        public readonly string $eligibilityPolicy,
        public readonly bool $defaultEnabled,
        public readonly ?int $maxWeightGrams = null,
        public readonly ?int $maxCartonSize = null,
        public readonly ?string $coverageFamily = null,
        public readonly bool $electronicService = false,
        public readonly ?string $compensationKind = null,
    ) {}

    public function isDerived(): bool
    {
        return $this->eligibilityPolicy === self::POLICY_DERIVED_UNRESOLVED;
    }

    /**
     * The row persisted in postal_services_1405.
     *
     * @return array<string, mixed>
     */
    public function toRow(int $tariffYear): array
    {
        return [
            'code' => $this->code,
            'title_fa' => $this->titleFa,
            'scope' => $this->scope->value,
            'content_kind' => $this->contentKind,
            'package_kind' => $this->packageKind,
            'rating_rule' => $this->ratingRule,
            'pdf_reference' => $this->pdfReference,
            'eligibility_policy' => $this->eligibilityPolicy,
            'tariff_year' => $tariffYear,
        ];
    }
}
