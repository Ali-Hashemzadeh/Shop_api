<?php

declare(strict_types=1);

namespace Modules\Payment\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Payment\Domain\Enums\PaymentMethodType;
use Modules\Payment\Domain\Enums\PaymentStatus;
use Modules\Payment\Domain\Models\Payment;
use Modules\Payment\Infrastructure\Gateways\PaymentGatewayFactory;

class InitializePaymentAction
{
    public function __construct(
        private readonly OrderManagerInterface $orderManager,
        private readonly PaymentGatewayFactory $gatewayFactory,
    ) {}

    public function handle(int $orderId, string $methodType, ?string $gateway = null): array
    {
        $order = $this->orderManager->findOrder($orderId);

        if ($order === null) {
            abort(404, 'Order not found.');
        }

        $method = PaymentMethodType::from($methodType);


        if ($method === PaymentMethodType::IN_PERSON) {
            return DB::transaction(function () use ($orderId, $order) {
                $transactionRef = 'CASH-'.uniqid();

                $payment = Payment::create([
                    'order_id' => $orderId,
                    'method_type' => PaymentMethodType::IN_PERSON->value,
                    'gateway' => null,
                    'amount' => $order->totalAmount,
                    'status' => PaymentStatus::PENDING_CASH->value,
                    'transaction_reference' => $transactionRef,
                ]);

                $this->orderManager->markAsPaid($orderId, $transactionRef);

                return [
                    'type' => 'in_person',
                    'payment_id' => $payment->id,
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

        $payment = Payment::create([
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
            'status' => PaymentStatus::INITIATED->value,
            'redirect_url' => $redirectDto->redirectUrl,
        ];
    }
}
