<?php

declare(strict_types=1);

namespace App\Privacy\Data;

/**
 * Takes a person's identifying values (Person::$knownValues: names,
 * email, phone in its written forms, address, ID number, emergency
 * contact) out of text another person wrote and out of the snapshots
 * issued documents keep (`bills.details`, `receipts.details`, ...), leaving
 * the rest of the text, the amounts and the numbers (P09-02; default 16).
 */
final readonly class Scrubber
{
    public const string ERASED = '[erased]';

    public function __construct(private Person $person) {}

    public function text(?string $text): ?string
    {
        if ($text === null || $text === '' || $this->person->knownValues === []) {
            return $text;
        }

        return str_ireplace($this->person->knownValues, self::ERASED, $text);
    }

    /** A JSON column's text: every string inside it scrubbed, keys and numbers kept. */
    public function json(?string $json): ?string
    {
        if ($json === null || $json === '') {
            return $json;
        }

        $value = json_decode($json, true);

        if (! is_array($value)) {
            return is_string($value) ? json_encode($this->text($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $json : $json;
        }

        array_walk_recursive($value, function (mixed &$item): void {
            if (is_string($item)) {
                $item = $this->text($item);
            }
        });

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $json;
    }
}
