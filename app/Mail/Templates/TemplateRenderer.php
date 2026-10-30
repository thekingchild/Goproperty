<?php

declare(strict_types=1);

namespace App\Mail\Templates;

use Illuminate\Support\Str;

/**
 * Fills a template in (PLATFORM_CONSOLE.md § 5; AGENT.md § 3 rule 10:
 * templates are data, not code). Only `{{ name }}` placeholders on the
 * template's whitelist are replaced, with the plain values the sender
 * gives; everything else — an unknown `{{ name }}`, Blade (`{{ $x }}`,
 * `{!! … !!}`, `@php`), PHP tags, HTML — stays exactly as typed and is
 * escaped. Nothing ever reaches Blade or `eval`.
 *
 * - Body HTML: values are inserted into the Markdown with every ASCII
 *   punctuation mark backslash-escaped (so a value is always literal text,
 *   never Markdown), then converted with raw HTML escaped and unsafe links
 *   (javascript:, data:…) dropped. A value placed inside a code span would
 *   show those backslashes: write placeholders in plain text or links.
 * - Body text: the Markdown with the values as they are, links written out
 *   as "label (address)".
 * - Subject: plain text, on one line.
 */
final class TemplateRenderer
{
    public const string PATTERN = '/\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)*)\s*\}\}/';

    /**
     * @param  list<string>  $allowed  the template's whitelist
     * @param  array<string, string|int|null>  $values  name => plain value
     */
    public function render(TemplateContent $content, array $allowed, array $values): RenderedEmail
    {
        $raw = fn (string $value): string => $value;

        $html = Str::markdown($this->fill($content->body, $allowed, $values, self::escapeMarkdown(...)), [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 20,
        ]);

        $text = (string) preg_replace('/\[([^\]\n]+)\]\(\s*([^)\s]+)\s*\)/', '$1 ($2)', $this->fill($content->body, $allowed, $values, $raw));
        $subject = trim((string) preg_replace('/\s+/u', ' ', $this->fill($content->subject, $allowed, $values, $raw)));
        $label = $content->actionLabel !== null ? trim($this->fill($content->actionLabel, $allowed, $values, $raw)) : '';

        return new RenderedEmail(
            subject: mb_substr($subject, 0, 200),
            html: trim($html),
            text: trim(str_replace("\r\n", "\n", $text)),
            actionLabel: $label !== '' ? $label : null,
        );
    }

    /** How a placeholder is written in a template, for the console's lists. */
    public static function tag(string $name): string
    {
        return '{{ '.$name.' }}';
    }

    /**
     * Placeholder names written in the text, whitelisted or not.
     *
     * @return list<string>
     */
    public function placeholdersIn(string $text): array
    {
        preg_match_all(self::PATTERN, $text, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * Placeholders written that this template cannot fill (shown as typed).
     *
     * @param  list<string>  $allowed
     * @return list<string>
     */
    public function unknown(TemplateContent $content, array $allowed): array
    {
        $used = $this->placeholdersIn($content->subject."\n".$content->body."\n".$content->actionLabel);

        return array_values(array_diff($used, $allowed));
    }

    /** Whether the text looks like code someone hoped would run (Blade or PHP); it is shown as typed. */
    public function looksLikeCode(TemplateContent $content): bool
    {
        return preg_match('/\{\{\s*\$|\{!!|@php|@\w+\s*\(|<\?(php|=)?/i', $content->subject."\n".$content->body."\n".$content->actionLabel) === 1;
    }

    /**
     * @param  list<string>  $allowed
     * @param  array<string, string|int|null>  $values
     * @param  callable(string): string  $escape
     */
    private function fill(string $text, array $allowed, array $values, callable $escape): string
    {
        return (string) preg_replace_callback(self::PATTERN, static function (array $match) use ($allowed, $values, $escape): string {
            if (! in_array($match[1], $allowed, true)) {
                return $match[0];
            }

            $value = trim((string) preg_replace('/[\r\n]+/', ' ', (string) ($values[$match[1]] ?? '')));

            return $escape($value);
        }, $text);
    }

    private static function escapeMarkdown(string $value): string
    {
        return (string) preg_replace('/([!-\/:-@\[-`{-~])/', '\\\\$1', $value);
    }
}
