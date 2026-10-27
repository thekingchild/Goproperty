<?php

declare(strict_types=1);

namespace App\Documents\Verification;

use App\Models\Organization;
use App\Tenancy\OrganizationContext;
use Closure;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The verification resolvers of every kind of document (ARCHITECTURE.md
 * § 6.5). Core knows no documents: each module registers a resolver for
 * its kinds from its service provider, e.g.
 *
 *     $this->app->callAfterResolving(DocumentVerifiers::class,
 *         fn (DocumentVerifiers $verifiers) => $verifiers->register('invoice', BillVerification::class));
 *
 * find() is what /verify/{code} (P03-05b) calls, with no organization: one
 * unscoped read of `document_verifications` by the unique code gives the
 * organization and the kind (the P01-04 invitation pattern, no scope
 * removed), then the kind's resolver runs inside that organization.
 * Unknown, malformed and unresolvable codes all give null.
 */
final class DocumentVerifiers
{
    /** @var array<string, VerificationResolver|class-string<VerificationResolver>|Closure(): VerificationResolver> */
    private array $resolvers = [];

    // The context is scoped (fresh per request and job), so it is looked up
    // when needed rather than kept by this singleton.
    public function __construct(private readonly Container $container) {}

    /** @param  VerificationResolver|class-string<VerificationResolver>|Closure(): VerificationResolver  $resolver */
    public function register(string $kind, VerificationResolver|string|Closure $resolver): void
    {
        if (isset($this->resolvers[$kind])) {
            throw new LogicException("A verification resolver for \"{$kind}\" is already registered.");
        }

        $this->resolvers[$kind] = $resolver;
    }

    public function has(string $kind): bool
    {
        return isset($this->resolvers[$kind]);
    }

    /** @return list<string> */
    public function kinds(): array
    {
        return array_keys($this->resolvers);
    }

    public function resolver(string $kind): VerificationResolver
    {
        $resolver = $this->resolvers[$kind] ?? throw new InvalidArgumentException("No verification resolver for \"{$kind}\".");

        if ($resolver instanceof Closure) {
            $resolver = $resolver();
        } elseif (is_string($resolver)) {
            $resolver = $this->container->make($resolver);
        }

        if (! $resolver instanceof VerificationResolver) {
            throw new LogicException("The verification resolver for \"{$kind}\" does not implement ".VerificationResolver::class.'.');
        }

        return $this->resolvers[$kind] = $resolver;
    }

    /** The document behind a code as typed or scanned, or null. */
    public function find(string $input): ?VerifiedDocument
    {
        $code = VerificationCodes::normalize($input);

        if ($code === null) {
            return null;
        }

        $row = DB::table('document_verifications')->where('code', $code)->first(['organization_id', 'kind']);

        if ($row === null || ! is_numeric($row->organization_id) || ! is_string($row->kind) || ! $this->has($row->kind)) {
            return null;
        }

        $organization = Organization::query()->find((int) $row->organization_id);

        if (! $organization instanceof Organization) {
            return null;
        }

        $resolver = $this->resolver($row->kind);

        return $this->container->make(OrganizationContext::class)->run($organization, fn (): ?VerifiedDocument => $resolver->resolve($code, $organization));
    }
}
