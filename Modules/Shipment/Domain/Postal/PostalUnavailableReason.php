<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Postal;

/**
 * Machine-readable reasons a postal method cannot be quoted. Exposed to clients as
 * `unavailable_code` beside the human `unavailable_reason`, and used to map a
 * checkout failure onto the right 422 field.
 */
enum PostalUnavailableReason: string
{
    case ServiceDisabled = 'service_disabled';
    case UnknownService = 'unknown_service';
    case ContentNotEligible = 'content_not_eligible';
    case DerivedTariffUnresolved = 'derived_tariff_unresolved';
    case CartEmpty = 'cart_empty';
    case WeightMissing = 'weight_missing';
    case WeightNotSupported = 'weight_not_supported';
    case OriginNotConfigured = 'origin_not_configured';
    case DestinationIncomplete = 'destination_incomplete';
    case InsideLocalDeliveryArea = 'inside_local_delivery_area';
    case RouteNotSupported = 'route_not_supported';
    case ZoneUnclassified = 'zone_unclassified';
    case CartonSizeUnknown = 'carton_size_unknown';
    case CartonTooLarge = 'carton_too_large';
    case TariffMissing = 'tariff_missing';
    case PricingParameterMissing = 'pricing_parameter_missing';

    /** Normal Post fallback requested, but every item in the cart has a valid weight. */
    case FallbackNotApplicable = 'fallback_not_applicable';

    public function message(): string
    {
        return match ($this) {
            self::FallbackNotApplicable => 'Normal Post is only offered when an item in your cart has no shipping weight. Please refresh your shipping options and choose another method.',
            self::ServiceDisabled => 'This postal service is not offered.',
            self::UnknownService => 'Unknown postal service.',
            self::ContentNotEligible => 'This postal service does not accept the kind of items in your cart.',
            self::DerivedTariffUnresolved => 'This postal service cannot be priced automatically yet.',
            self::CartEmpty => 'Add items to your cart to calculate postage.',
            self::WeightMissing => 'Postage cannot be calculated because an item in your cart has no shipping weight.',
            self::WeightNotSupported => 'Your parcel weight is outside the range this postal service accepts.',
            self::OriginNotConfigured => 'Postal shipping is temporarily unavailable.',
            self::DestinationIncomplete => 'Your address needs a province and a city to calculate postage.',
            self::InsideLocalDeliveryArea => 'Your address is inside the local delivery area — please choose local delivery or in-store pickup.',
            self::RouteNotSupported => 'This postal service is not available for your address.',
            self::ZoneUnclassified => 'This postal service is not available for your city.',
            self::CartonSizeUnknown => 'This postal service needs a confirmed carton size and is not available yet.',
            self::CartonTooLarge => 'Your parcel is larger than this postal service accepts.',
            self::TariffMissing => 'No postal tariff is available for this parcel.',
            self::PricingParameterMissing => 'Postal shipping is temporarily unavailable.',
        };
    }
}
