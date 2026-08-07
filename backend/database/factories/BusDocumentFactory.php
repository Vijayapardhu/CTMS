<?php

namespace Database\Factories;

use App\Enums\DocumentType;
use App\Models\Bus;
use App\Models\BusDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusDocument>
 */
class BusDocumentFactory extends Factory
{
    protected $model = BusDocument::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'bus_id' => Bus::factory(),
            'document_type' => DocumentType::FITNESS->value,
            'document_number' => strtoupper(fake()->bothify('??######')),
            'issuing_authority' => 'Regional Transport Office',
            'issued_on' => now()->subMonths(6)->toDateString(),
            'expires_on' => now()->addMonths(6)->toDateString(),
        ];
    }

    public function ofType(DocumentType $type): static
    {
        return $this->state(['document_type' => $type->value]);
    }

    public function expired(): static
    {
        return $this->state([
            'issued_on' => now()->subYear()->toDateString(),
            'expires_on' => now()->subDay()->toDateString(),
        ]);
    }

    /**
     * Expires today — still valid, because cover runs to the end of its last day.
     */
    public function expiringToday(): static
    {
        return $this->state(['expires_on' => now()->toDateString()]);
    }

    public function expiringInDays(int $days): static
    {
        return $this->state(['expires_on' => now()->addDays($days)->toDateString()]);
    }
}
