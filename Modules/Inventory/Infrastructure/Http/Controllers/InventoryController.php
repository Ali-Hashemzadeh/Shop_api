<?php

declare(strict_types=1);

namespace Modules\Inventory\Infrastructure\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Modules\Inventory\Infrastructure\Http\Requests\IndexLedgerRequest;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Modules\Inventory\Application\Actions\UpdateStockAction;
use Modules\Inventory\Domain\Contracts\InventoryManagerInterface;
use Modules\Inventory\Domain\Exceptions\StockNotFoundException;
use Modules\Inventory\Domain\Models\InventoryLedgerEntry;
use Modules\Inventory\Domain\Models\InventoryStock;
use Modules\Inventory\Infrastructure\Http\Requests\AdjustStockRequest;
use Modules\Inventory\Infrastructure\Http\Requests\BatchStockRequest;
use Modules\Inventory\Infrastructure\Http\Resources\InventoryLedgerEntryResource;
use Modules\Inventory\Infrastructure\Http\Resources\InventoryStockResource;

class InventoryController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly InventoryManagerInterface $inventory,
        private readonly UpdateStockAction $updateStockAction,
    ) {}

    public function showBySku(string $sku): JsonResponse
    {
        try {
            $dto = $this->inventory->getStockBySku($sku);
        } catch (StockNotFoundException) {
            return response()->json(['message' => 'Stock record not found.'], 404);
        }

        return response()->json(new InventoryStockResource($dto));
    }

    public function batchShow(BatchStockRequest $request): JsonResponse
    {
        $stocks = $this->inventory->getBatchStockBySkus($request->validated('skus', []));

        $result = collect($stocks)->mapWithKeys(
            fn ($dto, string $sku) => [$sku => new InventoryStockResource($dto)]
        );

        return response()->json($result);
    }

    public function adjust(AdjustStockRequest $request): JsonResponse
    {
        $dto = $this->updateStockAction->handle(
            $request->validated('sku'),
            $request->integer('quantity_change'),
            $request->validated('type'),
            $request->validated('notes'),
        );

        return response()->json(new InventoryStockResource($dto));
    }

    public function ledger(string $sku, IndexLedgerRequest $request): AnonymousResourceCollection
    {
        $this->authorize('viewLedger', InventoryStock::class);

        $entries = InventoryLedgerEntry::where('sku', $sku)
            ->latest('created_at')
            ->paginate($request->integer('per_page', 15));

        return InventoryLedgerEntryResource::collection($entries);
    }
}
