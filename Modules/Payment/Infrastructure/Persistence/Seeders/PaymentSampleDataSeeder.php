<?php

namespace Modules\Payment\Infrastructure\Persistence\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Order\Domain\Models\Order;
use Modules\Payment\Domain\Enums\PaymentMethodType;
use Modules\Payment\Domain\Enums\PaymentStatus;
use Modules\Payment\Domain\Models\Payment;

/**
 * Seeds a Payment row for each demo order, matching the order's real status:
 *   - transaction_ref "CASH-…"  → in-person cash payment (pending_cash)
 *   - transaction_ref "REF-…"   → online gateway payment (captured)
 *   - failed order              → online payment that failed
 *   - pending order             → online payment initiated but not completed
 *   - cancelled order           → no payment (never attempted)
 * Reads Order (Payment already depends on the Order contract), so the direction
 * of the dependency stays legal.
 */
class PaymentSampleDataSeeder extends Seeder
{
    public function run(): void
    {
        $orders = Order::where('notes', 'like', '[demo]%')->get();

        if ($orders->isEmpty()) {
            $this->command->warn('No demo orders found — run OrderSampleDataSeeder first.');

            return;
        }

        $created = 0;

        foreach ($orders as $order) {
            if (Payment::where('order_id', $order->id)->exists()) {
                continue;
            }

            $payment = $this->paymentFor($order);

            if ($payment === null) {
                continue;
            }

            Payment::create($payment + ['order_id' => $order->id, 'amount' => $order->total_amount]);
            $created++;
        }

        $this->command->info("Payment sample data seeded: {$created} payments across demo orders.");
    }

    /**
     * @return array{method_type:string,gateway:?string,status:string,transaction_reference:?string,gateway_response:?array}|null
     */
    private function paymentFor(Order $order): ?array
    {
        $isCash = $order->transaction_ref !== null && Str::startsWith($order->transaction_ref, 'CASH-');

        return match ($order->status) {
            'paid', 'processing', 'shipped' => $isCash
                ? [
                    'method_type' => PaymentMethodType::IN_PERSON->value,
                    'gateway' => null,
                    'status' => PaymentStatus::PENDING_CASH->value,
                    'transaction_reference' => $order->transaction_ref,
                    'gateway_response' => null,
                ]
                : [
                    'method_type' => PaymentMethodType::ONLINE->value,
                    'gateway' => 'zarinpal',
                    'status' => PaymentStatus::CAPTURED->value,
                    'transaction_reference' => $order->transaction_ref,
                    'gateway_response' => ['ref_id' => $order->transaction_ref, 'code' => 100],
                ],
            'failed' => [
                'method_type' => PaymentMethodType::ONLINE->value,
                'gateway' => 'zarinpal',
                'status' => PaymentStatus::FAILED->value,
                'transaction_reference' => 'REF-'.strtoupper(Str::random(12)),
                'gateway_response' => ['code' => -21, 'message' => 'Payment failed.'],
            ],
            'pending' => [
                'method_type' => PaymentMethodType::ONLINE->value,
                'gateway' => 'zarinpal',
                'status' => PaymentStatus::INITIATED->value,
                'transaction_reference' => 'REF-'.strtoupper(Str::random(12)),
                'gateway_response' => null,
            ],
            // cancelled → payment never attempted.
            default => null,
        };
    }
}
