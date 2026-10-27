<?php

declare(strict_types=1);

namespace Modules\Import\Contracts;

/**
 * One column a target reads. The label is the template's header; the
 * aliases are other headers agencies use for it (mapping suggestions);
 * options are the fixed values offered as a dropdown in the template.
 */
final readonly class ImportColumn
{
    public const string EXTERNAL_REF = 'external_ref';

    /**
     * @param  list<string>  $aliases
     * @param  list<string>  $options
     */
    public function __construct(
        public string $key,
        public string $label,
        public bool $required = false,
        public array $aliases = [],
        public ?string $example = null,
        public ?string $help = null,
        public array $options = [],
    ) {}

    /** The agency's own ID for the row (DATA_IMPORT.md § 2): re-importing it updates instead of duplicating. */
    public static function externalRef(?string $example = null): self
    {
        return new self(
            key: self::EXTERNAL_REF,
            label: 'Your ID',
            aliases: ['external ref', 'external id', 'reference', 'ref', 'our id', 'your ref', 'id'],
            example: $example,
            help: 'Optional: your own ID for this row. Importing the same ID again updates the record instead of adding it twice.',
        );
    }
}
