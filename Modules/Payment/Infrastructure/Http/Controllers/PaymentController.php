<?php

declare(strict_types=1);

namespace Modules\Payment\Infrastructure\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Payment\Application\Actions\HandleZarinpalCallbackAction;
use Modules\Payment\Domain\Contracts\PaymentManagerInterface;
use Modules\Payment\Domain\Enums\PaymentStatus;
use Modules\Payment\Domain\Models\Payment;
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
            userId: $request->user()->id,
            methodType: $request->input('method_type'),
            gateway: $request->input('gateway'),
        );

        return response()->json($result);
    }

    /**
     * Gateway return endpoint. The gateway hits this backend URL; we still run
     * server-side verification and persistence, then render the Payment result
     * page (Blade) instead of JSON. Buttons on the page navigate to the
     * configured frontend domain — the callback itself never leaves the backend.
     */
    public function zarinpalCallback(Request $request): View
    {
        $status = (string) $request->query('Status', '');
        $authority = (string) $request->query('Authority', '');

        // Without an Authority we cannot safely resolve a Payment record, so
        // fall through to a generic failure page (no internal details leaked).
        $result = $authority === ''
            ? ['payment_id' => null]
            : $this->handleCallback->handle($status, $authority);

        return view('payment::payment', $this->buildResultView($result));
    }

    /**
     * Assemble the result-page data purely from the persisted, server-verified
     * Payment state — never from raw callback query parameters. Navigation URLs
     * come from config only, so they cannot be overridden by the caller.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function buildResultView(array $result): array
    {
        $paymentId = $result['payment_id'] ?? null;
        $payment = $paymentId !== null ? Payment::find($paymentId) : null;

        $success = $payment !== null
            && $payment->status === PaymentStatus::CAPTURED->value;

        $home = rtrim((string) config('frontend.url'), '/');
        if ($home === '') {
            $home = rtrim((string) config('app.url'), '/');
        }

        $orderUrl = null;
        if ($payment !== null && $home !== '') {
            $orderPath = trim((string) config('frontend.order_path'), '/');
            $orderUrl = $orderPath === ''
                ? $home.'/'.$payment->order_id
                : $home.'/'.$orderPath.'/'.$payment->order_id;
        }

        return [
            'success' => $success,
            'gateway' => $payment?->gateway,
            'trackId' => $payment?->transaction_reference,
            'date' => $payment?->updated_at?->format('Y-m-d H:i'),
            'frontendHomeUrl' => $home !== '' ? $home : null,
            'frontendOrderUrl' => $orderUrl,
        ];
    }
}
