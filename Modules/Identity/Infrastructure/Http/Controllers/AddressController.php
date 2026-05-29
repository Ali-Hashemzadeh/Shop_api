<?php

namespace Modules\Identity\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Modules\Identity\Application\Actions\CreateAddress;
use Modules\Identity\Application\Actions\DeleteAddress;
use Modules\Identity\Application\Actions\ListAddresses;
use Modules\Identity\Application\Actions\SetDefaultShippingAddress;
use Modules\Identity\Application\Actions\ShowAddress;
use Modules\Identity\Application\Actions\UpdateAddress;
use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Infrastructure\Http\Requests\ListAddressRequest;
use Modules\Identity\Infrastructure\Http\Requests\SetDefaultShippingAddressRequest;
use Modules\Identity\Infrastructure\Http\Requests\StoreAddressRequest;
use Modules\Identity\Infrastructure\Http\Requests\UpdateAddressRequest;
use Modules\Identity\Infrastructure\Http\Resources\AddressResource;

class AddressController extends Controller
{
    use AuthorizesRequests;

    /**
     * @throws AuthorizationException
     */
    public function index(ListAddressRequest $request, ListAddresses $action): JsonResponse
    {
        $this->authorize('viewAny', Address::class);
        $addresses = $action->handle($request->user());

        return response()->json([
            'data' => AddressResource::collection($addresses),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function store(StoreAddressRequest $request, CreateAddress $action): JsonResponse
    {
        $this->authorize('create', Address::class);
        $address = $action->handle($request->user(), $request->validated());

        return response()->json([
            'message' => 'Address created successfully.',
            'data' => new AddressResource($address),
        ], 201);
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
        print_r($request->all());;
        die();
        return response()->json([
            'message' => 'Address updated successfully.',
            'data' => new AddressResource($action->handle($address, $request->validated())),
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function destroy($address, DeleteAddress $action): JsonResponse
    {
        $address = Address::findOrFail($address);
        $this->authorize('delete', $address);

        $action->handle($address);

        return response()->json([
            'message' => 'Address deleted successfully.',
        ]);
    }

    /**
     * @throws AuthorizationException
     */
    public function setDefaultShipping(SetDefaultShippingAddressRequest $request, Address $address, SetDefaultShippingAddress $action): JsonResponse
    {
        $this->authorize('setDefaultShipping', $address);

        return response()->json([
            'message' => 'Default shipping address updated successfully.',
            'data' => new AddressResource($action->handle($request->user(), $address)),
        ]);
    }
}
