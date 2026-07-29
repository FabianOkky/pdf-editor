<?php

use App\Services\PdfServiceClient;
use Illuminate\Support\Facades\Http;

it('reports the service as healthy and sends the shared secret', function () {
    config()->set('services.pdf', [
        'url' => 'http://pdf-service.test',
        'secret' => 'top-secret',
        'timeout' => 5,
    ]);

    Http::fake([
        'pdf-service.test/health' => Http::response([
            'status' => 'ok',
            'service' => 'pdf-service',
            'version' => '0.1.0',
        ]),
    ]);

    $client = app(PdfServiceClient::class);

    expect($client->health())->toMatchArray(['status' => 'ok'])
        ->and($client->isHealthy())->toBeTrue();

    Http::assertSent(fn ($request) => $request->url() === 'http://pdf-service.test/health'
        && $request->hasHeader('X-Pdf-Secret', 'top-secret'));
});

it('returns false from isHealthy when the service errors', function () {
    config()->set('services.pdf', [
        'url' => 'http://pdf-service.test',
        'secret' => 'x',
        'timeout' => 5,
    ]);

    Http::fake(['pdf-service.test/*' => Http::response(null, 500)]);

    expect(app(PdfServiceClient::class)->isHealthy())->toBeFalse();
});

it('fetches PDF info and uploads the file with the shared secret', function () {
    config()->set('services.pdf', [
        'url' => 'http://pdf-service.test',
        'secret' => 'top-secret',
        'timeout' => 5,
    ]);

    Http::fake([
        'pdf-service.test/pdf/info' => Http::response([
            'page_count' => 3,
            'pages' => [
                ['width' => 612.0, 'height' => 792.0],
                ['width' => 612.0, 'height' => 792.0],
                ['width' => 612.0, 'height' => 792.0],
            ],
            'source_type' => 'native',
        ]),
    ]);

    $info = app(PdfServiceClient::class)->info('%PDF-1.7 fake bytes', 'report.pdf');

    expect($info['page_count'])->toBe(3)
        ->and($info['source_type'])->toBe('native')
        ->and($info['pages'])->toHaveCount(3);

    Http::assertSent(fn ($request) => $request->url() === 'http://pdf-service.test/pdf/info'
        && $request->hasHeader('X-Pdf-Secret', 'top-secret')
        && $request->isMultipart());
});

it('returns the thumbnails array from the service', function () {
    config()->set('services.pdf', [
        'url' => 'http://pdf-service.test',
        'secret' => 'top-secret',
        'timeout' => 5,
    ]);

    Http::fake([
        'pdf-service.test/pdf/thumbnails' => Http::response([
            'thumbnails' => [
                ['page' => 1, 'width' => 120, 'height' => 160, 'format' => 'png', 'image_base64' => 'AAAA'],
            ],
        ]),
    ]);

    $thumbnails = app(PdfServiceClient::class)->thumbnails('%PDF-1.7 fake bytes', [1], 96, 'report.pdf');

    expect($thumbnails)->toHaveCount(1)
        ->and($thumbnails[0]['page'])->toBe(1)
        ->and($thumbnails[0]['image_base64'])->toBe('AAAA');

    Http::assertSent(fn ($request) => $request->url() === 'http://pdf-service.test/pdf/thumbnails'
        && $request->hasHeader('X-Pdf-Secret', 'top-secret'));
});

it('runs an organize page operation and returns the produced output', function () {
    config()->set('services.pdf', [
        'url' => 'http://pdf-service.test',
        'secret' => 'top-secret',
        'timeout' => 5,
    ]);

    Http::fake([
        'pdf-service.test/pdf/pages' => Http::response([
            'outputs' => [
                ['page_count' => 2, 'content_base64' => 'QkFTRTY0'],
            ],
        ]),
    ]);

    $outputs = app(PdfServiceClient::class)->pages(
        [['contents' => '%PDF-1.7 fake', 'filename' => 'report.pdf']],
        ['op' => 'organize', 'pages' => [['source' => 2, 'rotate' => 90], ['source' => 1, 'rotate' => 0]]],
    );

    expect($outputs)->toHaveCount(1)
        ->and($outputs[0]['page_count'])->toBe(2)
        ->and($outputs[0]['content_base64'])->toBe('QkFTRTY0');

    Http::assertSent(function ($request) {
        return $request->url() === 'http://pdf-service.test/pdf/pages'
            && $request->hasHeader('X-Pdf-Secret', 'top-secret')
            && $request->isMultipart()
            && collect($request->data())->contains(
                fn ($part) => $part['name'] === 'spec'
                    && str_contains($part['contents'], '"op":"organize"')
            );
    });
});

