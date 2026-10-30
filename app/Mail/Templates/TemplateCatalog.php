<?php

declare(strict_types=1);

namespace App\Mail\Templates;

use InvalidArgumentException;

/**
 * Every email template the code knows (P05-04). A module adds its own from
 * its service provider, as import targets do:
 * `$this->app->callAfterResolving(TemplateCatalog::class, fn (TemplateCatalog $catalog) => $catalog->add(...))`.
 * EmailTemplatesSeeder writes them into `email_templates`; a key not yet
 * seeded is sent with the wording given here.
 */
final class TemplateCatalog
{
    /** @var array<string, TemplateDefinition> */
    private array $definitions = [];

    public function add(TemplateDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            if (preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $definition->key) !== 1) {
                throw new InvalidArgumentException("Template key [{$definition->key}] must look like area.name.");
            }

            $this->definitions[$definition->key] = $definition;
        }
    }

    public function find(string $key): ?TemplateDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    public function get(string $key): TemplateDefinition
    {
        return $this->find($key) ?? throw new InvalidArgumentException("No email template [{$key}].");
    }

    /** @return array<string, TemplateDefinition> */
    public function all(): array
    {
        ksort($this->definitions);

        return $this->definitions;
    }
}
