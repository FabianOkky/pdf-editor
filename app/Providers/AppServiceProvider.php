<?php

namespace App\Providers;

use App\Services\PdfServiceClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PdfServiceClient::class, function ($app): PdfServiceClient {
            /** @var array{url: string, secret: ?string, timeout: int, export_timeout?: int} $config */
            $config = $app['config']->get('services.pdf');

            return new PdfServiceClient(
                baseUrl: $config['url'],
                secret: $config['secret'] ?? null,
                timeout: (int) $config['timeout'],
                longTimeout: (int) ($config['export_timeout'] ?? 300),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureFileUploads();
    }

    /**
     * Raise Livewire's global temporary-upload size cap to match our configured PDF limit.
     *
     * Livewire validates every temporary upload with `file|max:12288` (12 MB) before a
     * component ever sees it, which would reject larger PDFs below our own limit. We widen
     * it to the configured maximum; the upload component applies the precise mime/size rules.
     */
    protected function configureFileUploads(): void
    {
        $maxKilobytes = (int) config('services.pdf.max_upload_mb', 25) * 1024;

        config(['livewire.temporary_file_upload.rules' => ['required', 'file', "max:{$maxKilobytes}"]]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