it('sends every file when merging documents', function () {
    config()->set('services.pdf', [
        'url' => 'http://pdf-service.test',
        'secret' => 'top-secret',
        'timeout' => 5,
    ]);

    Http::fake([
        'pdf-service.test/pdf/pages' => Http::response([
            'outputs' => [
                ['page_count' => 5, 'content_base64' => 'TUVSR0VE'],
            ],
        ]),
    ]);

    $outputs = app(PdfServiceClient::class)->pages(
        [
            ['contents' => '%PDF-1.7 a', 'filename' => 'a.pdf'],
            ['contents' => '%PDF-1.7 b', 'filename' => 'b.pdf'],
        ],
        ['op' => 'merge'],
    );

    expect($outputs[0]['page_count'])->toBe(5);

    Http::assertSent(function ($request) {
        $fileParts = collect($request->data())->where('name', 'files');

        return $request->url() === 'http://pdf-service.test/pdf/pages'
            && $fileParts->count() === 2;
    });
});

it('bakes overlays and uploads the file with the shared secret', function () {
    config()->set('services.pdf', [
        'url' => 'http://pdf-service.test',
        'secret' => 'top-secret',
        'timeout' => 5,
    ]);

    Http::fake([
        'pdf-service.test/pdf/bake' => Http::response([
            'page_count' => 2,
            'content_base64' => 'QkFLRUQ=',
        ]),
    ]);

    $result = app(PdfServiceClient::class)->bake(
        '%PDF-1.7 fake bytes',
        [['type' => 'whiteout', 'page_number' => 1, 'z_index' => 0, 'order' => 0, 'payload' => ['x' => 1, 'y' => 1, 'width' => 1, 'height' => 1]]],
        'report.pdf',
    );

    expect($result['page_count'])->toBe(2)
        ->and($result['content_base64'])->toBe('QkFLRUQ=');

    Http::assertSent(function ($request) {
        return $request->url() === 'http://pdf-service.test/pdf/bake'
            && $request->hasHeader('X-Pdf-Secret', 'top-secret')
            && $request->isMultipart()
            && collect($request->data())->contains(
                fn ($part) => $part['name'] === 'overlays'
                    && str_contains($part['contents'], '"type":"whiteout"')
            );
    });
});

it('detects form fields and uploads the file with the shared secret', function () {
    config()->set('services.pdf', [
        'url' => 'http://pdf-service.test',
        'secret' => 'top-secret',
        'timeout' => 5,
    ]);

    Http::fake([
        'pdf-service.test/pdf/form-fields' => Http::response([
            'is_form' => true,
            'fields' => [
                ['name' => 'full_name', 'type' => 'text', 'value' => '', 'page_number' => 1, 'x' => 100, 'y' => 672, 'width' => 200, 'height' => 20, 'options' => [], 'readonly' => false, 'required' => false],
            ],
        ]),
    ]);

    $result = app(PdfServiceClient::class)->formFields('%PDF-1.7 fake bytes', 'form.pdf');

    expect($result['is_form'])->toBeTrue()
        ->and($result['fields'])->toHaveCount(1)
        ->and($result['fields'][0]['name'])->toBe('full_name');

    Http::assertSent(fn ($request) => $request->url() === 'http://pdf-service.test/pdf/form-fields'
        && $request->hasHeader('X-Pdf-Secret', 'top-secret')
        && $request->isMultipart());
});

