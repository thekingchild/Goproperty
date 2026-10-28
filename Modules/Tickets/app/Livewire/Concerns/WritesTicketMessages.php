<?php

declare(strict_types=1);

namespace Modules\Tickets\Livewire\Concerns;

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;

/**
 * The reply form of a ticket's conversation (P04-02), on the staff, tenant
 * and owner pages: the words, the files (Livewire's temporary uploads,
 * checked again by Messages\TicketMessages from their bytes), a limit of
 * tries a minute, and the form cleared once sent. The thread itself is
 * read in render(), never kept in a public property, so it is not in the
 * component's snapshot.
 */
trait WritesTicketMessages
{
    use WithFileUploads;

    /** Tries to send a message a user may make a minute. */
    public const int MESSAGES_PER_MINUTE = 20;

    public string $body = '';

    /** @var array<int, mixed> the files chosen (Livewire's temporary uploads) */
    public array $files = [];

    /** @throws ValidationException when the user sent too many messages this minute */
    protected function throttle(User $user): void
    {
        $key = 'ticket-messages:'.$user->id;

        if (RateLimiter::tooManyAttempts($key, self::MESSAGES_PER_MINUTE)) {
            throw ValidationException::withMessages(['body' => __('Too many messages. Try again in :seconds seconds.', ['seconds' => max(1, RateLimiter::availableIn($key))])]);
        }

        RateLimiter::hit($key, 60);
    }

    /** @return list<UploadedFile> */
    protected function chosenFiles(): array
    {
        return array_values(array_filter($this->files, static fn (mixed $file): bool => $file instanceof UploadedFile));
    }

    protected function sent(): void
    {
        $this->reset('body', 'files');
        $this->resetValidation();
    }
}
