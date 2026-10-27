<?php

declare(strict_types=1);

use App\Banking\AccountNameMatcher;
use App\Branding\BrandColours;
use App\Branding\Branding;
use App\Theme\Color;

/*
 * P01-05: the colour contrast rules of agency branding (BRANDING_DOCUMENTS.md
 * § 1) and the bank account-name comparison (ONBOARDING_COMPLIANCE.md § 4.1).
 */

it('rejects a primary colour below 3:1 against white and accepts darker ones', function (string $colour, bool $rejected) {
    expect(array_key_exists('primaryColor', BrandColours::problems($colour, null)))->toBe($rejected);
})->with([
    'yellow' => ['#FFE600', true],
    'sky 300' => ['#7DD3FC', true],
    'sky 500' => ['#0EA5E9', true],
    'white' => ['#FFFFFF', true],
    'template teal' => ['#0E6B5C', false],
    'cyan 600 (3–4.5:1)' => ['#0891B2', false],
    'navy' => ['#1E3A8A', false],
    'black' => ['#000000', false],
]);

it('rejects an accent too close to the primary', function () {
    expect(BrandColours::problems('#0E6B5C', '#0F766E'))->toHaveKey('accentColor')
        ->and(BrandColours::problems('#0E6B5C', '#F2B53A'))->toBe([])
        ->and(BrandColours::problems('#0E6B5C', null))->toBe([])
        ->and(BrandColours::problems('#0E6B5C', 'teal'))->toHaveKey('accentColor');
});

it('says how far below the minimum a colour is', function () {
    expect(BrandColours::problems('#FFE600', null)['primaryColor'])
        ->toContain('#FFE600 is too light')
        ->toContain('at least 3.0:1');
});

it('uses a darker shade of the same colour for text when white on it is under 4.5:1', function () {
    $shade = BrandColours::textShade('#0891B2');

    expect($shade)->not->toBe('#0891b2')
        ->and(Color::contrast($shade, '#ffffff'))->toBeGreaterThanOrEqual(4.5)
        ->and(BrandColours::textShade('#0E6B5C'))->toBe('#0e6b5c');
});

it('derives the document number code from the organization name', function () {
    expect(Branding::defaultCode('Adeyemi Realty Ltd'))->toBe('ADR')
        ->and(Branding::defaultCode('Okonkwo'))->toBe('OKO')
        ->and(Branding::defaultCode('Ìlé Homes Nigeria Limited'))->toBe('ILH');
});

it('matches bank account names regardless of case, order, punctuation and company suffix', function (string $given, string $bank, bool $matches) {
    expect((new AccountNameMatcher)->matches($given, $bank))->toBe($matches);
})->with([
    'suffix' => ['Adeyemi Realty', 'ADEYEMI REALTY LTD', true],
    'limited vs ltd' => ['Adeyemi Realty Limited', 'ADEYEMI REALTY LTD.', true],
    'word order' => ['Chiamaka Okafor', 'OKAFOR CHIAMAKA ADA', true],
    'ampersand' => ['Bello & Sons', 'BELLO AND SONS LTD', true],
    'cut short by the bank' => ['Adeyemi Realty and Property Management Services', 'ADEYEMI REALTY AND PROPERTY MANAGEM', true],
    'other company' => ['Adeyemi Realty', 'ADEYEMI PROPERTIES LTD', false],
    'other person' => ['Chiamaka Okafor', 'TUNDE BAKARE', false],
    'initial-like prefixes are not enough' => ['Ade Ola', 'ADEBAYO OLATUNDE', false],
    'one word needs an exact match' => ['Adeyemi', 'ADEYEMI REALTY', false],
    'empty' => ['', 'ADEYEMI REALTY', false],
]);
