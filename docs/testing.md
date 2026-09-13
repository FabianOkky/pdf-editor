# Testing

Both halves of the application have their own suite, and **both run fully offline**. The Python
service is replaced by an HTTP fake in the Laravel tests, and the LLM is faked on both sides, so
no API key, no running microservice and no network access is needed.

---

## Running the suites

### Laravel (Pest)

```bash
php artisan test --compact                       # everything
php artisan test --compact --filter=UploadTest   # one file or one test name
```

The suite needs a PostgreSQL database named `pdf_editor_test` (configured in `phpunit.xml`).
Create it once:

```bash
createdb pdf_editor_test
```

### Python (pytest)

```bash
cd pdf-service
python -m pytest -q
python -m pytest -q tests/test_pdf_bake.py       # one file
```

### Everything, the way CI runs it

```bash
composer test
```

That runs, in order: `config:clear` → Pint (check mode) → PHPStan → the Pest suite. A failure at
any step stops the chain.

---

## Quality gates

| Gate | Command | What it enforces |
|---|---|---|
| **Pest** | `php artisan test --compact` | Feature behaviour |
| **Pint** | `vendor/bin/pint` | PHP code style (Laravel preset) |
| **PHPStan / Larastan** | `vendor/bin/phpstan analyse --memory-limit=1G` | Static types at **level 7** |
| **pytest** | `python -m pytest -q` | The Python service |
| **ruff** | `python -m ruff check app tests` | Python lint (`E`, `F`, `I`, `UP`, `B`) |
| **black** | `python -m black --check app tests` | Python formatting, 100 columns |

Run `vendor/bin/pint` — not `pint --test` — to actually fix style rather than just report it.

### Current state

| Suite | Result |
|---|---|
| Pest | 166 tests, 165 passed, 1 skipped, 517 assertions |
| pytest | 96 passed |
| PHPStan | 0 errors at level 7 |
| Pint / ruff / black | clean |

The one skipped test is environment-gated: it needs a real `tessdata` language file, which is
not committed.

---

## Continuous integration

Two workflows run on pushes and pull requests to `main`, `master` and `develop`:

**`.github/workflows/tests.yml`** — two jobs:

- `ci` — PHP 8.4 and 8.5 against a PostgreSQL 18 service container: install, build assets, run
  PHPStan, run Pest.
- `pdf-service` — Python 3.12: install, run ruff and black, run pytest.

**`.github/workflows/lint.yml`** — runs Pint.

Plain PostgreSQL is enough; the RAG vector store is portable JSON rather than pgvector, so no
extension is required.

---

## How the tests are structured

### Laravel

`tests/Feature/` holds almost everything — Livewire component interactions, policies, file
streaming, seeding. That is deliberate: the value is in the flows, not in isolated units.

| Area | File |
|---|---|
| Auth | `Feature/Auth/*` — registration, login, password reset and confirmation |
| Library | `Feature/Documents/DocumentLibraryTest.php`, `UploadTest.php` |
| Viewer | `Feature/Documents/DocumentViewerTest.php` |
| Page operations | `PageOrganizeTest.php`, `DocumentSplitTest.php`, `DocumentMergeTest.php` |
| Overlay editor | `OverlayEditorTest.php`, `OverlayBakeTest.php` |
| Edited output | `EditedOutputTest.php` — every path that hands the user a file carries their edits |
| Versions | `DocumentVersionTest.php` |
| Forms & signatures | `FormFieldTest.php`, `SignatureTest.php` |
| Word export | `WordExportTest.php` |
| AI | `AiAssistantTest.php`, `RagServiceTest.php` |
| Service contract | `Feature/PdfServiceClientTest.php` |
| Seeding | `Feature/DemoSeederTest.php` |

Use the factories in `database/factories/` rather than building models by hand — several carry
states that wire up realistic relationships.

### Python

`pdf-service/tests/` has one file per router or service module, exercising both the FastAPI
route and the underlying function. Fixture PDFs are built **in memory** with PyMuPDF rather than
committed as binaries, so they stay small, readable and easy to vary.

---

## How external calls are faked

**In Laravel**, the Python service is replaced with Laravel's HTTP fake:

```php
Http::fake([
    '*/pdf/info' => Http::response([
        'page_count' => 3,
        'pages' => [['width' => 595.28, 'height' => 841.89]],
        'source_type' => 'native',
    ]),
]);
```

`tests/Feature/PdfServiceClientTest.php` is where the contract itself is pinned: it asserts the
shared-secret header is sent, that multipart bodies are shaped correctly, and that failures
surface as exceptions.

**In Python**, the single LLM touch-point (`ai_llm._complete`) is monkeypatched, so real
prompt-building runs but no provider call is made. Embeddings use the `hash` provider, which is
deterministic by construction.

---

## What is worth asserting

Two invariants are load-bearing enough to be tested directly:

1. **The original bytes never change.** `OverlayBakeTest` asserts the original file is
   byte-identical after a bake. If that ever fails, the Golden Rule is broken.
2. **Edits reach every output.** `EditedOutputTest` covers download, Word export, page
   operations, split and merge — each must bake pending overlays first. This exists because the
   first implementation handed back the pristine original.

---

## Writing new tests

```bash
php artisan make:test --pest DocumentArchiveTest        # feature (default)
php artisan make:test --pest --unit CoordinateTest      # unit
```

Do not pass a directory in the name — `make:test --pest SomeFeatureTest`, not
`Feature/SomeFeatureTest`.

A few conventions this codebase follows:

- Every change ships with a test — a new one, or an updated existing one.
- Prefer a feature test that drives the Livewire component over a unit test of a method.
- Fake the Python service; never let a test depend on it running.
- Use `Storage::fake('pdfs')` so tests never touch real storage.
- Name the behaviour, not the method: `it('bakes pending overlays before downloading')`.

After touching PHP, run `vendor/bin/pint --dirty` before committing.
