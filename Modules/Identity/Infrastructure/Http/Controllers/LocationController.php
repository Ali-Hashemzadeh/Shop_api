<?php

namespace Modules\Identity\Infrastructure\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Identity\Domain\Models\Province;
use Modules\Identity\Infrastructure\Http\Resources\CityResource;
use Modules\Identity\Infrastructure\Http\Resources\ProvinceResource;

class LocationController extends Controller
{
    public function provinces(Request $request)
    {
        $query = Province::query()->orderBy('name');

        if ($request->boolean('with_cities')) {
            $query->with([
                'cities' => fn ($query) => $query->orderBy('name'),
            ]);
        }

        $provinces = $query->get();

        return ProvinceResource::collection($provinces);
    }

    public function show(Province $province)
    {
        $province->load([
            'cities' => fn ($query) => $query->orderBy('name'),
        ]);

        return new ProvinceResource($province);
    }

    public function cities(Province $province)
    {
        $cities = $province->cities()
            ->orderBy('name')
            ->get();

        return CityResource::collection($cities);
    }
}
