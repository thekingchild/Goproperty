<?php

declare(strict_types=1);

use App\Documents\Verification\DocumentVerification;
use App\Documents\Verification\DocumentVerifiers;
use App\Documents\Verification\VerificationCodes;
use App\Documents\Verification\VerificationResolver;
use App\Documents\Verification\VerificationStatus;
use App\Documents\Verification\VerifiedDocument;
use App\Models\Organization;
use App\Support\Money;
use App\Tenancy\MissingOrganizationContext;
use App\Tenancy\OrganizationContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Random\Engine;
use Random\Engine\Mt19937;
use Random\Engine\Secure;
use Random\Randomizer;

require_once __DIR__.'/../../Support/compliance.php';

/*
 * P03-05a: verification codes for issued documents and the registry of
 * resolvers modules plug in (BRANDING_DOCUMENTS.md § 2 item 8, § 3 item 7;
 * the /verify/{code} page itself is P03-05b).
 */

/** A resolver that answers for the codes it is given, and records the context it ran in. */
function recordingResolver(array $documents = []): VerificationResolver
{
    return new class($documents) implements VerificationResolver
    {
        /** @var list<int|null> */
        public array $contexts = [];

        public function __construct(private array $documents) {}

        public function resolve(string $code, Organization $organization): ?VerifiedDocument
        {
            $this->contexts[] = app(OrganizationContext::class)->id();

            return $this->documents[$code] ?? null;
        }
    };
}

function verifiedReceipt(string $issuer, VerificationStatus $status = VerificationStatus::Valid): VerifiedDocument
{
    return new VerifiedDocument('receipt', 'Official receipt', 'RCT-ADR-2026-000318', $issuer, CarbonImmutable::parse('2026-12-14 10:42'), $status, Money::kobo(290_000_000));
}

beforeEach(function () {
    $this->acme = Organization::factory()->create(['name' => 'Adeyemi Realty Ltd']);
    $this->other = Organization::factory()->create(['name' => 'Other Homes Ltd']);
});

afterEach(fn () => app(OrganizationContext::class)->clear());

describe('acceptance: verification codes are unique and unguessable', function () {
    it('draws 10 characters of Crockford base 32 from the secure random source', function () {
        $codes = app(VerificationCodes::class);
        $drawn = array_map(fn (): string => $codes->generate(), range(1, 2000));

        expect(array_unique($drawn))->toHaveCount(2000);

        foreach ($drawn as $code) {
            expect($code)->toMatch('/^[0-9A-HJKMNP-TV-Z]{10}$/');
        }

        // Every symbol turns up (2000 × 10 draws from 32), and none is favoured much.
        $counts = count_chars(implode('', $drawn), 1);
        expect($counts)->toHaveCount(32)
            ->and(max($counts))->toBeLessThan(2 * min($counts) + 300);

        // The default engine is the CSPRNG, not a seedable one.
        $random = (new ReflectionProperty(VerificationCodes::class, 'random'))->getValue($codes);
        expect($random->engine)->toBeInstanceOf(Secure::class);
    });

    it('says nothing about the organization, the kind or the order of issue', function () {
        $first = inOrganization($this->acme, fn () => app(VerificationCodes::class)->issue('receipt'));
        $second = inOrganization($this->acme, fn () => app(VerificationCodes::class)->issue('receipt'));
        $theirs = inOrganization($this->other, fn () => app(VerificationCodes::class)->issue('invoice'));

        expect([$first, $second, $theirs])->each->toMatch('/^[0-9A-HJKMNP-TV-Z]{10}$/')
            // Consecutive codes share no structure: at most a few characters by chance.
            ->and(similar_text($first, $second))->toBeLessThan(8)
            ->and(similar_text($second, $theirs))->toBeLessThan(8);
    });

    it('records each code once, across every kind of document, and draws again on a collision', function () {
        $seeded = fn (): VerificationCodes => new VerificationCodes(app(OrganizationContext::class), new Randomizer(new Mt19937(42)));

        $first = inOrganization($this->acme, fn () => $seeded()->issue('invoice'));
        // Same seed: the first draw collides with the code above (another kind,
        // another organization), so it draws again.
        $second = inOrganization($this->other, fn () => $seeded()->issue('receipt'));

        expect($second)->not->toBe($first)
            ->and(DB::table('document_verifications')->pluck('code')->all())->toEqualCanonicalizing([$first, $second])
            ->and(DB::table('document_verifications')->where('code', $first)->value('organization_id'))->toBe($this->acme->id)
            ->and(DB::table('document_verifications')->where('code', $second)->value('kind'))->toBe('receipt');
    });

    it('cannot store the same code twice', function () {
        inOrganization($this->acme, fn () => DocumentVerification::query()->create(['kind' => 'invoice', 'code' => '7KQ2MX9P4D']));

        inOrganization($this->other, fn () => DocumentVerification::query()->create(['kind' => 'receipt', 'code' => '7KQ2MX9P4D']));
    })->throws(UniqueConstraintViolationException::class);

    it('gives up after five collisions rather than loop', function () {
        $stuck = new VerificationCodes(app(OrganizationContext::class), new Randomizer(new class implements Engine
        {
            public function generate(): string
            {
                return "\0\0\0\0\0\0\0\0";
            }
        }));

        inOrganization($this->acme, fn () => $stuck->issue('invoice'));
        inOrganization($this->acme, fn () => $stuck->issue('invoice'));
    })->throws(RuntimeException::class, 'Could not find a free verification code.');

    it('issues codes only inside an organization, for a kind written as a key', function () {
        expect(fn () => app(VerificationCodes::class)->issue('invoice'))->toThrow(MissingOrganizationContext::class)
            ->and(fn () => inOrganization($this->acme, fn () => app(VerificationCodes::class)->issue('Invoice!')))->toThrow(InvalidArgumentException::class);
    });
});

