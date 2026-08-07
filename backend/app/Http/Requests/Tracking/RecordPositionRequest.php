<?php

namespace App\Http\Requests\Tracking;

use App\Rules\WithinServiceArea;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One GPS reading from a driver's device.
 *
 * Shape only. Plausibility — speed, jump distance, accuracy, service area
 * relative to the previous fix — is a business rule enforced in the ingestion
 * pipeline, because it depends on state this layer cannot see.
 */
class RecordPositionRequest extends FormRequest
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
            'latitude' => ['required', 'numeric', 'between:-90,90', new WithinServiceArea],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'accuracy_meters' => ['nullable', 'numeric', 'min:0', 'max:10000'],
            'speed_kmh' => ['nullable', 'numeric', 'min:0', 'max:300'],
            'heading' => ['nullable', 'numeric', 'between:0,360'],
            'altitude_meters' => ['nullable', 'integer', 'between:-500,9000'],
            // The device's own clock. Recorded, but the server decides
            // ordering when the two disagree.
            'recorded_at' => ['nullable', 'date'],
            // Required for offline replay to be absorbed rather than counted
            // twice; optional for a live stream.
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }
}
