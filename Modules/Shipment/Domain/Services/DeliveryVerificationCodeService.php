<?php

declare(strict_types=1);

namespace Modules\Shipment\Domain\Services;

use Illuminate\Support\Facades\Hash;
use Modules\Shipment\Domain\Models\Shipment;

/**
 * The customer's handoff code: the proof that the parcel reached the person who
 * ordered it, and not the doorstep of whoever answered.
 *
 * Three rules make it worth having:
 *
 *  1. The plaintext is generated once, handed to the customer by SMS, and then
 *     forgotten. Only a hash is persisted, so a database or log leak cannot be
 *     replayed into a false delivery — and a lost code is reissued, never recovered.
 *  2. Digits come from a CSPRNG. Nothing about the code is derived from the
 *     shipment id, the public code, the phone number, or the clock, so knowing
 *     any of those tells an attacker nothing.
 *  3. Validity is scoped to the *current delivery attempt*, not to a wall clock.
 *     A failed attempt clears the hash; the next dispatch mints a fresh code.
 */
class DeliveryVerificationCodeService
{
    /**
     * Mint a fresh code and return the plaintext. The caller is responsible for
     * persisting the returned hash and for making sure the plaintext only ever
     * travels to the customer.
     *
     * @return array{code: string, attributes: array<string, mixed>}
     */
    public function issue(): array
    {
        $code = $this->generate();

        return [
            'code' => $code,
            'attributes' => [
                'delivery_verification_code_hash' => Hash::make($code),
                'delivery_verification_issued_at' => now(),
                // A reissue starts a new attempt, so any earlier confirmation
                // timestamp is cleared along with the code it belonged to.
                'delivery_verification_verified_at' => null,
            ],
        ];
    }

    /**
     * Column values that invalidate the active code. Applied when a delivery
     * attempt fails, so the code the customer already holds stops working the
     * moment the courier gives up on that attempt.
     *
     * @return array<string, mixed>
     */
    public function invalidatedAttributes(): array
    {
        return [
            'delivery_verification_code_hash' => null,
            'delivery_verification_issued_at' => null,
        ];
    }

    /**
     * Whether the submitted code matches the shipment's active code.
     *
     * A shipment with no active hash always fails — an attempt that was never
     * dispatched, or one whose code was invalidated, cannot be confirmed. The
     * caller must not tell the submitter which of those it was.
     */
    public function matches(Shipment $shipment, string $submitted): bool
    {
        $hash = $shipment->delivery_verification_code_hash;

        if ($hash === null || $hash === '') {
            return false;
        }

        return Hash::check($this->normalize($submitted), $hash);
    }

    /** Strip the spaces and dashes people type when reading a code aloud. */
    public function normalize(string $code): string
    {
        return preg_replace('/[^0-9]/', '', $code) ?? '';
    }

    private function generate(): string
    {
        $length = $this->length();

        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= (string) random_int(0, 9);
        }

        return $code;
    }

    /** Configurable, but never short enough to guess and never longer than an SMS. */
    public function length(): int
    {
        return min(max((int) config('shipment.delivery.verification_code_length', 6), 4), 10);
    }
}
