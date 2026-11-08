<?php

namespace App\Services;

use App\Models\LlmUsage;
use Illuminate\Support\Facades\Log;
use OpenAI;
use OpenAI\Client;

class LlmService
{
    protected Client $client;

    protected string $cheapModel;

    protected string $expensiveModel;

    protected int $maxTokens;

    protected float $temperature;

    public function __construct()
    {
        $this->client = OpenAI::client(config('llm.openai.api_key'));
        $this->cheapModel = config('llm.openai.cheap_model');
        $this->expensiveModel = config('llm.openai.expensive_model');
        $this->maxTokens = config('llm.openai.max_tokens');
        $this->temperature = config('llm.openai.temperature');
    }

    /**
     * Generate a chat completion using the specified model.
     *
     * @param  array  $messages  Array of message objects with 'role' and 'content'
     * @param  bool  $useExpensiveModel  Whether to use the expensive model
     * @param  int|null  $tenantId  Tenant ID for usage tracking
     * @param  int|null  $userId  User ID for usage tracking
     * @return array{content: string, usage: array, model: string}
     *
     * @throws \Exception
     */
    public function chat(
        array $messages,
        bool $useExpensiveModel = false,
        ?int $tenantId = null,
        ?int $userId = null
    ): array {
        $model = $useExpensiveModel ? $this->expensiveModel : $this->cheapModel;

        try {
            $response = $this->client->chat()->create([
                'model' => $model,
                'messages' => $messages,
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
                'response_format' => ['type' => 'json_object'],
            ]);

            $content = $response->choices[0]->message->content;
            $usage = [
                'prompt_tokens' => $response->usage->promptTokens,
                'completion_tokens' => $response->usage->completionTokens,
                'total_tokens' => $response->usage->totalTokens,
            ];

            // Log usage to database
            $this->logUsage($model, $usage, $tenantId, $userId);

            // Calculate estimated cost
            $cost = $this->estimateCost($model, $usage);
            Log::info("LLM API call completed", [
                'model' => $model,
                'tokens' => $usage['total_tokens'],
                'estimated_cost' => $cost,
            ]);

            return [
                'content' => $content,
                'usage' => $usage,
                'model' => $model,
            ];
        } catch (\Exception $e) {
            Log::error('LLM API call failed', [
                'error' => $e->getMessage(),
                'model' => $model,
            ]);

            throw $e;
        }
    }

    /**
     * Determine if text should use the expensive model based on complexity.
     */
    public function shouldUseExpensiveModel(string $text): bool
    {
        if (! config('llm.conditional_compute.enabled')) {
            return false;
        }

        $threshold = config('llm.conditional_compute.text_length_threshold');
        $keywords = config('llm.conditional_compute.complexity_keywords');

        // Check text length
        if (strlen($text) > $threshold) {
            return true;
        }

        // Check for complexity keywords
        $textLower = strtolower($text);
        foreach ($keywords as $keyword) {
            if (str_contains($textLower, $keyword)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Log LLM usage to the database.
     */
    protected function logUsage(string $model, array $usage, ?int $tenantId, ?int $userId): void
    {
        try {
            LlmUsage::create([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'model_name' => $model,
                'input_tokens' => $usage['prompt_tokens'],
                'output_tokens' => $usage['completion_tokens'],
                'total_tokens' => $usage['total_tokens'],
                'estimated_cost' => $this->estimateCost($model, $usage),
                'endpoint' => 'chat.completions',
            ]);
        } catch (\Exception $e) {
            Log::warning('Failed to log LLM usage', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Estimate the cost of an API call based on model and token usage.
     * Pricing as of Nov 2024 (approximate):
     * - gpt-4o-mini: $0.150/1M input, $0.600/1M output
     * - gpt-4o: $2.50/1M input, $10.00/1M output.
     */
    protected function estimateCost(string $model, array $usage): float
    {
        $pricing = match ($model) {
            'gpt-4o-mini' => [
                'input' => 0.150 / 1_000_000,
                'output' => 0.600 / 1_000_000,
            ],
            'gpt-4o' => [
                'input' => 2.50 / 1_000_000,
                'output' => 10.00 / 1_000_000,
            ],
            default => [
                'input' => 0,
                'output' => 0,
            ],
        };

        return ($usage['prompt_tokens'] * $pricing['input'])
            + ($usage['completion_tokens'] * $pricing['output']);
    }
}
