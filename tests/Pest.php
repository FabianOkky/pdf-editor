<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

/**
 * Fake the Python service's `/pdf/pages` (and `/pdf/thumbnails`) endpoints so page
 * operations resolve without a running microservice.
 *
 * @param  list<array{page_count: int, content_base64: string}>  $outputs
 */
function fakePdfPages(array $outputs): void
{
    Http::fake([
        '*/pdf/pages' => Http::response(['outputs' => $outputs]),
        '*/pdf/thumbnails' => Http::response(['thumbnails' => []]),
    ]);
}

/**
 * A produced output payload (base64-encoded PDF bytes + page count) for {@see fakePdfPages}.
 *
 * @return array{page_count: int, content_base64: string}
 */
function pdfOutput(int $pageCount, string $bytes = '%PDF-1.7 produced'): array
{
    return ['page_count' => $pageCount, 'content_base64' => base64_encode($bytes)];
}

/**
 * Fake the Python service's `/pdf/bake` endpoint so overlay baking resolves without a
 * running microservice.
 */
function fakePdfBake(int $pageCount = 1, string $bytes = '%PDF-baked'): void
{
    Http::fake([
        '*/pdf/bake' => Http::response(['page_count' => $pageCount, 'content_base64' => base64_encode($bytes)]),
        '*/pdf/thumbnails' => Http::response(['thumbnails' => []]),
    ]);
}

/**
 * Fake the Python service's `/pdf/form-fields` detection endpoint.
 *
 * @param  list<array<string, mixed>>  $fields
 */
function fakePdfFormFields(array $fields = [], bool $isForm = true): void
{
    Http::fake([
        '*/pdf/form-fields' => Http::response(['is_form' => $isForm, 'fields' => $fields]),
    ]);
}

/**
 * Fake the Python service's `/pdf/export/docx` endpoint so Word exports resolve without a
 * running microservice.
 */
function fakePdfExport(
    string $sourceType = 'native',
    bool $ocrApplied = false,
    int $pageCount = 1,
    string $docxBytes = 'PK fake-docx-bytes',
): void {
    Http::fake([
        '*/pdf/export/docx' => Http::response([
            'source_type' => $sourceType,
            'ocr_applied' => $ocrApplied,
            'page_count' => $pageCount,
            'content_base64' => base64_encode($docxBytes),
        ]),
    ]);
}

/**
 * Fake the AI assistant endpoints (`/pdf/extract-text` + `/ai/*`) so the assistant resolves
 * without a running microservice or an LLM key. Embeddings are computed deterministically from a
 * tiny fixed vocabulary, so retrieval (in-process cosine) is real and reproducible in tests.
 * Pass `$overrides` to replace any endpoint (e.g. a specific chat answer or extract-text pages).
 *
 * @param  array<string, mixed>  $overrides
 */
function fakeAi(array $overrides = []): void
{
    $vocab = ['invoice', 'total', 'amount', 'payment', 'contract', 'weather', 'rain', 'forecast', 'cat', 'dog'];

    $embed = fn (array $texts): array => array_map(
        fn (string $text): array => array_map(
            fn (string $word): float => (float) substr_count(strtolower($text), $word),
            $vocab,
        ),
        $texts,
    );

    Http::fake(array_merge([
        '*/pdf/extract-text' => Http::response([
            'page_count' => 1,
            'source_type' => 'native',
            'ocr_applied' => false,
            'pages' => [['page_number' => 1, 'text' => 'This document has extractable text.']],
            'text' => 'This document has extractable text.',
        ]),
        '*/ai/embed' => function ($request) use ($embed, $vocab) {
            $texts = data_get(json_decode($request->body(), true), 'texts', []);

            return Http::response([
                'model' => 'hash',
                'dimensions' => count($vocab),
                'embeddings' => $embed($texts),
            ]);
        },
        '*/ai/chat' => Http::response(['answer' => 'Here is the answer (p. 1).', 'model' => 'claude-opus-4-8']),
        '*/ai/summarize' => Http::response(['summary' => 'A concise summary.', 'model' => 'claude-opus-4-8']),
        '*/ai/translate' => Http::response(['translated' => 'Una traducción.', 'target_language' => 'Spanish', 'model' => 'claude-opus-4-8']),
    ], $overrides));
}

/**
 * An `/pdf/extract-text` response payload for {@see fakeAi} overrides.
 *
 * @param  list<array{page_number: int, text: string}>  $pages
 * @return Response
 */
function fakeExtractText(array $pages, string $sourceType = 'native', bool $ocrApplied = false)
{
    return Http::response([
        'page_count' => count($pages),
        'source_type' => $sourceType,
        'ocr_applied' => $ocrApplied,
        'pages' => $pages,
        'text' => implode("\n\n", array_map(fn (array $p): string => $p['text'], $pages)),
    ]);
}
