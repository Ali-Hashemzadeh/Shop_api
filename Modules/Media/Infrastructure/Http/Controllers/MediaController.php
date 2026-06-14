<?php

namespace Modules\Media\Infrastructure\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Media\Domain\Contracts\MediaManagerInterface;
use Modules\Media\Domain\Models\Media;
use Modules\Media\Infrastructure\Http\Requests\StoreMediaRequest;
use Modules\Media\Infrastructure\Http\Resources\MediaResource;

class MediaController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly MediaManagerInterface $media,
    ) {}

    public function store(StoreMediaRequest $request): JsonResponse
    {
        $dto = $this->media->upload(
            $request->file('file'),
            $request->input('folder', 'uploads'),
        );

        return response()->json(new MediaResource($dto), 201);
    }

    public function destroy(int $id): JsonResponse
    {
        $this->authorize('delete', Media::class);

        $deleted = $this->media->delete($id);

        return $deleted
            ? response()->json(null, 204)
            : response()->json(['message' => 'Media not found.'], 404);
    }
}
