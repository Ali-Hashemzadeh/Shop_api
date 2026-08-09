<?php

declare(strict_types=1);

namespace Modules\Promotion\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Modules\Promotion\Application\Actions\SaveCampaignAction;
use Modules\Promotion\Domain\Contracts\PromotionManagerInterface;
use Modules\Promotion\Infrastructure\Http\Requests\IndexPromotionRequest;
use Modules\Promotion\Infrastructure\Http\Requests\StoreCampaignRequest;
use Modules\Promotion\Infrastructure\Http\Requests\UpdateCampaignRequest;
use Modules\Promotion\Infrastructure\Http\Resources\CampaignResource;

class AdminCampaignController extends Controller
{
    public function __construct(
        private readonly PromotionManagerInterface $promotion,
        private readonly SaveCampaignAction $save,
    ) {}

    public function index(IndexPromotionRequest $request): AnonymousResourceCollection
    {
        return CampaignResource::collection(
            $this->promotion->getCampaigns($request->filters(), $request->perPage())
        );
    }

    public function show(IndexPromotionRequest $request, int $campaign): JsonResponse
    {
        $dto = $this->promotion->findCampaign($campaign);

        if ($dto === null) {
            return response()->json(['message' => 'Campaign not found.'], 404);
        }

        return response()->json(new CampaignResource($dto));
    }

    public function store(StoreCampaignRequest $request): JsonResponse
    {
        return response()->json(
            new CampaignResource($this->save->create($request->validated())),
            201
        );
    }

    public function update(UpdateCampaignRequest $request, int $campaign): JsonResponse
    {
        return response()->json(
            new CampaignResource($this->save->update($campaign, $request->validated()))
        );
    }

    public function destroy(int $campaign): JsonResponse
    {
        abort_unless(request()->user()?->can('promotion.campaign.manage'), 403);

        $this->save->delete($campaign);

        return response()->json(null, 204);
    }
}
