<?php

declare(strict_types=1);

namespace Modules\Payment\Infrastructure\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Payment\Application\Actions\HandleZarinpalCallbackAction;
use Modules\Payment\Domain\Contracts\PaymentManagerInterface;
use Modules\Payment\Infrastructure\Http\Requests\InitializePaymentRequest;

class PaymentController extends Controller
{
    public function __construct(
        private readonly PaymentManagerInterface $manager,
        private readonly HandleZarinpalCallbackAction $handleCallback,
    ) {}

    public function initialize(InitializePaymentRequest $request): JsonResponse
    {
        $result = $this->manager->initializePayment(
            orderId: (int) $request->input('order_id'),
            methodType: $request->input('method_type'),
            gateway: $request->input('gateway'),
        );

        return response()->json($result);
    }

    public function zarinpalCallback(Request $request): JsonResponse
    {
        $status = (string) $request->query('Status', '');
        $authority = (string) $request->query('Authority', '');

        if (empty($authority)) {
            return response()->json(['success' => false, 'message' => 'Missing Authority parameter.'], 400);
        }

        $result = $this->handleCallback->handle($status, $authority);

        $httpStatus = $result['success'] ? 200 : 422;

        return response()->json($result, $httpStatus);
    }
}