it('fills form fields and flattens with the shared secret', function () {
    config()->set('services.pdf', [
        'url' => 'http://pdf-service.test',
        'secret' => 'top-secret',
        'timeout' => 5,
    ]);

    Http::fake([
        'pdf-service.test/pdf/form-fields/fill' => Http::response([
            'page_count' => 1,
            'content_base64' => 'RklMTEVE',
        ]),
    ]);

    $result = app(PdfServiceClient::class)->fillFormFields(
        '%PDF-1.7 fake bytes',
        ['full_name' => 'Jane', 'agree' => true],
        true,
        'form.pdf',
    );

    expect($result['page_count'])->toBe(1)
        ->and($result['content_base64'])->toBe('RklMTEVE');

    Http::assertSent(function ($request) {
        if ($request->url() !== 'http://pdf-service.test/pdf/form-fields/fill') {
            return false;
        }

        $data = collect($request->data());
        $values = $data->firstWhere('name', 'values');
        $flatten = $data->firstWhere('name', 'flatten');

        return is_array($values)
            && str_contains($values['contents'], '"full_name":"Jane"')
            && is_array($flatten)
            && $flatten['contents'] === 'true';
    });
});

it('runs OCR and uploads the file with the shared secret', function () {
    config()->set('services.pdf', [
        'url' => 'http://pdf-service.test',
        'secret' => 'top-secret',
        'timeout' => 5,
        'export_timeout' => 120,
    ]);

    Http::fake([
        'pdf-service.test/pdf/ocr' => Http::response([
            'page_count' => 1,
            'content_base64' => base64_encode('%PDF-searchable'),
            'text' => 'Invoice 4815',
            'language' => 'eng',
        ]),
    ]);

    $result = app(PdfServiceClient::class)->ocr('%PDF-1.7 scanned', 'eng', 'scan.pdf');

    expect($result['page_count'])->toBe(1)
        ->and($result['text'])->toBe('Invoice 4815')
        ->and($result['language'])->toBe('eng');

    Http::assertSent(function ($request) {
        return $request->url() === 'http://pdf-service.test/pdf/ocr'
            && $request->hasHeader('X-Pdf-Secret', 'top-secret')
            && $request->isMultipart()
            && collect($request->data())->contains(
                fn ($part) => $part['name'] === 'language' && $part['contents'] === 'eng'
            );
    });
});

it('exports a docx and reports the pipeline that ran', function () {
    config()->set('services.pdf', [
        'url' => 'http://pdf-service.test',
        'secret' => 'top-secret',
        'timeout' => 5,
        'export_timeout' => 120,
    ]);

    Http::fake([
        'pdf-service.test/pdf/export/docx' => Http::response([
            'source_type' => 'scanned',
            'ocr_applied' => true,
            'page_count' => 3,
            'content_base64' => base64_encode('PK docx'),
        ]),
    ]);

    $result = app(PdfServiceClient::class)->exportDocx('%PDF-1.7 scanned', 'eng', 'scan.pdf');

    expect($result['source_type'])->toBe('scanned')
        ->and($result['ocr_applied'])->toBeTrue()
        ->and($result['page_count'])->toBe(3)
        ->and(base64_decode($result['content_base64']))->toBe('PK docx');

    Http::assertSent(fn ($request) => $request->url() === 'http://pdf-service.test/pdf/export/docx'
        && $request->hasHeader('X-Pdf-Secret', 'top-secret')
        && $request->isMultipart());
});

it('extracts text and uploads the file with the shared secret', function () {
    config()->set('services.pdf', [
        'url' => 'http://pdf-service.test',
        'secret' => 'top-secret',
        'timeout' => 5,
        'export_timeout' => 120,
    ]);

    Http::fake([
        'pdf-service.test/pdf/extract-text' => Http::response([
            'page_count' => 2,
            'source_type' => 'native',
            'ocr_applied' => false,
            'pages' => [
                ['page_number' => 1, 'text' => 'Hello'],
                ['page_number' => 2, 'text' => 'World'],
            ],
            'text' => "Hello\n\nWorld",
        ]),
    ]);

    $result = app(PdfServiceClient::class)->extractText('%PDF-1.7 native', 'eng', 'doc.pdf');

    expect($result['page_count'])->toBe(2)
        ->and($result['source_type'])->toBe('native')
        ->and($result['pages'])->toHaveCount(2)
        ->and($result['pages'][0]['text'])->toBe('Hello');

    Http::assertSent(fn ($request) => $request->url() === 'http://pdf-service.test/pdf/extract-text'
        && $request->hasHeader('X-Pdf-Secret', 'top-secret')
        && $request->isMultipart());
});

