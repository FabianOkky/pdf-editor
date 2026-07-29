<?php

namespace App\Http\Controllers;

use App\Enums\DocumentStatus;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * The signed-in landing page: a quick overview of the user's library — headline stats,
 * the most recent documents, and the primary actions. All data is scoped to the user.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();

        $documents = $user->documents()
            ->with('latestVersion')
            ->latest()
            ->take(6)
            ->get();

        $stats = $this->stats($user);

        return view('dashboard', [
            'recentDocuments' => $documents,
            'stats' => $stats,
        ]);
    }

    /**
     * Headline counters for the dashboard cards.
     *
     * @return array{documents: int, edited: int, pages: int, storage: string}
     */
    protected function stats(User $user): array
    {
        return [
            'documents' => $user->documents()->where('status', DocumentStatus::Ready)->count(),
            'edited' => $user->documents()->where('status', DocumentStatus::Ready)->has('versions')->count(),
            'pages' => (int) $user->documents()->where('status', DocumentStatus::Ready)->sum('page_count'),
            'storage' => $this->humanBytes((int) $user->documents()->where('status', DocumentStatus::Ready)->sum('size_bytes')),
        ];
    }

    /**
     * Format a byte count as a short human-readable string (e.g. "3.2 MB").
     */
    protected function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        $unitIndex = 0;

        while ($value >= 1024 && $unitIndex < count($units) - 1) {
            $value /= 1024;
            $unitIndex++;
        }

        return number_format($value, $value >= 10 ? 0 : 1).' '.$units[$unitIndex];
    }
}
