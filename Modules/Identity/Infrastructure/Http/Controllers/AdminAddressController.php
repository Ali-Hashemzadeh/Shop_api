<?php

namespace Modules\Identity\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Application\Actions\DeleteAddress;
use Modules\Identity\Application\Actions\ShowAddress;
use Modules\Identity\Application\Actions\UpdateAddress;
use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Domain\Models\User;
use Modules\Identity\Infrastructure\Http\Requests\UpdateAddressRequest;
use Modules\Identity\Infrastructure\Http\Resources\AddressResource;

class AdminAddressController extends Controller
{
    use AuthorizesRequests;

    /**
     * @throws AuthorizationException
     */
    public function indexForUser(User $user): JsonResponse
    {
        $this->authorize('viewAny', Address::class);

        $addresses = $user->addresses()
            ->with(['province', 'city'])
            ->orderByDesc('is_default_shipping')
            ->latest('id')
            ->get();

        return response()->json([
            'data' => AddressResource::collection($addresses),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function show(Address $address, ShowAddress $action): JsonResponse
    {
        $this->authorize('view', $address);

        return response()->json([
            'data' => new AddressResource($action->handle($address)),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function update(UpdateAddressRequest $request, Address $address, UpdateAddress $action): JsonResponse
    {
        $this->authorize('update', $address);

        return response()->json([
            'message' => 'Address updated successfully.',
            'data' => new AddressResource($action->handle($address, $request->validated())),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy(Address $address, DeleteAddress $action): JsonResponse
    {
        $this->authorize('delete', $address);

        $action->handle($address);

        return response()->json([
            'message' => 'Address deleted successfully.',
        ]);
    }
}
