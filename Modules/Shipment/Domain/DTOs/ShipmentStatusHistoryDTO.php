<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\DTOs;

use Carbon\Carbon;
use Modules\Shipment\Domain\Models\ShipmentStatusHistory;

class ShipmentStatusHistoryDTO
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $fromStatus,
        public readonly string $toStatus,
        public readonly ?int $changedByUserId,
        public readonly ?string $reason,
        public readonly ?string $note,
        public readonly array $metadata,
        public readonly Carbon $createdAt,
    ) {}

    public static function fromModel(ShipmentStatusHistory $history): self
    {
        return new self(
            id: $history->id,
            fromStatus: $history->from_status,
            toStatus: $history->to_status,
            changedByUserId: $history->changed_by_user_id,
            reason: $history->reason,
            note: $history->note,
            metadata: $history->metadata ?? [],
            createdAt: Carbon::parse($history->created_at),
        );
    }
}
