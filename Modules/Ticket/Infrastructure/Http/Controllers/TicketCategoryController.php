<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Modules\Ticket\Domain\DTOs\TicketCategoryDTO;
use Modules\Ticket\Domain\Models\TicketCategory;
use Modules\Ticket\Infrastructure\Http\Resources\TicketCategoryResource;

/**
 * The active ticket categories any authenticated customer can file a ticket
 * under — the picker for the "create ticket" form. Read-only and unfiltered by
 * permission (auth is enough); admin category management lives elsewhere.
 */
class TicketCategoryController extends Controller
{
    public function index(): JsonResponse
    {
        $categories = TicketCategory::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get()
            ->map(fn (TicketCategory $category): TicketCategoryDTO => TicketCategoryDTO::fromModel($category));

        return response()->json([
            'data' => TicketCategoryResource::collection($categories),
        ]);
    }
}