it('reads typed and scanned codes forgivingly, and prints them in two groups', function () {
    expect(VerificationCodes::normalize('7kq2m-x9p4d'))->toBe('7KQ2MX9P4D')
        ->and(VerificationCodes::normalize(' 7KQ2M X9P4D '))->toBe('7KQ2MX9P4D')
        ->and(VerificationCodes::normalize('OIL23456789'))->toBeNull()
        ->and(VerificationCodes::normalize('OIL2345678'))->toBe('0112345678')
        ->and(VerificationCodes::normalize('7KQ2MX9P4U'))->toBeNull()
        ->and(VerificationCodes::normalize('7KQ2MX9P4'))->toBeNull()
        ->and(VerificationCodes::normalize('<script>12'))->toBeNull()
        ->and(VerificationCodes::format('7KQ2MX9P4D'))->toBe('7KQ2M-X9P4D')
        ->and(VerificationCodes::url('7kq2m-x9p4d'))->toBe(url('/verify/7KQ2MX9P4D'));
});

describe('resolver registry', function () {
    it('finds a code\'s document through the resolver of its kind, inside the issuing organization', function () {
        $code = inOrganization($this->other, fn () => app(VerificationCodes::class)->issue('receipt'));
        $resolver = recordingResolver([$code => verifiedReceipt('Other Homes')]);
        app(DocumentVerifiers::class)->register('receipt', $resolver);

        // Looked up with no organization (the public page), or while another is current.
        $found = app(DocumentVerifiers::class)->find(strtolower(VerificationCodes::format($code)));
        $whileAcme = inOrganization($this->acme, fn () => app(DocumentVerifiers::class)->find($code));

        expect($found)->toBeInstanceOf(VerifiedDocument::class)
            ->and($found->issuer)->toBe('Other Homes')
            ->and($found->amount?->kobo)->toBe(290_000_000)
            ->and($whileAcme?->number)->toBe('RCT-ADR-2026-000318')
            ->and($resolver->contexts)->toBe([$this->other->id, $this->other->id])
            ->and(app(OrganizationContext::class)->has())->toBeFalse();
    });

    it('tells a voided document from a valid one', function () {
        $code = inOrganization($this->acme, fn () => app(VerificationCodes::class)->issue('receipt'));
        app(DocumentVerifiers::class)->register('receipt', recordingResolver([$code => verifiedReceipt('Adeyemi Realty', VerificationStatus::Voided)]));

        expect(app(DocumentVerifiers::class)->find($code)?->isVoided())->toBeTrue();
    });

    it('answers null for unknown, malformed and unresolvable codes', function () {
        $receipt = inOrganization($this->acme, fn () => app(VerificationCodes::class)->issue('receipt'));
        $lease = inOrganization($this->acme, fn () => app(VerificationCodes::class)->issue('lease'));
        $resolver = recordingResolver();
        app(DocumentVerifiers::class)->register('receipt', $resolver);
        $verifiers = app(DocumentVerifiers::class);

        expect($verifiers->find('ZZZZZZZZZZ'))->toBeNull()        // never issued
            ->and($verifiers->find('not a code'))->toBeNull()       // malformed
            ->and($verifiers->find(''))->toBeNull()
            ->and($verifiers->find($lease))->toBeNull()             // no resolver for its kind
            ->and($verifiers->find($receipt))->toBeNull()           // the module no longer knows it
            ->and($resolver->contexts)->toBe([$this->acme->id]);
    });

    it('resolves a class name through the container, once, and lists the kinds', function () {
        $code = inOrganization($this->acme, fn () => app(VerificationCodes::class)->issue('invoice'));
        app()->bind(FakeInvoiceVerification::class, fn () => new FakeInvoiceVerification);
        app(DocumentVerifiers::class)->register('invoice', FakeInvoiceVerification::class);

        $verifiers = app(DocumentVerifiers::class);

        expect($verifiers->find($code)?->title)->toBe('Invoice')
            ->and($verifiers->resolver('invoice'))->toBe($verifiers->resolver('invoice'))
            ->and($verifiers->has('invoice'))->toBeTrue()
            ->and($verifiers->has('receipt'))->toBeFalse()
            // Modules register their own kinds too (the Leasing module's `lease_contract`, P03-03b).
            ->and($verifiers->kinds())->toContain('invoice');
    });

    it('takes registrations from module providers, made when the registry is first resolved', function () {
        app()->forgetInstance(DocumentVerifiers::class);
        app()->afterResolving(DocumentVerifiers::class, fn (DocumentVerifiers $verifiers) => $verifiers->register('statement', fn () => recordingResolver()));

        expect(app(DocumentVerifiers::class)->kinds())->toContain('statement')
            ->and(app(DocumentVerifiers::class))->toBe(app(DocumentVerifiers::class));
    });

    it('refuses a second resolver for the same kind, and an unknown kind', function () {
        app(DocumentVerifiers::class)->register('receipt', recordingResolver());

        expect(fn () => app(DocumentVerifiers::class)->register('receipt', recordingResolver()))->toThrow(LogicException::class)
            ->and(fn () => app(DocumentVerifiers::class)->resolver('invoice'))->toThrow(InvalidArgumentException::class);
    });
});

final class FakeInvoiceVerification implements VerificationResolver
{
    public function resolve(string $code, Organization $organization): ?VerifiedDocument
    {
        return new VerifiedDocument('invoice', 'Invoice', 'INV-ADR-2027-000142', $organization->name, CarbonImmutable::now(), VerificationStatus::Valid);
    }
}
