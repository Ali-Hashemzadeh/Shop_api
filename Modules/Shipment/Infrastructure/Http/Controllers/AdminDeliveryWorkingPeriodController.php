<?php

declare(strict_types=1);

namespace Modules\Shipment\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Modules\Shipment\Domain\Models\DeliveryWorkingPeriod;
use Modules\Shipment\Infrastructure\Http\Requests\StoreDeliveryWorkingPeriodRequest;
use Modules\Shipment\Infrastructure\Http\Requests\UpdateDeliveryWorkingPeriodRequest;
use Modules\Shipment\Infrastructure\Http\Resources\DeliveryWorkingPeriodResource;

class AdminDeliveryWorkingPeriodController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('shipment.slot.view-admin'), 403);

        $periods = DeliveryWorkingPeriod::query()->orderBy('weekday')->orderBy('starts_at')->get();

        return response()->json(['data' => DeliveryWorkingPeriodResource::collection($periods)]);
    }

    public function store(StoreDeliveryWorkingPeriodRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->ensureNoOverlap((int) $data['weekday'], $data['starts_at'], $data['ends_at']);
        $period = DeliveryWorkingPeriod::create($data);

        return response()->json(new DeliveryWorkingPeriodResource($period), 201);
    }

    public function update(UpdateDeliveryWorkingPeriodRequest $request, DeliveryWorkingPeriod $workingPeriod): JsonResponse
    {
        $data = $request->validated();
        $weekday = (int) ($data['weekday'] ?? $workingPeriod->weekday);
        $startsAt = (string) ($data['starts_at'] ?? $workingPeriod->starts_at);
        $endsAt = (string) ($data['ends_at'] ?? $workingPeriod->ends_at);

        if ($endsAt <= $startsAt) {
            throw ValidationException::withMessages(['ends_at' => ['The end time must be after the start time.']]);
        }

        $this->ensureNoOverlap($weekday, $startsAt, $endsAt, $workingPeriod->id);
        $workingPeriod->update($data);

        return response()->json(new DeliveryWorkingPeriodResource($workingPeriod->refresh()));
    }

    public function destroy(Request $request, DeliveryWorkingPeriod $workingPeriod): JsonResponse
    {
        abort_unless((bool) $request->user()?->can('shipment.slot.manage'), 403);
        $workingPeriod->delete();

        return response()->json(null, 204);
    }

    private function ensureNoOverlap(int $weekday, string $startsAt, string $endsAt, ?int $ignoreId = null): void
    {
        $overlaps = DeliveryWorkingPeriod::query()
            ->where('weekday', $weekday)
            ->when($ignoreId, fn ($query) => $query->whereKeyNot($ignoreId))
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->exists();

        if ($overlaps) {
            throw ValidationException::withMessages(['starts_at' => ['This working period overlaps another period on the same weekday.']]);
        }
    }
}
