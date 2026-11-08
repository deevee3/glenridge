<?php

namespace App\Services;

use App\Models\Event;
use App\Models\Node;
use Illuminate\Support\Facades\Log;

class EventParserService
{
    public function __construct(protected LlmService $llmService)
    {
    }

    /**
     * Parse text and extract disruption events.
     *
     * @param  string  $text  The text to parse
     * @param  int  $tenantId  Tenant ID for scoping and usage tracking
     * @param  int  $userId  User ID for event creation and usage tracking
     * @param  string  $sourceType  Type of source (email, pdf, pasted_text, etc.)
     * @return array{events: array, raw_payload: array, llm_response: array}
     *
     * @throws \Exception
     */
    public function parseText(
        string $text,
        int $tenantId,
        int $userId,
        string $sourceType = 'pasted_text'
    ): array {
        // Determine which model to use
        $useExpensiveModel = $this->llmService->shouldUseExpensiveModel($text);

        // Build the prompt
        $systemPrompt = $this->getSystemPrompt();
        $userPrompt = $this->getUserPrompt($text, $tenantId);

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        // Call LLM
        $llmResponse = $this->llmService->chat($messages, $useExpensiveModel, $tenantId, $userId);

        // Parse LLM response
        $parsedData = json_decode($llmResponse['content'], true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception('Failed to parse LLM response as JSON: '.json_last_error_msg());
        }

        // Validate and process events
        $events = $this->processExtractedEvents(
            $parsedData['events'] ?? [],
            $tenantId,
            $userId,
            $sourceType,
            $text
        );

        return [
            'events' => $events,
            'raw_payload' => [
                'original_text' => $text,
                'source' => $sourceType,
                'llm_model' => $llmResponse['model'],
                'tokens_used' => $llmResponse['usage']['total_tokens'],
            ],
            'llm_response' => $llmResponse,
        ];
    }

    /**
     * Get the system prompt for event extraction.
     */
    protected function getSystemPrompt(): string
    {
        return <<<'PROMPT'
You are an expert supply chain analyst specializing in identifying disruption events from unstructured text.

Your task is to extract structured disruption events from the provided text. A disruption event is anything that impacts the flow of goods through a supply chain network (e.g., port closures, strikes, weather delays, congestion, equipment failures).

For each event you identify, extract:
- event_type: One of: port_closure, port_congestion, strike, weather_delay, customs_delay, equipment_failure, capacity_shortage, supply_disruption, transportation_delay, regulatory_change
- summary: A concise 1-2 sentence summary of the event
- location: The primary location affected (port name, city, facility name)
- severity: low, medium, or high (based on impact magnitude)
- start_date: ISO 8601 date if mentioned (YYYY-MM-DD), otherwise null
- end_date: ISO 8601 date if mentioned (YYYY-MM-DD), otherwise null
- confidence: A float between 0 and 1 indicating your confidence in this extraction

Respond ONLY with valid JSON in this exact format:
{
  "events": [
    {
      "event_type": "port_closure",
      "summary": "Port of Los Angeles temporarily closed due to equipment failure",
      "location": "Port of Los Angeles",
      "severity": "high",
      "start_date": "2024-11-20",
      "end_date": "2024-11-25",
      "confidence": 0.95
    }
  ]
}

If no events are found, return: {"events": []}
PROMPT;
    }

    /**
     * Get the user prompt with context about known locations.
     */
    protected function getUserPrompt(string $text, int $tenantId): string
    {
        // Get known node locations for fuzzy matching
        $knownLocations = Node::where('tenant_id', $tenantId)
            ->pluck('name')
            ->take(20)
            ->implode(', ');

        $contextNote = $knownLocations
            ? "\n\nKnown locations in our network: {$knownLocations}\nIf the text mentions locations similar to these, use the exact name from our network."
            : '';

        return <<<PROMPT
Extract all supply chain disruption events from the following text:{$contextNote}

TEXT:
{$text}

Remember to respond ONLY with valid JSON.
PROMPT;
    }

    /**
     * Process and validate extracted events.
     */
    protected function processExtractedEvents(
        array $extractedEvents,
        int $tenantId,
        int $userId,
        string $sourceType,
        string $originalText
    ): array {
        $minConfidence = config('llm.extraction.min_confidence_threshold');
        $maxEvents = config('llm.extraction.max_events_per_text');

        $processedEvents = [];

        foreach (array_slice($extractedEvents, 0, $maxEvents) as $eventData) {
            // Skip low-confidence events
            if (($eventData['confidence'] ?? 0) < $minConfidence) {
                Log::info('Skipping low-confidence event', [
                    'confidence' => $eventData['confidence'],
                    'summary' => $eventData['summary'] ?? 'N/A',
                ]);

                continue;
            }

            // Attempt fuzzy location matching
            $location = $this->fuzzyMatchLocation($eventData['location'] ?? null, $tenantId);

            // Create the event in database
            $event = Event::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'event_type' => $eventData['event_type'] ?? 'supply_disruption',
                'summary' => $eventData['summary'] ?? 'Unspecified disruption event',
                'location' => $location,
                'severity' => $eventData['severity'] ?? null,
                'start_date' => $eventData['start_date'] ?? null,
                'confidence' => $eventData['confidence'] ?? 0.5,
                'source_type' => $sourceType,
                'raw_payload' => [
                    'original_text' => $originalText,
                    'source' => $sourceType,
                    'extracted_at' => now()->toIso8601String(),
                ],
                'parsed_payload' => $eventData,
            ]);

            $processedEvents[] = $event;
        }

        return $processedEvents;
    }

    /**
     * Fuzzy match a location string to known network nodes.
     */
    protected function fuzzyMatchLocation(?string $extractedLocation, int $tenantId): ?string
    {
        if (! $extractedLocation) {
            return null;
        }

        $nodes = Node::where('tenant_id', $tenantId)
            ->get(['name', 'location']);

        $threshold = config('llm.extraction.fuzzy_match_threshold');
        $bestMatch = null;
        $bestSimilarity = 0;

        foreach ($nodes as $node) {
            // Try matching against both node name and location
            foreach ([$node->name, $node->location] as $candidate) {
                similar_text(
                    strtolower($extractedLocation),
                    strtolower($candidate),
                    $similarity
                );

                if ($similarity > $bestSimilarity && $similarity >= ($threshold * 100)) {
                    $bestSimilarity = $similarity;
                    $bestMatch = $node->name;
                }
            }
        }

        return $bestMatch ?? $extractedLocation;
    }
}
