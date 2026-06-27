<?php

declare(strict_types=1);

namespace Modules\Payment\Application\Actions;

use Illuminate\Support\Facades\DB;
use Modules\Order\Domain\Contracts\OrderManagerInterface;
use Modules\Payment\Domain\Enums\PaymentStatus;
use Modules\Payment\Domain\Models\Payment;
use Modules\Payment\Infrastructure\Gateways\PaymentGatewayFactory;
use Throwable;

class ExpireStalePaymentsAction
{
    public function __construct(
        private readonly PaymentGatewayFactory $gatewayFactory,
        private readonly OrderManagerInterface $orderManager,
    ) {}

    /**
     * Finds every INITIATED payment older than 30 minutes and asks the gateway
     * whether it was actually captured (lost callback) or truly expired.
     *
     * Returns ['expired' => int, 'recovered' => int].
     */
    public function handle(): array
    {
        $cutoff = now()->subMinutes(30);
        $expired = 0;
        $recovered = 0;

        Payment::where('status', PaymentStatus::INITIATED->value)
            ->where('created_at', '<', $cutoff)
            ->each(function (Payment $payment) use (&$expired, &$recovered): void {
                try {
                    $driver = $this->gatewayFactory->make($payment->gateway ?? 'zarinpal');
                    $result = $driver->verifyPayment($payment->amount, $payment->transaction_reference);

                    DB::transaction(function () use ($payment, $result, &$expired, &$recovered): void {
                        if ($result['success']) {
                            // Callback was lost but payment went through — recover it.
                            $payment->update([
                                'status' => PaymentStatus::CAPTURED->value,
                                'transaction_reference' => $result['reference_id'],
                                'gateway_response' => $result['raw_response'],
                            ]);
                            $this->orderManager->markAsPaid($payment->order_id, (string) $result['reference_id']);
                            $recovered++;
                        } else {
                            $payment->update([
                                'status' => PaymentStatus::FAILED->value,
                                'gateway_response' => $result['raw_response'],
                            ]);
                            $expired++;
                        }
                    });
                } catch (Throwable) {
                    // One gateway error must not block the rest of the batch.
                }
            });

        return ['expired' => $expired, 'recovered' => $recovered];
    }
}
