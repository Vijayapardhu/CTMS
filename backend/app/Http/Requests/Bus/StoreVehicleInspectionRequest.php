<?php

namespace App\Http\Requests\Bus;

use App\Enums\InspectionItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * A submitted pre-trip inspection (BR-107).
 *
 * The outcome is never accepted from the client — it is derived from the item
 * verdicts by VehicleInspectionService, so a driver cannot submit a set of
 * failures marked "passed".
 */
class StoreVehicleInspectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'odometer_reading' => ['required', 'integer', 'min:0', 'max:9999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'size:'.count(InspectionItem::cases())],
            'items.*.item' => ['required', Rule::enum(InspectionItem::class)],
            'items.*.passed' => ['required', 'boolean'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'items.*.evidence_id' => ['nullable', 'uuid'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $items = $this->input('items');

        if (! is_array($items)) {
            return;
        }

        foreach ($items as $index => $item) {
            if (is_array($item) && is_string($item['item'] ?? null)) {
                $items[$index]['item'] = strtoupper(trim($item['item']));
            }
        }

        $this->merge(['items' => $items]);
    }

    /**
     * A failed item needs an explanation, and a failed safety-critical item
     * needs evidence. A bus taken off the road on an unexplained tick is not
     * a defensible record.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $items = $this->input('items');

            if (! is_array($items)) {
                return;
            }

            foreach ($items as $index => $item) {
                if (! is_array($item) || filter_var($item['passed'] ?? null, FILTER_VALIDATE_BOOLEAN)) {
                    continue;
                }

                $case = InspectionItem::tryFrom(strtoupper((string) ($item['item'] ?? '')));

                if ($case === null) {
                    continue;
                }

                if (blank($item['notes'] ?? null)) {
                    $validator->errors()->add(
                        "items.{$index}.notes",
                        "Describe the fault found on {$case->label()}.",
                    );
                }

                if ($case->isSafetyCritical() && blank($item['evidence_id'] ?? null)) {
                    $validator->errors()->add(
                        "items.{$index}.evidence_id",
                        "A photograph is required for a {$case->label()} failure.",
                    );
                }
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.size' => 'Every checklist item must be answered.',
        ];
    }
}
