<?php

declare(strict_types=1);

namespace Modules\Tickets\Tickets;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Modules\Tickets\Enums\TicketCategory;
use Modules\Tickets\Enums\TicketPriority;

/**
 * What a ticket form types (`category`, `priority`, `title`,
 * `description`), checked the same way for tenants and staff. Whitespace
 * is tidied; a draft may leave the description for later, a sent ticket
 * may not.
 */
final class TicketInput
{
    public const int TITLE_MAX = 120;

    public const int DESCRIPTION_MAX = 5000;

    public const int NOTE_MAX = 1000;

    /**
     * @param  array<string, mixed>  $input
     * @return array{category: TicketCategory, priority: TicketPriority, title: string, description: string|null}
     *
     * @throws ValidationException
     */
    public static function validated(array $input, bool $draft = false): array
    {
        $values = [
            'category' => is_string($input['category'] ?? null) ? $input['category'] : null,
            'priority' => is_string($input['priority'] ?? null) && $input['priority'] !== '' ? $input['priority'] : TicketPriority::Medium->value,
            'title' => self::line($input['title'] ?? null),
            'description' => self::text($input['description'] ?? null),
        ];

        Validator::make($values, [
            'category' => ['required', Rule::in(TicketCategory::values())],
            'priority' => ['required', Rule::in(TicketPriority::values())],
            'title' => ['required', 'string', 'min:3', 'max:'.self::TITLE_MAX],
            'description' => [$draft ? 'nullable' : 'required', 'string', 'min:3', 'max:'.self::DESCRIPTION_MAX],
        ], [
            'category.required' => __('Choose what kind of problem it is.'),
            'title.required' => __('Say in a few words what is wrong.'),
            'description.required' => __('Describe the problem: where it is and what happens.'),
        ], [
            'title' => __('title'),
            'description' => __('description'),
        ])->validate();

        return [
            'category' => TicketCategory::from((string) $values['category']),
            'priority' => TicketPriority::from((string) $values['priority']),
            'title' => (string) $values['title'],
            'description' => $values['description'],
        ];
    }

    /**
     * A note given with a move (what was done, why it is reopened), tidied;
     * null when empty. Required ones say so.
     *
     * @throws ValidationException
     */
    public static function note(?string $note, bool $required = false, string $key = 'note', ?string $requiredMessage = null): ?string
    {
        $note = self::text($note);

        Validator::make([$key => $note], [$key => [$required ? 'required' : 'nullable', 'string', 'min:3', 'max:'.self::NOTE_MAX]], [
            $key.'.required' => $requiredMessage ?? __('Add a note.'),
        ], [$key => __('note')])->validate();

        return $note;
    }

    /** One line: spaces squeezed, trimmed; null when empty. */
    private static function line(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim((string) preg_replace('/\s+/u', ' ', $value));

        return $value === '' ? null : $value;
    }

    /** Several lines: line endings made \n, trailing spaces and blank runs tidied; null when empty. */
    private static function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = str_replace(["\r\n", "\r"], "\n", $value);
        $value = (string) preg_replace('/[ \t]+\n/u', "\n", $value);
        $value = trim((string) preg_replace('/\n{3,}/u', "\n\n", $value));

        return $value === '' ? null : $value;
    }
}
