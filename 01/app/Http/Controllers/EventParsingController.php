<?php

namespace App\Http\Controllers;

use App\Services\EventParserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class EventParsingController extends Controller
{
    public function __construct(protected EventParserService $eventParserService)
    {
    }

    /**
     * Parse pasted text and extract events.
     */
    public function parsePastedText(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'text' => 'required|string|min:10|max:50000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $result = $this->eventParserService->parseText(
                text: $request->input('text'),
                tenantId: $request->user()->tenant_id,
                userId: $request->user()->id,
                sourceType: 'pasted_text'
            );

            $eventCount = count($result['events']);

            return response()->json([
                'success' => true,
                'message' => "Successfully extracted {$eventCount} event" . ($eventCount !== 1 ? 's' : ''),
                'events' => $result['events']->map(fn ($event) => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'summary' => $event->summary,
                    'location' => $event->location,
                    'severity' => $event->severity,
                    'confidence' => $event->confidence,
                    'start_date' => $event->start_date?->toDateString(),
                ]),
                'metadata' => [
                    'model_used' => $result['llm_response']['model'],
                    'tokens_used' => $result['llm_response']['usage']['total_tokens'],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to parse pasted text', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to parse text',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during parsing',
            ], 500);
        }
    }

    /**
     * Parse plain text file upload and extract events.
     */
    public function parsePlainTextUpload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:txt|max:10240', // 10MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            $text = file_get_contents($request->file('file')->getRealPath());

            if (strlen($text) > 50000) {
                return response()->json([
                    'success' => false,
                    'message' => 'File too large',
                    'error' => 'Text files must be under 50,000 characters',
                ], 422);
            }

            $result = $this->eventParserService->parseText(
                text: $text,
                tenantId: $request->user()->tenant_id,
                userId: $request->user()->id,
                sourceType: 'plain_text_upload'
            );

            $eventCount = count($result['events']);

            return response()->json([
                'success' => true,
                'message' => "Successfully extracted {$eventCount} event" . ($eventCount !== 1 ? 's' : ''),
                'events' => $result['events']->map(fn ($event) => [
                    'id' => $event->id,
                    'event_type' => $event->event_type,
                    'summary' => $event->summary,
                    'location' => $event->location,
                    'severity' => $event->severity,
                    'confidence' => $event->confidence,
                    'start_date' => $event->start_date?->toDateString(),
                ]),
                'metadata' => [
                    'model_used' => $result['llm_response']['model'],
                    'tokens_used' => $result['llm_response']['usage']['total_tokens'],
                    'filename' => $request->file('file')->getClientOriginalName(),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to parse text file', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to parse file',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during parsing',
            ], 500);
        }
    }

    /**
     * Parse PDF file upload and extract events.
     * Note: Requires a PDF text extraction library (could use Smalot\PdfParser).
     */
    public function parsePdfUpload(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:pdf|max:20480', // 20MB max
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // TODO: Implement PDF text extraction
            // For now, return a placeholder message
            return response()->json([
                'success' => false,
                'message' => 'PDF parsing not yet implemented',
                'error' => 'PDF text extraction requires additional library installation. Use text files for now.',
            ], 501);

            // Future implementation would look like:
            // $pdfParser = new \Smalot\PdfParser\Parser();
            // $pdf = $pdfParser->parseFile($request->file('file')->getRealPath());
            // $text = $pdf->getText();
            //
            // Then call $this->eventParserService->parseText($text, ...)
        } catch (\Exception $e) {
            Log::error('Failed to parse PDF', [
                'error' => $e->getMessage(),
                'user_id' => $request->user()->id,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to parse PDF',
                'error' => config('app.debug') ? $e->getMessage() : 'An error occurred during parsing',
            ], 500);
        }
    }
}
