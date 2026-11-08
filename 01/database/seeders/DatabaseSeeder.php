<?php

namespace Database\Seeders;

use App\Models\Edge;
use App\Models\Event;
use App\Models\Node;
use App\Models\Scenario;
use App\Models\ScenarioResult;
use App\Models\Tenant;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create tenant and admin user
        $tenant = Tenant::firstOrCreate([
            'name' => config('seed.tenant_name'),
        ]);

        $user = User::firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'email' => config('seed.admin_email'),
            ],
            [
                'name' => config('seed.admin_name'),
                'password' => Hash::make(config('seed.admin_password')),
                'email_verified_at' => now(),
            ]
        );

        // Seed network topology - Nodes (Ports, DCs, Plants)
        $nodes = [
            // Ports
            ['name' => 'Port of Los Angeles', 'type' => 'port', 'location' => 'Los Angeles, CA', 'capacity' => 5000],
            ['name' => 'Port of Long Beach', 'type' => 'port', 'location' => 'Long Beach, CA', 'capacity' => 4500],
            ['name' => 'Port of Oakland', 'type' => 'port', 'location' => 'Oakland, CA', 'capacity' => 3000],
            ['name' => 'Port of Seattle', 'type' => 'port', 'location' => 'Seattle, WA', 'capacity' => 3500],
            ['name' => 'Port of Savannah', 'type' => 'port', 'location' => 'Savannah, GA', 'capacity' => 4000],
            ['name' => 'Port of Houston', 'type' => 'port', 'location' => 'Houston, TX', 'capacity' => 3800],
            
            // Distribution Centers
            ['name' => 'Chicago DC', 'type' => 'distribution_center', 'location' => 'Chicago, IL', 'capacity' => 8000],
            ['name' => 'Memphis DC', 'type' => 'distribution_center', 'location' => 'Memphis, TN', 'capacity' => 7500],
            ['name' => 'Dallas DC', 'type' => 'distribution_center', 'location' => 'Dallas, TX', 'capacity' => 7000],
            ['name' => 'Atlanta DC', 'type' => 'distribution_center', 'location' => 'Atlanta, GA', 'capacity' => 6500],
            
            // Manufacturing Plants
            ['name' => 'Atlanta Plant', 'type' => 'manufacturing_plant', 'location' => 'Atlanta, GA', 'capacity' => 5000],
            ['name' => 'Detroit Plant', 'type' => 'manufacturing_plant', 'location' => 'Detroit, MI', 'capacity' => 4500],
            ['name' => 'Phoenix Plant', 'type' => 'manufacturing_plant', 'location' => 'Phoenix, AZ', 'capacity' => 4000],
        ];

        $createdNodes = [];
        foreach ($nodes as $nodeData) {
            $createdNodes[$nodeData['name']] = Node::create([
                'tenant_id' => $tenant->id,
                'name' => $nodeData['name'],
                'type' => $nodeData['type'],
                'location' => $nodeData['location'],
                'capacity' => $nodeData['capacity'],
            ]);
        }

        // Seed edges (flows between nodes)
        $edges = [
            // Port to DC flows
            ['origin' => 'Port of Los Angeles', 'destination' => 'Chicago DC', 'lead_time' => 5, 'volume' => 1500, 'cost' => 250],
            ['origin' => 'Port of Los Angeles', 'destination' => 'Dallas DC', 'lead_time' => 4, 'volume' => 1200, 'cost' => 200],
            ['origin' => 'Port of Long Beach', 'destination' => 'Memphis DC', 'lead_time' => 6, 'volume' => 1300, 'cost' => 280],
            ['origin' => 'Port of Long Beach', 'destination' => 'Dallas DC', 'lead_time' => 4, 'volume' => 1100, 'cost' => 210],
            ['origin' => 'Port of Oakland', 'destination' => 'Chicago DC', 'lead_time' => 5, 'volume' => 900, 'cost' => 270],
            ['origin' => 'Port of Seattle', 'destination' => 'Chicago DC', 'lead_time' => 4, 'volume' => 1000, 'cost' => 240],
            ['origin' => 'Port of Seattle', 'destination' => 'Memphis DC', 'lead_time' => 5, 'volume' => 800, 'cost' => 260],
            ['origin' => 'Port of Savannah', 'destination' => 'Atlanta DC', 'lead_time' => 2, 'volume' => 1400, 'cost' => 150],
            ['origin' => 'Port of Savannah', 'destination' => 'Memphis DC', 'lead_time' => 3, 'volume' => 1100, 'cost' => 180],
            ['origin' => 'Port of Houston', 'destination' => 'Dallas DC', 'lead_time' => 2, 'volume' => 1300, 'cost' => 140],
            ['origin' => 'Port of Houston', 'destination' => 'Atlanta DC', 'lead_time' => 3, 'volume' => 1000, 'cost' => 170],
            
            // DC to Plant flows
            ['origin' => 'Chicago DC', 'destination' => 'Detroit Plant', 'lead_time' => 1, 'volume' => 2000, 'cost' => 80],
            ['origin' => 'Memphis DC', 'destination' => 'Atlanta Plant', 'lead_time' => 2, 'volume' => 1800, 'cost' => 100],
            ['origin' => 'Dallas DC', 'destination' => 'Phoenix Plant', 'lead_time' => 2, 'volume' => 1600, 'cost' => 110],
            ['origin' => 'Atlanta DC', 'destination' => 'Atlanta Plant', 'lead_time' => 1, 'volume' => 1900, 'cost' => 70],
            
            // Alternative routes (for rerouting)
            ['origin' => 'Port of Los Angeles', 'destination' => 'Phoenix Plant', 'lead_time' => 3, 'volume' => 500, 'cost' => 180],
            ['origin' => 'Port of Oakland', 'destination' => 'Memphis DC', 'lead_time' => 6, 'volume' => 600, 'cost' => 290],
            ['origin' => 'Chicago DC', 'destination' => 'Atlanta Plant', 'lead_time' => 3, 'volume' => 700, 'cost' => 140],
        ];

        foreach ($edges as $edgeData) {
            Edge::create([
                'tenant_id' => $tenant->id,
                'origin_node_id' => $createdNodes[$edgeData['origin']]->id,
                'destination_node_id' => $createdNodes[$edgeData['destination']]->id,
                'avg_lead_time_days' => $edgeData['lead_time'],
                'lead_time_std_days' => round($edgeData['lead_time'] * 0.2, 1),
                'volume' => $edgeData['volume'],
                'cost_per_unit' => $edgeData['cost'],
            ]);
        }

        // Seed events (disruptions)
        Event::factory()
            ->count(25)
            ->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        // Seed some high-severity port events
        Event::factory()
            ->count(5)
            ->highSeverity()
            ->portEvent()
            ->create(['tenant_id' => $tenant->id, 'user_id' => $user->id]);

        // Seed scenarios
        $events = Event::where('tenant_id', $tenant->id)->limit(10)->get();
        $scenarioTypes = ['port_closure', 'lead_time_inflation', 'labor_capacity_loss'];

        foreach ($events as $index => $event) {
            if ($index >= 10) break;

            $type = $scenarioTypes[$index % 3];
            $parameters = $this->getScenarioParameters($type, $createdNodes);

            $scenario = Scenario::create([
                'tenant_id' => $tenant->id,
                'event_id' => $event->id,
                'name' => ucwords(str_replace('_', ' ', $type)) . " - {$event->location}",
                'scenario_type' => $type,
                'parameters' => $parameters,
                'created_by' => $user->id,
            ]);

            // Create sample results for each scenario
            $resultsCount = rand(1, 3);
            for ($i = 0; $i < $resultsCount; $i++) {
                ScenarioResult::create([
                    'scenario_id' => $scenario->id,
                    'metrics' => $this->generateMetrics($type),
                    'recommendations' => $this->generateRecommendations($type),
                ]);
            }
        }
    }

    private function getScenarioParameters(string $type, array $nodes): array
    {
        $randomNode = array_values($nodes)[array_rand($nodes)];

        return match($type) {
            'port_closure' => [
                'node_id' => $randomNode->id,
                'node_name' => $randomNode->name,
                'duration_days' => rand(7, 30),
            ],
            'lead_time_inflation' => [
                'inflation_percentage' => rand(25, 100),
                'affected_region' => 'West Coast',
            ],
            'labor_capacity_loss' => [
                'node_id' => $randomNode->id,
                'node_name' => $randomNode->name,
                'capacity_reduction_percentage' => rand(15, 40),
            ],
            default => [],
        };
    }

    private function generateMetrics(string $type): array
    {
        return [
            'flows_impacted' => rand(5, 15),
            'total_flows' => 18,
            'percent_impacted' => round(rand(25, 80), 1),
            'avg_lead_time_increase' => round(rand(2, 8), 1),
            'estimated_cost_impact' => rand(50000, 500000),
            'max_delay_days' => rand(3, 12),
        ];
    }

    private function generateRecommendations(string $type): array
    {
        $recommendations = [
            [
                'type' => 'Rerouting',
                'description' => 'Consider rerouting shipments through alternative ports with available capacity',
                'priority' => 'high',
                'estimated_impact' => 'Could reduce delays by 40%',
            ],
            [
                'type' => 'Expedited Shipping',
                'description' => 'Use air freight for critical shipments to maintain service levels',
                'priority' => 'medium',
                'estimated_impact' => 'Additional cost of $50k-$100k',
            ],
            [
                'type' => 'Inventory Adjustment',
                'description' => 'Increase safety stock at downstream locations',
                'priority' => 'low',
                'estimated_impact' => 'Buffer against future disruptions',
            ],
        ];

        return array_slice($recommendations, 0, rand(1, 3));
    }
}
