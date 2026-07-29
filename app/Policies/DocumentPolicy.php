<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

/**
 * Documents are strictly owner-scoped: a user may only see and act on documents they own
 * (ARCHITECTURE.md §6). Ownership is the single rule behind every ability here.
 */
class DocumentPolicy
{
    /**
     * Determine whether the user can view their document library.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the document.
     */
    public function view(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    /**
     * Determine whether the user can upload documents.
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can update (e.g. rename) the document.
     */
    public function update(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    /**
     * Determine whether the user can delete the document.
     */
    public function delete(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    /**
     * Determine whether the user can download the original file.
     */
    public function download(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    /**
     * Determine whether the user can restore the document.
     */
    public function restore(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    /**
     * Determine whether the user can permanently delete the document.
     */
    public function forceDelete(User $user, Document $document): bool
    {
        return $this->owns($user, $document);
    }

    /**
     * Whether the given user owns the document.
     */
    protected function owns(User $user, Document $document): bool
    {
        return $user->id === $document->user_id;
    }
}
