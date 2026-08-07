<?php

namespace Database\Factories;

use App\Enums\BusStatus;
use App\Enums\DocumentType;
use App\Models\Bus;
use App\Models\BusDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Bus>
 */
class BusFactory extends Factory
{
    protected $model = Bus::class;

    /**
     * Every NOT NULL column in the buses table must be produced here, or the
     * factory becomes a source of false test failures.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'registration_number' => 'KA-'.fake()->numberBetween(10, 99).'-'
                .fake()->regexify('[A-Z]{2}').'-'.fake()->unique()->numberBetween(1000, 9999),
            'vehicle_name' => 'Campus Shuttle '.fake()->numberBetween(1, 50),
            'model' => fake()->randomElement(['Ashok Leyland 2023', 'Tata Starbus 2024', 'Eicher Skyline 2022']),
            'year_of_manufacture' => fake()->numberBetween(2015, 2025),
            'seating_capacity' => fake()->randomElement([40, 50, 60]),
            'status' => BusStatus::AVAILABLE->value,
            'fuel_type' => fake()->randomElement(['DIESEL', 'CNG', 'ELECTRIC']),
            'mileage' => fake()->randomFloat(2, 5000, 50000),
        ];
    }

    /**
     * A bus in the fleet has its papers. Every mandatory document (BR-055) is
     * created valid, so the factory produces a vehicle that can actually be
     * assigned — tests needing a non-compliant bus ask for one explicitly.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (Bus $bus) {
            foreach (DocumentType::mandatory() as $type) {
                BusDocument::factory()->ofType($type)->create(['bus_id' => $bus->id]);
            }
        });
    }

    /**
     * A bus with no statutory documents recorded at all.
     *
     * Implemented by removing what configure() created rather than by
     * suppressing it: factory instances are immutable, so a flag set on a
     * derived instance is not visible to the callback registered on the
     * original.
     */
    public function withoutDocuments(): static
    {
        return $this->afterCreating(function (Bus $bus) {
            $bus->documents()->forceDelete();
        });
    }

    /**
     * A bus whose named document has lapsed. Created after the valid set, and
     * supersedes it, so the bus has exactly one current document of that type.
     */
    public function withExpiredDocument(DocumentType $type = DocumentType::INSURANCE): static
    {
        return $this->afterCreating(function (Bus $bus) use ($type) {
            $bus->documents()->where('document_type', $type->value)->forceDelete();

            BusDocument::factory()->ofType($type)->expired()->create(['bus_id' => $bus->id]);
        });
    }

    public function running(): static
    {
        return $this->state(['status' => BusStatus::RUNNING->value]);
    }

    public function inMaintenance(): static
    {
        return $this->state(['status' => BusStatus::MAINTENANCE->value]);
    }

    public function brokenDown(): static
    {
        return $this->state(['status' => BusStatus::BREAKDOWN->value]);
    }

    public function offline(): static
    {
        return $this->state(['status' => BusStatus::OFFLINE->value]);
    }

    public function withCapacity(int $seats): static
    {
        return $this->state(['seating_capacity' => $seats]);
    }
}
