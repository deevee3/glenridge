<?php

namespace App\Http\Controllers;

use App\Models\Scenario;
use App\Services\ScenarioSimulationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class ScenarioExecutionController extends Controller
{
    public function __construct(protected ScenarioSimulationService $simulationService)
    {
    }

    /**
     * Store a new scenario.
     */
    public function store(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'scenario_type' => 'required|in:port_closure,lead_time_inflation,labor_capacity_loss',
            'event_id' => 'nullable|exists:events,id',
            'parameters' => 'required|array',
        ]);

        if ($validator->fails()) {
            return redirect()
                ->back()
                ->withErrors($validator)
                ->withInput();
        }

        try {
            $scenario = Scenario::create([
                'tenant_id' => $request->user()->tenant_id,
                'event_id' => $request->input('event_id'),
                'name' => $request->input('name'),
                'scenario_type' => $request->input('scenario_type'),
                'parameters' => $request->input('parameters'),
                'created_by' => $request->user()->id,
            ]);

            return redirect()
                ->route('scenarios.show', $scenario)
                ->with('scenarioSummary', [
                    'message' => 'Scenario created successfully',
                ]);
        } catch (\Exception $e) {
            Log::error('Failed to create scenario', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
            ]);

            return redirect()
                ->back()
                ->withErrors(['error' => 'Failed to create scenario'])
                ->withInput();
        }
    }

    /**
     * Execute a scenario and generate results.
     */
    public function execute(Request $request, Scenario $scenario): RedirectResponse
    {
        // Ensure tenant access
        if ($scenario->tenant_id !== $request->user()->tenant_id) {
            abort(403, 'Unauthorized access to scenario');
        }

        try {
            $result = $this->simulationService->executeScenario($scenario);

            return redirect()
                ->route('scenarios.show', $scenario)
                ->with('scenarioSummary', [
                    'message' => 'Scenario executed successfully',
                    'flows_impacted' => $result->metrics['flows_impacted'] ?? 0,
                    'result_id' => $result->id,
                ]);
        } catch(\Exception $e) {
            Log::error('Failed to execute scenario', [
                'scenario_id' => $scenario->id,
                'error' => $e->getMessage(),
            ]);

            return redirect()
                ->route('scenarios.show', $scenario)
                ->withErrors(['error' => 'Failed to execute scenario: ' . $e->getMessage()]);
        }
    }
}
