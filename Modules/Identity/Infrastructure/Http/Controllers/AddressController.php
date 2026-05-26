<?php

namespace Modules\Identity\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Identity\Application\Actions\CreateAddress;
use Modules\Identity\Application\Actions\DeleteAddress;
use Modules\Identity\Application\Actions\ListAddresses;
use Modules\Identity\Application\Actions\SetDefaultShippingAddress;
use Modules\Identity\Application\Actions\ShowAddress;
use Modules\Identity\Application\Actions\UpdateAddress;
use Modules\Identity\Domain\Models\Address;
use Modules\Identity\Infrastructure\Http\Requests\StoreAddressRequest;
use Modules\Identity\Infrastructure\Http\Requests\UpdateAddressRequest;
use Modules\Identity\Infrastructure\Http\Resources\AddressResource;

class AddressController extends Controller
{
    public function index(Request $request, ListAddresses $action): JsonResponse
    {
        $addresses = $action->handle($request->user());

        return response()->json([
            'data' => AddressResource::collection($addresses),
        ]);
    }

    public function store(StoreAddressRequest $request, CreateAddress $action): JsonResponse
    {
        $address = $action->handle($request->user(), $request->validated());

        return response()->json([
            'message' => 'Address created successfully.',
            'data' => new AddressResource($address),
        ], 201);
    }

    public function show(Request $request, Address $address, ShowAddress $action): JsonResponse
    {
        $this->authorize('view', $address);

        return response()->json([
            'data' => new AddressResource($action->handle($address)),
        ]);
    }

    public function update(UpdateAddressRequest $request, Address $address, UpdateAddress $action): JsonResponse
    {
        $this->authorize('update', $address);

        return response()->json([
            'message' => 'Address updated successfully.',
            'data' => new AddressResource($action->handle($address, $request->validated())),
        ]);
    }

    public function destroy(Address $address, DeleteAddress $action): JsonResponse
    {
        $this->authorize('delete', $address);

        $action->handle($address);

        return response()->json([
            'message' => 'Address deleted successfully.',
        ]);
    }

    public function setDefaultShipping(Request $request, Address $address, SetDefaultShippingAddress $action): JsonResponse
    {
        $this->authorize('update', $address);

        return response()->json([
            'message' => 'Default shipping address updated successfully.',
            'data' => new AddressResource($action->handle($request->user(), $address)),
        ]);
    }
}
