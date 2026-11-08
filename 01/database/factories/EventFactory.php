<?php

namespace Database\Factories;

use App\Models\Event;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Event>
 */
class EventFactory extends Factory
{
    protected $model = Event::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $eventTypes = [
            'port_congestion',
            'port_closure',
            'strike',
            'weather_delay',
            'customs_delay',
            'equipment_failure',
            'capacity_shortage',
            'supply_disruption',
        ];

        $locations = [
            'Port of Los Angeles',
            'Port of Long Beach',
            'Port of Oakland',
            'Port of Seattle',
            'Port of Savannah',
            'Port of Houston',
            'Chicago Distribution Center',
            'Memphis Distribution Center',
            'Dallas Distribution Center',
            'Atlanta Manufacturing Plant',
        ];

        $severities = ['low', 'medium', 'high'];
        $sourceTypes = ['email', 'pdf', 'pasted_text', 'api'];

        $eventType = fake()->randomElement($eventTypes);
        $location = fake()->randomElement($locations);
        $severity = fake()->randomElement($severities);

        // Generate context-aware summaries
        $summaries = [
            'port_congestion' => "Heavy congestion reported at {$location} with delays up to 5 days",
            'port_closure' => "{$location} temporarily closed due to operational issues",
            'strike' => "Labor strike affecting operations at {$location}",
            'weather_delay' => "Severe weather causing delays at {$location}",
            'customs_delay' => "Customs processing delays reported at {$location}",
            'equipment_failure' => "Equipment malfunction disrupting operations at {$location}",
            'capacity_shortage' => "Capacity constraints at {$location} impacting throughput",
            'supply_disruption' => "Supply chain disruption affecting {$location}",
        ];

        $rawPayload = [
            'original_text' => fake()->paragraph(3),
            'source' => $sourceTypes[array_rand($sourceTypes)],
            'received_at' => now()->toIso8601String(),
        ];

        $parsedPayload = [
            'event_type' => $eventType,
            'summary' => $summaries[$eventType],
            'location' => $location,
            'severity' => $severity,
            'confidence' => fake()->randomFloat(2, 0.7, 1.0),
            'extracted_at' => now()->toIso8601String(),
        ];

        return [
            'tenant_id' => Tenant::first()?->id ?? Tenant::factory(),
            'user_id' => User::first()?->id ?? User::factory(),
            'event_type' => $eventType,
            'summary' => $summaries[$eventType],
            'location' => $location,
            'severity' => $severity,
            'start_date' => fake()->optional(0.7)->dateTimeBetween('-30 days', '+14 days'),
            'confidence' => fake()->randomFloat(2, 0.7, 1.0),
            'source_type' => fake()->randomElement($sourceTypes),
            'raw_payload' => $rawPayload,
            'parsed_payload' => $parsedPayload,
        ];
    }

    /**
     * Indicate that the event is high severity.
     */
    public function highSeverity(): static
    {
        return $this->state(fn (array $attributes) => [
            'severity' => 'high',
            'confidence' => fake()->randomFloat(2, 0.85, 1.0),
        ]);
    }

    /**
     * Indicate that the event is port-related.
     */
    public function portEvent(): static
    {
        return $this->state(fn (array $attributes) => [
            'event_type' => fake()->randomElement(['port_congestion', 'port_closure']),
            'location' => fake()->randomElement([
                'Port of Los Angeles',
                'Port of Long Beach',
                'Port of Oakland',
                'Port of Seattle',
            ]),
        ]);
    }
}
