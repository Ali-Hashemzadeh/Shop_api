<?php

declare(strict_types=1);

namespace Modules\Payment\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Payment\Domain\Enums\PaymentMethodType;
use Modules\Payment\Domain\Enums\PaymentStatus;
use Modules\Payment\Domain\Events\PaymentSuccessfulEvent;
use Modules\Payment\Domain\Models\Payment;
use Modules\Payment\Infrastructure\Gateways\PaymentGatewayFactory;

class InitializePaymentAction
{
    public function __construct(
        private readonly OrderManagerInterface $orderManager,
        private readonly PaymentGatewayFactory $gatewayFactory,
    ) {}

    public function handle(int $orderId, int $userId, string $methodType, ?string $gateway = null, ?string $couponCode = null): array
    {
        $order = $this->orderManager->findOrder($orderId);

        if ($order === null) {
            abort(404, 'Order not found.');
        }

        if ($order->userId !== $userId) {
            abort(403, 'This order does not belong to you.');
        }

        // Payment owns no pricing logic. Order validates and reserves the coupon,
        // recomputes the payable total, and freezes it — and on every retry returns
        // that same frozen total, so all attempts against one order charge alike.
        // Throws 422 for an unusable code or an attempt to change frozen pricing.
        $order = $this->orderManager->finalizeForPayment($orderId, $userId, $couponCode);

        $method = PaymentMethodType::from($methodType);

        if ($method === PaymentMethodType::IN_PERSON) {
            return DB::transaction(function () use ($orderId, $order, $userId) {
                $transactionRef = 'CASH-'.uniqid();

                $payment = Payment::createWithPublicCode([
                    'order_id' => $orderId,
                    'method_type' => PaymentMethodType::IN_PERSON->value,
                    'gateway' => null,
                    'amount' => $order->totalAmount,
                    'status' => PaymentStatus::PENDING_CASH->value,
                    'transaction_reference' => $transactionRef,
                ]);

                $this->orderManager->markAsPaid($orderId, $transactionRef);

                Event::dispatch(new PaymentSuccessfulEvent(
                    orderId: $orderId,
                    userId: $userId,
                    gateway: 'in_person',
                    amount: (int) $order->totalAmount * 10,
                    paymentId: $payment->id,
                    paymentPublicCode: $payment->public_code,
                    orderPublicCode: $order->publicCode,
                    paidAt: now()->toDateTimeString(),
                ));

                return [
                    'type' => 'in_person',
                    'payment_id' => $payment->id,
                    // This response is a plain array carrying both a payment and an
                    // order, so the codes are prefixed rather than a bare
                    // `public_code` that would not say which entity it names.
                    'payment_public_code' => $payment->public_code,
                    'order_public_code' => $order->publicCode,
                    'status' => PaymentStatus::PENDING_CASH->value,
                    'redirect_url' => null,
                ];
            });
        }

        $resolvedGateway = $gateway ?? config('payment.default_gateway');
        $driver = $this->gatewayFactory->make($resolvedGateway);

        $callbackUrl = url('/api/v1/payments/zarinpal/callback');

        $redirectDto = $driver->requestPayment(
            orderId: $orderId,
            amountInCents: $order->totalAmount,
            callbackUrl: $callbackUrl,
            customerMetadata: [],
        );

        $payment = Payment::createWithPublicCode([
            'order_id' => $orderId,
            'method_type' => PaymentMethodType::ONLINE->value,
            'gateway' => $resolvedGateway,
            'amount' => $order->totalAmount,
            'status' => PaymentStatus::INITIATED->value,
            'transaction_reference' => $redirectDto->transactionReference,
        ]);

        return [
            'type' => 'online',
            'payment_id' => $payment->id,
            'payment_public_code' => $payment->public_code,
            'order_public_code' => $order->publicCode,
            'status' => PaymentStatus::INITIATED->value,
            'redirect_url' => $redirectDto->redirectUrl,
        ];
    }
}
