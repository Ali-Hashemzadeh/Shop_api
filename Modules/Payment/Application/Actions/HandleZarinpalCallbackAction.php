<?php

declare(strict_types=1);

namespace Modules\Payment\Application\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Cart\Domain\Contracts\CartManagerInterface;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Payment\Domain\Enums\PaymentStatus;
use Modules\Payment\Domain\Events\PaymentFailedEvent;
use Modules\Payment\Domain\Models\Payment;
use Modules\Payment\Infrastructure\Gateways\PaymentGatewayFactory;

class HandleZarinpalCallbackAction
{
    public function __construct(
        private readonly OrderManagerInterface $orderManager,
        private readonly PaymentGatewayFactory $gatewayFactory,
        private readonly CartManagerInterface $cartManager,
    ) {}

    public function handle(string $status, string $authority): array
    {
        $payment = Payment::where('transaction_reference', $authority)->first();

        if ($payment === null) {
            return ['success' => false, 'message' => 'Payment record not found.'];
        }

        if ($status !== 'OK') {
            $payment->update(['status' => PaymentStatus::FAILED->value]);

            return ['success' => false, 'message' => 'Payment was cancelled by the user.', 'payment_id' => $payment->id];
        }

        // Idempotency guard — already captured by a prior callback delivery
        if ($payment->status === PaymentStatus::CAPTURED->value) {
            return ['success' => true, 'message' => 'Payment already captured.', 'payment_id' => $payment->id];
        }

        $driver = $this->gatewayFactory->make($payment->gateway ?? 'zarinpal');
        $result = $driver->verifyPayment($payment->amount, $authority);

        return DB::transaction(function () use ($payment, $result) {
            if (! $result['success']) {
                $payment->update([
                    'status' => PaymentStatus::FAILED->value,
                    'gateway_response' => $result['raw_response'],
                ]);

                // Server-side verification rejected the payment. The owning user
                // comes from the Order contract — the Payment record has only an
                // order id, and no User model crosses this boundary.
                $order = $this->orderManager->findOrder($payment->order_id);

                if ($order !== null) {
                    Event::dispatch(new PaymentFailedEvent(
                        orderId: $order->id,
                        userId: $order->userId,
                    ));
                }

                return ['success' => false, 'message' => 'Payment verification failed.', 'payment_id' => $payment->id];
            }

            $payment->update([
                'status' => PaymentStatus::CAPTURED->value,
                'transaction_reference' => $result['reference_id'],
                'gateway_response' => $result['raw_response'],
            ]);

            $order = $this->orderManager->markAsPaid($payment->order_id, (string) $result['reference_id']);
            $this->cartManager->clearUserCart($order->userId);

            return [
                'success' => true,
                'message' => 'Payment captured successfully.',
                'payment_id' => $payment->id,
                'reference_id' => $result['reference_id'],
            ];
        });
    }
}
