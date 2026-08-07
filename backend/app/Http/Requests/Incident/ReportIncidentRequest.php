<?php

namespace App\Http\Requests\Incident;

use App\Enums\IncidentClass;
use App\Enums\IncidentSeverity;
use App\Enums\IncidentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * An incident report.
 *
 * The validation deliberately gets *lighter* as the situation gets worse. A
 * driver raising an SOS needs one tap; demanding a description and a
 * photograph from someone in an emergency is indefensible, and the seconds it
 * costs are the seconds that matter.
 */
class ReportIncidentRequest extends FormRequest
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
        $type = $this->incidentType();

        return [
            // TRACKING_LOST is inferred from missing data, never submitted —
            // accepting it here would let a driver manufacture the evidence
            // that their bus went silent.
            'incident_type' => [
                'required',
                Rule::enum(IncidentType::class)->only(IncidentType::reportableCases()),
            ],
            'trip_id' => ['nullable', 'uuid', 'exists:trips,id'],
            // Required for everything except life safety, where the type alone
            // is enough to act on.
            'description' => [
                $type !== null && $type->class() === IncidentClass::LIFE_SAFETY
                    ? 'nullable'
                    : 'required',
                'string', 'max:2000',
            ],
            'severity' => ['nullable', Rule::enum(IncidentSeverity::class)],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            // An id from POST /evidence, not a path. The file is checked
            // and stored before it can be cited here, so the photograph a
            // safety rule demands is one that actually exists.
            'evidence_id' => ['nullable', 'uuid'],
            'vehicle_can_continue' => ['nullable', 'boolean'],
            // The device's own timestamp, honoured for a report queued
            // offline — otherwise every delayed SOS looks like it happened
            // when the signal came back.
            'reported_at' => ['nullable', 'date'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['incident_type', 'severity'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtoupper(trim($this->input($field)))]);
            }
        }
    }

    /**
     * An operational fault needs evidence — it is what the workshop works
     * from, and what justifies taking a bus off the road.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $type = $this->incidentType();

            if ($type?->requiresPhoto() && blank($this->input('evidence_id'))) {
                $validator->errors()->add(
                    'evidence_id',
                    "A photograph is required when reporting {$type->label()}.",
                );
            }
        });
    }

    public function incidentType(): ?IncidentType
    {
        return IncidentType::tryFrom(strtoupper((string) $this->input('incident_type')));
    }
}
