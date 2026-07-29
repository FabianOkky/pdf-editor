<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentFileController;
use App\Livewire\Documents\Editor as DocumentsEditor;
use App\Livewire\Documents\Index as DocumentsIndex;
use App\Livewire\Documents\Organize as DocumentsOrganize;
use App\Livewire\Documents\Show as DocumentsShow;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check() ? redirect()->route('dashboard') : view('welcome');
})->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::livewire('documents', DocumentsIndex::class)->name('documents.index');
    Route::livewire('documents/{document}', DocumentsShow::class)->name('documents.show');
    Route::livewire('documents/{document}/organize', DocumentsOrganize::class)->name('documents.organize');
    Route::livewire('documents/{document}/edit', DocumentsEditor::class)->name('documents.editor');

    // Owner-scoped file streaming off the private disk (originals are served as-is).
    Route::get('documents/{document}/file', [DocumentFileController::class, 'show'])
        ->middleware('can:view,document')
        ->name('documents.file');
    Route::get('documents/{document}/download', [DocumentFileController::class, 'download'])
        ->middleware('can:download,document')
        ->name('documents.download');
    Route::get('documents/{document}/thumbnail', [DocumentFileController::class, 'thumbnail'])
        ->middleware('can:view,document')
        ->name('documents.thumbnail');

    // Derived versions (page operations). Scope bindings tie the version to its document.
    Route::get('documents/{document}/versions/{version}/file', [DocumentFileController::class, 'versionShow'])
        ->scopeBindings()
        ->middleware('can:view,document')
        ->name('documents.versions.file');
    Route::get('documents/{document}/versions/{version}/download', [DocumentFileController::class, 'versionDownload'])
        ->scopeBindings()
        ->middleware('can:download,document')
        ->name('documents.versions.download');

    // Word exports (async). Scope bindings tie the export job to its document.
    Route::get('documents/{document}/exports/{exportJob}/download', [DocumentFileController::class, 'exportDownload'])
        ->scopeBindings()
        ->middleware('can:view,document')
        ->name('documents.exports.download');
});

require __DIR__.'/settings.php';
