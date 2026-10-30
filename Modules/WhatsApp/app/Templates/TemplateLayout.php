<?php

declare(strict_types=1);

namespace Modules\WhatsApp\Templates;

/**
 * A template's text parts and variables, read from Meta's `components`
 * (P05-06): the header (text headers only), the body, the footer and URL
 * buttons. Variables are Meta's `{{1}}`, `{{2}}` (positional) or
 * `{{first_name}}` (named), keyed here by where they are: `header.1`,
 * `body.1`, `body.first_name`, `button.0` (the URL button at index 0 has
 * at most one).
 *
 * Plain text in and out: values are put in as text, never as markup
 * (AGENT.md § 3 rule 10), and the Cloud API's parameters are built from
 * the same values (components()).
 */
final readonly class TemplateLayout
{
    private const string VARIABLE = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    /** @param  list<array{index: int, text: string, url: string}>  $buttons */
    public function __construct(
        public ?string $header,
        public string $body,
        public ?string $footer,
        public array $buttons,
    ) {}

    /** @param  array<int|string, mixed>  $components */
    public static function fromComponents(array $components): self
    {
        $header = null;
        $body = '';
        $footer = null;
        $buttons = [];

        foreach ($components as $component) {
            if (! is_array($component)) {
                continue;
            }

            $type = strtoupper((string) ($component['type'] ?? ''));
            $text = is_string($component['text'] ?? null) ? $component['text'] : null;

            if ($type === 'HEADER' && strtoupper((string) ($component['format'] ?? 'TEXT')) === 'TEXT' && $text !== null) {
                $header = $text;
            } elseif ($type === 'BODY' && $text !== null) {
                $body = $text;
            } elseif ($type === 'FOOTER' && $text !== null) {
                $footer = $text;
            } elseif ($type === 'BUTTONS' && is_array($component['buttons'] ?? null)) {
                foreach (array_values($component['buttons']) as $index => $button) {
                    if (is_array($button) && strtoupper((string) ($button['type'] ?? '')) === 'URL' && is_string($button['url'] ?? null)) {
                        $buttons[] = ['index' => $index, 'text' => (string) ($button['text'] ?? ''), 'url' => $button['url']];
                    }
                }
            }
        }

        return new self($header, $body, $footer, $buttons);
    }

    /**
     * Every variable, in the order Meta numbers them: header, body, then buttons.
     *
     * @return list<string>
     */
    public function variables(): array
    {
        $variables = [];

        foreach (['header' => $this->header, 'body' => $this->body] as $part => $text) {
            foreach (self::names((string) $text) as $name) {
                $variables[] = $part.'.'.$name;
            }
        }

        foreach ($this->buttons as $button) {
            if (self::names($button['url']) !== []) {
                $variables[] = 'button.'.$button['index'];
            }
        }

        return $variables;
    }

    /** "Body variable {{1}}", "Button link (Pay now)". */
    public function describe(string $variable): string
    {
        [$part, $name] = explode('.', $variable, 2) + [1 => ''];

        if ($part === 'button') {
            foreach ($this->buttons as $button) {
                if ((string) $button['index'] === $name) {
                    return 'Button "'.$button['text'].'": the end of '.$button['url'];
                }
            }
        }

        return ucfirst($part).' {{'.$name.'}}';
    }

    /**
     * The message as the tenant reads it, every variable filled with its value
     * (a missing one stays as `{{1}}`), plain text.
     *
     * @param  array<string, string>  $values  variable => value
     */
    public function render(array $values): string
    {
        $lines = [];

        if ($this->header !== null) {
            $lines[] = self::fill($this->header, 'header', $values);
        }

        $lines[] = self::fill($this->body, 'body', $values);

        if ($this->footer !== null) {
            $lines[] = $this->footer;
        }

        foreach ($this->buttons as $button) {
            $lines[] = '['.$button['text'].'] '.self::buttonUrl($button['url'], $values['button.'.$button['index']] ?? null);
        }

        return implode("\n\n", array_filter($lines, static fn (string $line): bool => $line !== ''));
    }

    /**
     * The Cloud API's `template.components` for these values.
     *
     * @param  array<string, string>  $values  variable => value (each one present)
     * @return list<array<string, mixed>>
     */
    public function components(array $values): array
    {
        $components = [];

        foreach (['header' => $this->header, 'body' => $this->body] as $part => $text) {
            $parameters = [];

            foreach (self::names((string) $text) as $name) {
                $parameter = ['type' => 'text', 'text' => $values[$part.'.'.$name] ?? ''];

                if (! ctype_digit($name)) {
                    $parameter['parameter_name'] = $name;
                }

                $parameters[] = $parameter;
            }

            if ($parameters !== []) {
                $components[] = ['type' => $part, 'parameters' => $parameters];
            }
        }

        foreach ($this->buttons as $button) {
            if (self::names($button['url']) === []) {
                continue;
            }

            $components[] = [
                'type' => 'button',
                'sub_type' => 'url',
                'index' => (string) $button['index'],
                'parameters' => [['type' => 'text', 'text' => self::buttonSuffix($button['url'], $values['button.'.$button['index']] ?? '')]],
            ];
        }

        return $components;
    }

    /**
     * What a URL button's variable is given: Meta adds it to the button's
     * address, so a full link mapped there (the pay link) gives only the
     * part after the address's fixed beginning.
     */
    public static function buttonSuffix(string $url, string $value): string
    {
        $prefix = (string) strstr($url, '{{', true);

        return $prefix !== '' && str_starts_with($value, $prefix) ? substr($value, strlen($prefix)) : $value;
    }

    /** @return list<string> the variable names in the text, in order of first use; numbers ascending */
    private static function names(string $text): array
    {
        preg_match_all(self::VARIABLE, $text, $matches);
        $names = array_values(array_unique($matches[1]));

        if ($names !== [] && array_filter($names, 'ctype_digit') === $names) {
            sort($names, SORT_NUMERIC);
        }

        return array_map('strval', $names);
    }

    /** @param  array<string, string>  $values */
    private static function fill(string $text, string $part, array $values): string
    {
        return (string) preg_replace_callback(self::VARIABLE, static fn (array $match): string => $values[$part.'.'.$match[1]] ?? $match[0], $text);
    }

    private static function buttonUrl(string $url, ?string $value): string
    {
        if ($value === null) {
            return $url;
        }

        $suffix = self::buttonSuffix($url, $value);

        return (string) preg_replace_callback(self::VARIABLE, static fn (): string => $suffix, $url, 1);
    }
}
