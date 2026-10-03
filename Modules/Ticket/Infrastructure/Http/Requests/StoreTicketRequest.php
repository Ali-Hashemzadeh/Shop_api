<?php

declare(strict_types=1);

namespace Modules\Ticket\Infrastructure\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Ticket\Domain\Contracts\TicketReferenceValidatorInterface;
use Modules\Ticket\Domain\Enums\TicketPriority;
use Modules\Ticket\Domain\Enums\TicketReferenceType;
use Modules\Ticket\Infrastructure\Http\Requests\Concerns\ValidatesMediaOwnership;

/**
 * Open a ticket. `authorize()` runs before validation, so an unauthorized caller
 * gets 403, never a 422 bleed-through.
 *
 * References are validated for shape (a known type + a code the customer quoted)
 * AND for ownership: a customer may only reference their own entities. Ownership
 * is decided by the owning module, not Ticket — each module registers a
 * {@see TicketReferenceValidatorInterface} under the `ticket.reference_validators`
 * container tag and answers from its own tables, so Ticket stays decoupled and
 * never imports an Order/Payment/Shipment model. Reference types nobody owns
 * (e.g. `product`/`variant`) have no validator and remain informational.
 */
class StoreTicketRequest extends FormRequest
{
    use ValidatesMediaOwnership;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('ticket.create');
    }

    public function rules(): array
    {
        return [
            'subject' => ['required', 'string', 'max:255'],
            'category' => [
                'nullable',
                'string',
                Rule::exists('ticket_categories', 'code')->where('is_active', true),
            ],
            'priority' => ['required', 'string', Rule::in(TicketPriority::values())],
            'message' => ['required', 'string'],
            'references' => ['sometimes', 'nullable', 'array'],
            'references.*.type' => ['required_with:references', 'string', Rule::in(TicketReferenceType::values())],
            'references.*.code' => ['required_with:references', 'string', 'max:255'],
            ...$this->mediaIdsRules(),
        ];
    }

    /**
     * Ownership gate for references. Runs after the shape rules so `type`/`code`
     * are already known-good; for each reference whose type has a registered
     * owning-module validator, the quoted code must belong to the caller.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Attachments must belong to the caller (see the trait).
            $this->validateMediaOwnership($validator);

            $references = $this->input('references');

            if (! is_array($references) || $references === []) {
                return;
            }

            $userId = (int) $this->user()->getAuthIdentifier();
            $validators = $this->referenceValidatorsByType();

            foreach ($references as $index => $reference) {
                $type = is_array($reference) ? ($reference['type'] ?? null) : null;
                $code = is_array($reference) ? ($reference['code'] ?? null) : null;

                if (! is_string($type) || ! is_string($code) || ! isset($validators[$type])) {
                    continue; // unowned type (product/variant) or already shape-invalid
                }

                if (! $validators[$type]->ownedByUser($code, $userId)) {
                    $validator->errors()->add(
                        "references.{$index}.code",
                        'The referenced item was not found or does not belong to you.',
                    );
                }
            }
        });
    }

    /**
     * @return array<string, TicketReferenceValidatorInterface> keyed by reference type
     */
    private function referenceValidatorsByType(): array
    {
        $map = [];

        /** @var iterable<TicketReferenceValidatorInterface> $tagged */
        $tagged = app()->tagged('ticket.reference_validators');

        foreach ($tagged as $validator) {
            if ($validator instanceof TicketReferenceValidatorInterface) {
                $map[$validator->type()] = $validator;
            }
        }

        return $map;
    }

    public function messages(): array
    {
        return [
            'category.exists' => 'The selected category is not available.',
        ];
    }
}
