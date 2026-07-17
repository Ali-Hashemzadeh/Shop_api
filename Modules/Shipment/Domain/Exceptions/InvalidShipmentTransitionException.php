<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class InvalidShipmentTransitionException extends RuntimeException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct("Cannot transition shipment from '{$from}' to '{$to}'.");
    }

    /** Render as a 422 so an invalid operator action reads as a business error. */
    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], 422);
    }
}
