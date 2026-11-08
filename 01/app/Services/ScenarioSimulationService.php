<?php

namespace App\Services;

use App\Models\Edge;
use App\Models\Node;
use App\Models\Scenario;
use App\Models\ScenarioResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ScenarioSimulationService
{
    /**
     * Execute a scenario simulation.
     *
     * @param  Scenario  $scenario  The scenario to execute
     * @return ScenarioResult The generated result
     *
     * @throws \Exception
     */
    public function executeScenario(Scenario $scenario): ScenarioResult
    {
        $tenantId = $scenario->tenant_id;

        // Execute appropriate simulation based on type
        $result = match ($scenario->scenario_type) {
            'port_closure' => $this->simulatePortClosure($scenario, $tenantId),
            'lead_time_inflation' => $this->simulateLeadTimeInflation($scenario, $tenantId),
            'labor_capacity_loss' => $this->simulateLaborCapacityLoss($scenario, $tenantId),
            default => throw new \Exception("Unknown scenario type: {$scenario->scenario_type}"),
        };

        // Create and save the result
        $scenarioResult = ScenarioResult::create([
            'scenario_id' => $scenario->id,
            'metrics' => $result['metrics'],
            'recommendations' => $result['recommendations'],
        ]);

        Log::info('Scenario executed successfully', [
            'scenario_id' => $scenario->id,
            'type' => $scenario->scenario_type,
            'flows_impacted' => $result['metrics']['flows_impacted'] ?? 0,
        ]);

        return $scenarioResult;
    }

    /**
     * Simulate a port closure scenario.
     */
    protected function simulatePortClosure(Scenario $scenario, int $tenantId): array
    {
        $nodeId = $scenario->parameters['node_id'] ?? null;
        $durationDays = $scenario->parameters['duration_days'] ?? 7;

        if (! $nodeId) {
            throw new \Exception('Port closure scenario requires node_id parameter');
        }

        $node = Node::where('tenant_id', $tenantId)->findOrFail($nodeId);

        // Find all edges that use this node (either as origin or destination)
        $affectedEdges = Edge::where('tenant_id', $tenantId)
            ->where(function ($query) use ($nodeId) {
                $query->where('origin_node_id', $nodeId)
                    ->orWhere('destination_node_id', $nodeId);
            })
            ->with(['originNode', 'destinationNode'])
            ->get();

        $totalEdges = Edge::where('tenant_id', $tenantId)->count();
        $impactedCount = $affectedEdges->count();

        // Calculate total volume impact
        $totalVolumeImpacted = $affectedEdges->sum('volume');

        // Find alternative routes
        $alternatives = $this->findAlternativeRoutes($affectedEdges, $nodeId, $tenantId);

        // Calculate cost impact
        $estimatedCostImpact = $this->calculateClosureCostImpact(
            $totalVolumeImpacted,
            $durationDays,
            $affectedEdges->avg('cost_per_unit')
        );

        $metrics = [
            'flows_impacted' => $impactedCount,
            'total_flows' => $totalEdges,
            'percent_impacted' => $totalEdges > 0 ? round(($impactedCount / $totalEdges) * 100, 1) : 0,
            'total_volume_impacted' => $totalVolumeImpacted,
            'avg_lead_time_increase' => $durationDays,
            'estimated_cost_impact' => $estimatedCostImpact,
            'closure_duration_days' => $durationDays,
        ];

        $recommendations = $this->generateClosureRecommendations($node, $alternatives, $affectedEdges);

        return [
            'metrics' => $metrics,
            '

recommendations' => $recommendations,
        ];
    }

    /**
     * Simulate lead time inflation scenario.
     */
    protected function simulateLeadTimeInflation(Scenario $scenario, int $tenantId): array
    {
        $inflationPercentage = $scenario->parameters['inflation_percentage'] ?? 50;
        $affectedRegion = $scenario->parameters['affected_region'] ?? null;

        // Get all edges (or filter by region if specified)
        $query = Edge::where('tenant_id', $tenantId)->with(['originNode', 'destinationNode']);

        if ($affectedRegion) {
            // Filter edges where origin or destination is in the affected region
            $query->whereHas('originNode', function ($q) use ($affectedRegion) {
                $q->where('location', 'like', "%{$affectedRegion}%");
            })->orWhereHas('destinationNode', function ($q) use ($affectedRegion) {
                $q->where('location', 'like', "%{$affectedRegion}%");
            });
        }

        $edges = $query->get();
        $totalEdges = Edge::where('tenant_id', $tenantId)->count();

        // Calculate new lead times
        $inflationMultiplier = 1 + ($inflationPercentage / 100);
        $totalDelay = 0;
        $affectedCount = 0;

        foreach ($edges as $edge) {
            $originalLeadTime = $edge->avg_lead_time_days;
            $newLeadTime = $originalLeadTime * $inflationMultiplier;
            $additionalDelay = $newLeadTime - $originalLeadTime;
            $totalDelay += $additionalDelay;
            $affectedCount++;
        }

        $avgLeadTimeIncrease = $affectedCount > 0 ? round($totalDelay / $affectedCount, 1) : 0;

        // Calculate cost impact
        $estimatedCostImpact = $this->calculateDelayedCostImpact(
            $edges->sum('volume'),
            $avgLeadTimeIncrease,
            $edges->avg('cost_per_unit')
        );

        $metrics = [
            'flows_impacted' => $affectedCount,
            'total_flows' => $totalEdges,
            'percent_impacted' => $totalEdges > 0 ? round(($affectedCount / $totalEdges) * 100, 1) : 0,
            'avg_lead_time_increase' => $avgLeadTimeIncrease,
            'inflation_percentage' => $inflationPercentage,
            'estimated_cost_impact' => $estimatedCostImpact,
            'total_additional_days' => round($totalDelay, 1),
        ];

        $recommendations = $this->generateInflationRecommendations($inflationPercentage, $affectedRegion);

        return [
            'metrics' => $metrics,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Simulate labor capacity loss scenario.
     */
    protected function simulateLaborCapacityLoss(Scenario $scenario, int $tenantId): array
    {
        $nodeId = $scenario->parameters['node_id'] ?? null;
        $capacityReduction = $scenario->parameters['capacity_reduction_percentage'] ?? 25;

        if (! $nodeId) {
            throw new \Exception('Labor capacity loss scenario requires node_id parameter');
        }

        $node = Node::where('tenant_id', $tenantId)->findOrFail($nodeId);

        // Calculate new capacity
        $originalCapacity = $node->capacity;
        $newCapacity = $originalCapacity * (1 - ($capacityReduction / 100));
        $capacityLost = $originalCapacity - $newCapacity;

        // Find flows using this node as destination (incoming volume)
        $incomingEdges = Edge::where('tenant_id', $tenantId)
            ->where('destination_node_id', $nodeId)
            ->get();

        $totalIncomingVolume = $incomingEdges->sum('volume');
        $overload = max(0, $totalIncomingVolume - $newCapacity);
        $congestionLevel = $newCapacity > 0 ? ($totalIncomingVolume / $newCapacity) : 0;

        // Estimate delay based on congestion
        $estimatedDelayDays = $this->calculateCongestionDelay($congestionLevel);

        // Find all edges through this node
        $affectedEdges = Edge::where('tenant_id', $tenantId)
            ->where(function ($query) use ($nodeId) {
                $query->where('origin_node_id', $nodeId)
                    ->orWhere('destination_node_id', $nodeId);
            })
            ->get();

        $totalEdges = Edge::where('tenant_id', $tenantId)->count();

        $estimatedCostImpact = $this->calculateCapacityCostImpact(
            $overload,
            $estimatedDelayDays,
            $affectedEdges->avg('cost_per_unit')
        );

        $metrics = [
            'flows_impacted' => $affectedEdges->count(),
            'total_flows' => $totalEdges,
            'percent_impacted' => $totalEdges > 0 ? round(($affectedEdges->count() / $totalEdges) * 100, 1) : 0,
            'original_capacity' => $originalCapacity,
            'new_capacity' => round($newCapacity),
            'capacity_reduction_percentage' => $capacityReduction,
            'total_incoming_volume' => $totalIncomingVolume,
            'volume_overload' => round($overload),
            'congestion_ratio' => round($congestionLevel, 2),
            'avg_lead_time_increase' => round($estimatedDelayDays, 1),
            'estimated_cost_impact' => $estimatedCostImpact,
        ];

        $recommendations = $this->generateCapacityRecommendations($node, $capacityReduction, $overload, $tenantId);

        return [
            'metrics' => $metrics,
            'recommendations' => $recommendations,
        ];
    }

    /**
     * Find alternative routes when a node is closed.
     */
    protected function findAlternativeRoutes(Collection $affectedEdges, int $closedNodeId, int $tenantId): array
    {
        $alternatives = [];

        foreach ($affectedEdges as $edge) {
            $isOriginClosed = $edge->origin_node_id === $closedNodeId;
            $nodeToReplace = $isOriginClosed ? $edge->originNode : $edge->destinationNode;
            $otherNode = $isOriginClosed ? $edge->destinationNode : $edge->originNode;

            // Find nodes of the same type with available capacity
            $alternativeNodes = Node::where('tenant_id', $tenantId)
                ->where('type', $nodeToReplace->type)
                ->where('id', '!=', $closedNodeId)
                ->where('capacity', '>', 0)
                ->limit(3)
                ->get();

            if ($alternativeNodes->isNotEmpty()) {
                $alternatives[] = [
                    'original_route' => "{$edge->originNode->name} → {$edge->destinationNode->name}",
                    'alternatives' => $alternativeNodes->map(fn ($node) => [
                        'node_name' => $node->name,
                        'capacity' => $node->capacity,
                    ])->toArray(),
                ];
            }
        }

        return $alternatives;
    }

    /**
     * Calculate congestion delay based on overload ratio.
     */
    protected function calculateCongestionDelay(float $congestionRatio): float
    {
        if ($congestionRatio <= 1.0) {
            return 0; // No delay if under capacity
        }

        // Exponential delay model: delay increases rapidly as congestion grows
        // At 150% capacity → ~2 days, at 200% → ~5 days
        return min(10, ($congestionRatio - 1) * 10);
    }

    /**
     * Calculate cost impact of a closure.
     */
    protected function calculateClosureCostImpact(float $volume, int $days, float $avgCostPerUnit): int
    {
        // Simple model: volume × days × cost per unit × penalty multiplier
        $penaltyMultiplier = 1.5; // Account for expedited shipping, storage, etc.

        return (int) round($volume * $days * $avgCostPerUnit * $penaltyMultiplier);
    }

    /**
     * Calculate cost impact of delays.
     */
    protected function calculateDelayedCostImpact(float $volume, float $delayDays, float $avgCostPerUnit): int
    {
        $holdingCostPerDay = 0.1; // $0.10 per unit per day of delay

        return (int) round($volume * $delayDays * $holdingCostPerDay);
    }

    /**
     * Calculate cost impact of capacity loss.
     */
    protected function calculateCapacityCostImpact(float $overload, float $delayDays, float $avgCostPerUnit): int
    {
        $expeditedCostMultiplier = 2.0;

        return (int) round($overload * $delayDays * $avgCostPerUnit * $expeditedCostMultiplier);
    }

    /**
     * Generate recommendations for port closure.
     */
    protected function generateClosureRecommendations(Node $node, array $alternatives, Collection $affectedEdges): array
    {
        $recommendations = [];

        if (! empty($alternatives)) {
            $recommendations[] = [
                'type' => 'Rerouting',
                'description' => "Reroute shipments to alternative {$node->type}s with available capacity",
                'priority' => 'high',
                'alternatives' => array_slice($alternatives, 0, 3),
            ];
        }

        if ($affectedEdges->count() > 5) {
            $recommendations[] = [
                'type' => 'Expedited Shipping'

,
                'description' => 'Consider air freight for critical shipments to minimize delays',
                'priority' => 'medium',
                'estimated_impact' => 'Additional cost of $' . number_format($affectedEdges->sum('volume') * 50),
            ];
        }

        $recommendations[] = [
            'type' => 'Communication',
            'description' => 'Proactively notify customers of potential delays',
            'priority' => 'high',
            'flows_affected' => $affectedEdges->count(),
        ];

        return $recommendations;
    }

    /**
     * Generate recommendations for lead time inflation.
     */
    protected function generateInflationRecommendations(int $inflationPercentage, ?string $region): array
    {
        $recommendations = [];

        $recommendations[] = [
            'type' => 'Safety Stock',
            'description' => 'Increase safety stock levels to buffer against extended lead times',
            'priority' => $inflationPercentage > 50 ? 'high' : 'medium',
            'estimated_impact' => "Protect against {$inflationPercentage}% longer lead times",
        ];

        if ($inflationPercentage > 30) {
            $recommendations[] = [
                'type' => 'Alternative Suppliers',
                'description' => 'Explore suppliers in unaffected regions to diversify risk',
                'priority' => 'medium',
                'region_affected' => $region ?? 'Multiple regions',
            ];
        }

        $recommendations[] = [
            'type' => 'Production Planning',
            'description' => 'Adjust production schedules to account for longer lead times',
            'priority' => 'medium',
        ];

        return $recommendations;
    }

    /**
     * Generate recommendations for capacity loss.
     */
    protected function generateCapacityRecommendations(Node $node, int $reductionPercentage, float $overload, int $tenantId): array
    {
        $recommendations = [];

        if ($overload > 0) {
            $recommendations[] = [
                'type' => 'Capacity Redistribution',
                'description' => "Redistribute {$overload} units of volume to alternative facilities",
                'priority' => 'high',
                'volume_to_reroute' => round($overload),
            ];
        }

        if ($reductionPercentage > 30) {
            // Find similar nodes
            $alternativeNodes = Node::where('tenant_id', $tenantId)
                ->where('type', $node->type)
                ->where('id', '!=', $node->id)
                ->where('capacity', '>', 0)
                ->limit(3)
                ->get();

            if ($alternativeNodes->isNotEmpty()) {
                $recommendations[] = [
                    'type' => 'Temporary Capacity Expansion',
                    'description' => 'Expand capacity at alternative facilities to compensate',
                    'priority' => 'high',
                    'alternatives' => $alternativeNodes->pluck('name')->toArray(),
                ];
            }
        }

        $recommendations[] = [
            'type' => 'Workforce Planning',
            'description' => 'Adjust staffing and shifts to maximize remaining capacity',
            'priority' => 'medium',
        ];

        return $recommendations;
    }
}