it('embeds a batch of texts as a JSON request with the shared secret', function () {
    config()->set('services.pdf', ['url' => 'http://pdf-service.test', 'secret' => 'top-secret', 'timeout' => 5]);

    Http::fake([
        'pdf-service.test/ai/embed' => Http::response([
            'model' => 'hash',
            'dimensions' => 3,
            'embeddings' => [[1.0, 0.0, 0.0], [0.0, 1.0, 0.0]],
        ]),
    ]);

    $result = app(PdfServiceClient::class)->embed(['first', 'second']);

    expect($result['model'])->toBe('hash')
        ->and($result['dimensions'])->toBe(3)
        ->and($result['embeddings'])->toHaveCount(2)
        ->and($result['embeddings'][0])->toEqual([1, 0, 0]);

    Http::assertSent(fn ($request) => $request->url() === 'http://pdf-service.test/ai/embed'
        && $request->hasHeader('X-Pdf-Secret', 'top-secret')
        && $request['texts'] === ['first', 'second']);
});

it('asks a grounded chat question and returns the answer', function () {
    config()->set('services.pdf', ['url' => 'http://pdf-service.test', 'secret' => 'top-secret', 'timeout' => 5]);

    Http::fake([
        'pdf-service.test/ai/chat' => Http::response(['answer' => 'The total is 100 (p. 2).', 'model' => 'claude-opus-4-8']),
    ]);

    $result = app(PdfServiceClient::class)->chat(
        'What is the total?',
        [['page_number' => 2, 'content' => 'Total 100']],
        [['role' => 'user', 'content' => 'hi']],
    );

    expect($result['answer'])->toBe('The total is 100 (p. 2).')
        ->and($result['model'])->toBe('claude-opus-4-8');

    Http::assertSent(fn ($request) => $request->url() === 'http://pdf-service.test/ai/chat'
        && $request->hasHeader('X-Pdf-Secret', 'top-secret')
        && $request['question'] === 'What is the total?'
        && $request['contexts'][0]['page_number'] === 2);
});

it('summarizes text via a JSON request', function () {
    config()->set('services.pdf', ['url' => 'http://pdf-service.test', 'secret' => 'top-secret', 'timeout' => 5]);

    Http::fake([
        'pdf-service.test/ai/summarize' => Http::response(['summary' => 'Short.', 'model' => 'claude-opus-4-8']),
    ]);

    $result = app(PdfServiceClient::class)->summarize('A long body of text', 'page 3');

    expect($result['summary'])->toBe('Short.');

    Http::assertSent(fn ($request) => $request->url() === 'http://pdf-service.test/ai/summarize'
        && $request['text'] === 'A long body of text'
        && $request['scope'] === 'page 3');
});

it('translates text via a JSON request', function () {
    config()->set('services.pdf', ['url' => 'http://pdf-service.test', 'secret' => 'top-secret', 'timeout' => 5]);

    Http::fake([
        'pdf-service.test/ai/translate' => Http::response([
            'translated' => 'Bonjour',
            'target_language' => 'French',
            'model' => 'claude-opus-4-8',
        ]),
    ]);

    $result = app(PdfServiceClient::class)->translate('Hello', 'French');

    expect($result['translated'])->toBe('Bonjour')
        ->and($result['target_language'])->toBe('French');

    Http::assertSent(fn ($request) => $request->url() === 'http://pdf-service.test/ai/translate'
        && $request['text'] === 'Hello'
        && $request['target_language'] === 'French');
});

it('returns multiple outputs from a split operation', function () {
    config()->set('services.pdf', [
        'url' => 'http://pdf-service.test',
        'secret' => 'top-secret',
        'timeout' => 5,
    ]);

    Http::fake([
        'pdf-service.test/pdf/pages' => Http::response([
            'outputs' => [
                ['page_count' => 2, 'content_base64' => 'QQ=='],
                ['page_count' => 3, 'content_base64' => 'Qg=='],
            ],
        ]),
    ]);

    $outputs = app(PdfServiceClient::class)->pages(
        [['contents' => '%PDF-1.7 fake', 'filename' => 'report.pdf']],
        ['op' => 'split', 'ranges' => [[1, 2], [3, 5]]],
    );

    expect($outputs)->toHaveCount(2)
        ->and($outputs[0]['page_count'])->toBe(2)
        ->and($outputs[1]['page_count'])->toBe(3);
});
