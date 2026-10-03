<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Ticket\Domain\DTOs\TicketCategoryDTO;
use Modules\Ticket\Domain\Models\TicketCategory;
use Modules\Ticket\Infrastructure\Http\Requests\ManageCategoriesRequest;
use Modules\Ticket\Infrastructure\Http\Requests\StoreTicketCategoryRequest;
use Modules\Ticket\Infrastructure\Http\Requests\UpdateTicketCategoryRequest;
use Modules\Ticket\Infrastructure\Http\Resources\TicketCategoryResource;

/**
 * Admin management of ticket categories (`ticket.manage-categories`). The `code`
 * is immutable once created because tickets store it, not the id — so an update
 * changes only the display name, activation, and ordering.
 */
class AdminTicketCategoryController extends Controller
{
    public function index(ManageCategoriesRequest $request): JsonResponse
    {
        $categories = TicketCategory::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (TicketCategory $category): TicketCategoryDTO => TicketCategoryDTO::fromModel($category));

        return response()->json([
            'data' => TicketCategoryResource::collection($categories),
        ]);
    }

    public function store(StoreTicketCategoryRequest $request): JsonResponse
    {
        $category = TicketCategory::create([
            'name' => $request->validated('name'),
            'code' => $request->validated('code'),
            'is_active' => $request->boolean('is_active', true),
            'sort_order' => (int) $request->validated('sort_order', 0),
        ]);

        return response()->json(
            ['data' => new TicketCategoryResource(TicketCategoryDTO::fromModel($category))],
            201,
        );
    }

    public function update(UpdateTicketCategoryRequest $request, TicketCategory $ticketCategory): JsonResponse
    {
        $ticketCategory->fill($request->safe()->only(['name', 'is_active', 'sort_order']));
        $ticketCategory->save();

        return response()->json(['data' => new TicketCategoryResource(TicketCategoryDTO::fromModel($ticketCategory))]);
    }

    public function destroy(ManageCategoriesRequest $request, TicketCategory $ticketCategory): JsonResponse
    {
        $ticketCategory->delete();

        return response()->json(['message' => 'Category deleted successfully.']);
    }
}
