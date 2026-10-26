<?php

declare(strict_types=1);

namespace Tests\Unit\PHPStan;

use Illuminate\Foundation\Bootstrap\HandleExceptions;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use Tools\PHPStan\Rules\OrganizationScopeBypassRule;

/**
 * P00-07 acceptance (static half): Larastan rejects removing the
 * organization scope outside the Platform namespace (ARCHITECTURE.md § 4).
 *
 * @extends RuleTestCase<OrganizationScopeBypassRule>
 */
final class OrganizationScopeBypassRuleTest extends RuleTestCase
{
    private const string MESSAGE = 'Organization data must stay scoped: only App\Platform or Modules\Platform may remove OrganizationScope (ARCHITECTURE.md § 4).';

    protected function getRule(): Rule
    {
        return new OrganizationScopeBypassRule($this->createReflectionProvider());
    }

    /** @return list<string> */
    public static function getAdditionalConfigFiles(): array
    {
        return [dirname(__DIR__, 3).'/vendor/larastan/larastan/extension.neon'];
    }

    /** Larastan boots a Laravel app inside PHPStan, which installs error handlers. */
    protected function tearDown(): void
    {
        HandleExceptions::flushState($this);

        parent::tearDown();
    }

    public function test_it_rejects_scope_bypasses_outside_the_platform_namespace(): void
    {
        $this->analyse([__DIR__.'/data/scope-bypass.php'], [
            [self::MESSAGE, 13],
            [self::MESSAGE, 14],
            [self::MESSAGE, 15],
            [self::MESSAGE, 16],
            [self::MESSAGE, 17],
            [self::MESSAGE, 18],
            [self::MESSAGE, 19],
            [self::MESSAGE, 20],
            [self::MESSAGE, 62],
        ]);
    }
}
