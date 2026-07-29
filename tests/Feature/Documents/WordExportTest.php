<?php

use App\Enums\ExportFormat;
use App\Enums\ExportJobStatus;
use App\Jobs\ExportDocumentJob;
use App\Livewire\Documents\Show;
use App\Models\Document;
use App\Models\ExportJob;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('pdfs');
});

it('queues a word export from the viewer', function () {
    Queue::fake();
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['path' => 'documents/d.pdf']);
    Storage::disk('pdfs')->put('documents/d.pdf', '%PDF');

    Livewire::actingAs($user)
        ->test(Show::class, ['document' => $document])
        ->call('exportToWord')
        ->assertHasNoErrors();

    $job = ExportJob::query()->where('document_id', $document->id)->first();

    expect($job)->not->toBeNull()
        ->and($job->status)->toBe(ExportJobStatus::Queued)
        ->and($job->format)->toBe(ExportFormat::Docx)
        ->and($job->created_by)->toBe($user->id);

    Queue::assertPushed(ExportDocumentJob::class, fn ($pushed) => $pushed->exportJob->is($job));
});

it('does not queue a second export while one is already running', function () {
    Queue::fake();
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['path' => 'documents/d.pdf']);
    Storage::disk('pdfs')->put('documents/d.pdf', '%PDF');
    ExportJob::factory()->for($document)->create(['status' => ExportJobStatus::Processing]);

    Livewire::actingAs($user)
        ->test(Show::class, ['document' => $document])
        ->call('exportToWord');

    expect(ExportJob::where('document_id', $document->id)->count())->toBe(1);
    Queue::assertNothingPushed();
});

it('runs the export pipeline and stores the docx without touching the original', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create([
        'title' => 'Quarterly Report',
        'path' => 'documents/d.pdf',
    ]);
    Storage::disk('pdfs')->put('documents/d.pdf', '%PDF-original');
    fakePdfExport(sourceType: 'native', ocrApplied: false, pageCount: 2, docxBytes: 'PK real-docx');

    $job = ExportJob::factory()->for($document)->create(['created_by' => $user->id]);

    ExportDocumentJob::dispatchSync($job);
    $job->refresh();

    expect($job->status)->toBe(ExportJobStatus::Completed)
        ->and($job->engine)->toBe('pdf2docx')
        ->and($job->result_filename)->toBe('Quarterly Report.docx')
        ->and($job->result_size_bytes)->toBe(strlen('PK real-docx'))
        ->and($job->meta)->toMatchArray([
            'source_type' => 'native',
            'ocr_applied' => false,
            'page_count' => 2,
        ]);

    Storage::disk('pdfs')->assertExists($job->result_path);
    expect(Storage::disk('pdfs')->get($job->result_path))->toBe('PK real-docx');

    // Golden Rule: the original document file is byte-for-byte untouched.
    expect(Storage::disk('pdfs')->get('documents/d.pdf'))->toBe('%PDF-original');
});

it('records the ocr engine when the source was scanned', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->scanned()->create(['path' => 'documents/s.pdf']);
    Storage::disk('pdfs')->put('documents/s.pdf', '%PDF-scan');
    fakePdfExport(sourceType: 'scanned', ocrApplied: true, pageCount: 1);

    $job = ExportJob::factory()->for($document)->create();
    ExportDocumentJob::dispatchSync($job);
    $job->refresh();

    expect($job->status)->toBe(ExportJobStatus::Completed)
        ->and($job->engine)->toBe('ocr+python-docx')
        ->and($job->meta['ocr_applied'])->toBeTrue();
});

it('marks the export failed when the service errors', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create(['path' => 'documents/d.pdf']);
    Storage::disk('pdfs')->put('documents/d.pdf', '%PDF');
    Http::fake(['*/pdf/export/docx' => Http::response(null, 500)]);

    $job = ExportJob::factory()->for($document)->create();
    ExportDocumentJob::dispatchSync($job);
    $job->refresh();

    expect($job->status)->toBe(ExportJobStatus::Failed)
        ->and($job->error)->not->toBeNull()
        ->and($job->result_path)->toBeNull();
});

it('lets the owner download a completed export', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();
    $job = ExportJob::factory()->for($document)->completed()->create();
    Storage::disk('pdfs')->put($job->result_path, 'PK docx-bytes');

    $this->actingAs($user)
        ->get(route('documents.exports.download', [$document, $job]))
        ->assertOk()
        ->assertDownload($job->result_filename);
});

it('forbids downloading another user’s export', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $document = Document::factory()->for($owner)->create();
    $job = ExportJob::factory()->for($document)->completed()->create();
    Storage::disk('pdfs')->put($job->result_path, 'PK docx');

    $this->actingAs($other)
        ->get(route('documents.exports.download', [$document, $job]))
        ->assertForbidden();
});

it('404s a download for an unfinished export', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();
    $job = ExportJob::factory()->for($document)->create(); // queued, no result file

    $this->actingAs($user)
        ->get(route('documents.exports.download', [$document, $job]))
        ->assertNotFound();
});

it('404s when the export does not belong to the document in the url', function () {
    $user = User::factory()->create();
    $documentA = Document::factory()->for($user)->create();
    $documentB = Document::factory()->for($user)->create();
    $job = ExportJob::factory()->for($documentB)->completed()->create();
    Storage::disk('pdfs')->put($job->result_path, 'PK');

    $this->actingAs($user)
        ->get(route('documents.exports.download', [$documentA, $job]))
        ->assertNotFound();
});

it('shows a ready state in the viewer when an export is complete', function () {
    $user = User::factory()->create();
    $document = Document::factory()->for($user)->create();
    ExportJob::factory()->for($document)->completed()->create();

    Livewire::actingAs($user)
        ->test(Show::class, ['document' => $document])
        ->assertSee(__('Download .docx'));
});
