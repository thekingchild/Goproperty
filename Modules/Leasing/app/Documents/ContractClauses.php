<?php

declare(strict_types=1);

namespace Modules\Leasing\Documents;

/**
 * The agency's clause template for lease contracts (BRANDING_DOCUMENTS.md
 * § 4: "clauses (agency-editable template)"), stored as the organization
 * setting `leasing.contract_clauses` (Settings → Lease contract).
 *
 * Templates are data, never code (AGENT.md § 3 rule 10): the text is never
 * compiled by Blade or evaluated. Only the placeholders listed in
 * PLACEHOLDERS are filled in, written as `{name}` with lower-case letters
 * and underscores; anything else — an unknown `{name}`, `{{ … }}`,
 * `{!! … !!}`, `@php`, HTML — stays exactly as typed and is printed as
 * plain text (the document view escapes every clause, values included).
 *
 * Clauses are separated by a blank line and numbered on the contract;
 * single line breaks inside a clause are kept.
 */
final class ContractClauses
{
    public const string SETTING = 'leasing.contract_clauses';

    /** Longest template accepted (characters). */
    public const int MAX_LENGTH = 10_000;

    /**
     * Placeholder => what it stands for (shown on the settings page).
     *
     * @var array<string, string>
     */
    public const array PLACEHOLDERS = [
        'tenant_name' => 'The tenant\'s full name',
        'tenant_phone' => 'The tenant\'s phone number',
        'landlord' => 'The landlord: the building\'s owner, or the agency when no owner is recorded',
        'agency_name' => 'Your company name as printed on documents',
        'property' => 'The building\'s name',
        'unit' => 'The unit (e.g. Flat 3B)',
        'address' => 'The building\'s address',
        'rent' => 'Rent for one period, e.g. ₦1,500,000.00',
        'rent_in_words' => 'Rent for one period in words',
        'cycle' => 'How often rent is paid, e.g. yearly',
        'deposit' => 'The caution deposit',
        'start_date' => 'First day of the lease',
        'end_date' => 'Last day of the lease',
        'contract_number' => 'The contract number',
    ];

    /** `{name}` not touching another brace, so `{{name}}` stays literal too. */
    private const string PATTERN = '/(?<!\{)\{([a-z_]{1,40})\}(?!\})/';

    public const string DEFAULT = <<<'TEXT'
        The Landlord, {landlord}, through {agency_name} as managing agent, lets {unit} at {property}, {address} (the "Premises") to {tenant_name} (the "Tenant") for the term from {start_date} to {end_date}.

        The Tenant shall pay rent of {rent} ({cycle}) in advance, on or before each due date in the rent schedule above, into the account named on the agency's invoice, quoting the invoice's payment reference. A payment is confirmed only by an official receipt.

        The Tenant has paid a caution deposit of {deposit}. It is refunded when the tenancy ends and the Premises have been inspected, less the cost of making good any damage beyond fair wear and tear and any rent or charges left unpaid.

        The Tenant shall use the Premises as a private dwelling only, unless the Landlord agrees otherwise in writing, keep them clean and in good repair, and shall not sublet, assign or part with possession of any part of them without the Landlord's written consent.

        The Tenant shall make no structural alteration or addition to the Premises without the Landlord's written consent, and shall let the Landlord or the managing agent inspect the Premises at reasonable times after giving notice.

        The Tenant shall pay the service charge, utility bills and levies for the Premises as they are billed, and shall keep to the estate rules in force.

        The Landlord shall keep the structure and exterior of the Premises in repair, and the Tenant may enjoy the Premises in peace for as long as the Tenant keeps to this agreement.

        Either party may end this tenancy by giving the notice required by the tenancy law of the State in which the Premises are. When the tenancy ends, the Tenant shall give up the Premises in the condition they were let in, fair wear and tear excepted.

        This agreement is governed by the laws of the Federal Republic of Nigeria and of the State in which the Premises are.
        TEXT;

    /**
     * The template's clauses with the known placeholders filled in, as
     * plain text (escape when printing).
     *
     * @param  array<string, string>  $values  placeholder => value; keys outside PLACEHOLDERS are ignored
     * @return list<string>
     */
    public static function render(string $template, array $values): array
    {
        $values = array_intersect_key($values, self::PLACEHOLDERS);
        $text = str_replace(["\r\n", "\r"], "\n", $template);
        $filled = (string) preg_replace_callback(
            self::PATTERN,
            static fn (array $match): string => $values[$match[1]] ?? $match[0],
            $text,
        );

        $clauses = preg_split('/\n[ \t]*\n/', $filled) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $clause): string => trim($clause), $clauses),
            static fn (string $clause): bool => $clause !== '',
        ));
    }

    /**
     * The `{name}` placeholders in the template that are not known (they
     * print as typed), for a hint on the settings page.
     *
     * @return list<string>
     */
    public static function unknownPlaceholders(string $template): array
    {
        preg_match_all(self::PATTERN, $template, $matches);

        return array_values(array_unique(array_filter(
            $matches[1],
            static fn (string $name): bool => ! array_key_exists($name, self::PLACEHOLDERS),
        )));
    }
}
