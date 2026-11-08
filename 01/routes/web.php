<?php

use App\Http\Controllers\DataUploadController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventParsingController;
use App\Http\Controllers\ScenarioController;
use App\Http\Controllers\ScenarioExecutionController;
use App\Http\Controllers\UploadPageController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Laravel\Fortify\Features;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'canRegister' => Features::enabled(Features::registration()),
    ]);
})->name('home');

Route::middleware(['auth', 'verified', 'tenant'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::get('uploads', UploadPageController::class)->name('uploads.index');

    Route::post('uploads/nodes', [DataUploadController::class, 'uploadNodes'])->name('uploads.nodes');
    Route::post('uploads/edges', [DataUploadController::class, 'uploadEdges'])->name('uploads.edges');

    Route::get('ingestion', function () {
        return Inertia::render('ingestion/index');
    })->name('ingestion.index');

    Route::post('ingestion/pasted-text', [EventParsingController::class, 'parsePastedText'])->name('ingestion.pasted');
    Route::post('ingestion/plain-text-upload', [EventParsingController::class, 'parsePlainTextUpload'])->name('ingestion.text-upload');
    Route::post('ingestion/pdf-upload', [EventParsingController::class, 'parsePdfUpload'])->name('ingestion.pdf-upload');

    Route::get('events', [EventController::class, 'index'])->name('events.index');
    Route::get('events/{event}', [EventController::class, 'show'])->name('events.show');

    Route::get('scenarios', [ScenarioController::class, 'index'])->name('scenarios.index');
    Route::post('scenarios', [ScenarioExecutionController::class, 'store'])->name('scenarios.store');
    Route::get('scenarios/{scenario}', [ScenarioController::class, 'show'])->name('scenarios.show');
    Route::post('scenarios/{scenario}/execute', [ScenarioExecutionController::class, 'execute'])->name('scenarios.execute');
});

require __DIR__.'/settings.php';
