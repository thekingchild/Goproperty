<?php

declare(strict_types=1);

namespace App\Mail\Templates;

/**
 * An email the code sends by key, as the code knows it (P05-04): its
 * category, the placeholders it fills in (the whitelist) and the wording it
 * starts with. The wording is GoProperty's to change in the console
 * (`content-manager`); the key, category and placeholders follow the code.
 * Core and modules add theirs to TemplateCatalog from their providers.
 */
final readonly class TemplateDefinition
{
    /** @param  list<Placeholder>  $placeholders */
    public function __construct(
        public string $key,
        public string $name,
        public string $description,
        public TemplateCategory $category,
        public array $placeholders,
        public TemplateContent $content,
    ) {}

    /** @return list<string> */
    public function placeholderNames(): array
    {
        return array_map(static fn (Placeholder $placeholder): string => $placeholder->name, $this->placeholders);
    }

    /** @return array<string, string> name => sample value */
    public function samples(): array
    {
        $samples = [];

        foreach ($this->placeholders as $placeholder) {
            $samples[$placeholder->name] = $placeholder->sample;
        }

        return $samples;
    }
}
