<?php

declare(strict_types=1);

namespace Tools\PHPStan\Rules;

use App\Tenancy\BelongsToOrganization;
use App\Tenancy\OrganizationScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;

/**
 * Tenancy guard (ARCHITECTURE.md § 4, AGENT.md § 3 rule 1): only the
 * Platform namespace may read across organizations. Elsewhere it is an error
 * to remove OrganizationScope, or to call withoutGlobalScopes() on an
 * organization-owned model (or one whose model cannot be told).
 *
 * @implements Rule<Node\Expr\CallLike>
 */
final class OrganizationScopeBypassRule implements Rule
{
    public const array ALLOWED_NAMESPACES = ['App\\Platform', 'Modules\\Platform'];

    public function __construct(private readonly ReflectionProvider $reflection) {}

    public function getNodeType(): string
    {
        return Node\Expr\CallLike::class;
    }

    /** @return list<IdentifierRuleError> */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! ($node instanceof MethodCall || $node instanceof StaticCall) || ! $node->name instanceof Identifier) {
            return [];
        }

        $method = $node->name->toLowerString();

        if (! in_array($method, ['withoutglobalscope', 'withoutglobalscopes'], true) || $this->allowed($scope)) {
            return [];
        }

        $args = $node->getArgs();

        if ($args !== []) {
            if (! $this->mentionsOrganizationScope($scope->getType($args[0]->value))) {
                return [];
            }
        } elseif ($method === 'withoutglobalscope' || $this->targetIsOrganizationOwned($node, $scope) === false) {
            return [];
        }

        return [
            RuleErrorBuilder::message('Organization data must stay scoped: only App\\Platform or Modules\\Platform may remove OrganizationScope (ARCHITECTURE.md § 4).')
                ->identifier('goproperty.organizationScopeBypass')
                ->build(),
        ];
    }

    private function allowed(Scope $scope): bool
    {
        $namespace = (string) $scope->getNamespace();

        foreach (self::ALLOWED_NAMESPACES as $allowed) {
            if ($namespace === $allowed || str_starts_with($namespace, $allowed.'\\')) {
                return true;
            }
        }

        return false;
    }

    /** OrganizationScope::class, an OrganizationScope instance, or an array holding either. */
    private function mentionsOrganizationScope(Type $type): bool
    {
        foreach ($type->getConstantStrings() as $string) {
            if (ltrim($string->getValue(), '\\') === OrganizationScope::class) {
                return true;
            }
        }

        if (in_array(OrganizationScope::class, $type->getObjectClassNames(), true)) {
            return true;
        }

        foreach ($type->getConstantArrays() as $array) {
            foreach ($array->getValueTypes() as $value) {
                if ($this->mentionsOrganizationScope($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** True, false, or null when the model cannot be told (counted as owned). */
    private function targetIsOrganizationOwned(MethodCall|StaticCall $node, Scope $scope): ?bool
    {
        if ($node instanceof StaticCall) {
            return $node->class instanceof Name ? $this->owned([$scope->resolveName($node->class)]) : null;
        }

        $type = $scope->getType($node->var);
        $models = [];

        foreach ($type->getObjectClassNames() as $class) {
            if (! $this->reflection->hasClass($class)) {
                continue;
            }

            $reflection = $this->reflection->getClass($class);

            if ($reflection->is(Builder::class)) {
                $models = [...$models, ...$type->getTemplateType(Builder::class, 'TModel')->getObjectClassNames()];
            } elseif ($reflection->is(Relation::class)) {
                $models = [...$models, ...$type->getTemplateType(Relation::class, 'TRelatedModel')->getObjectClassNames()];
            } elseif ($reflection->is(Model::class)) {
                $models[] = $class;
            }
        }

        return $models === [] ? null : $this->owned($models);
    }

    /** @param  list<string>  $classes */
    private function owned(array $classes): ?bool
    {
        foreach ($classes as $class) {
            if (! $this->reflection->hasClass($class)) {
                return null;
            }

            $reflection = $this->reflection->getClass($class)->getNativeReflection();

            do {
                if ($this->usesTrait($reflection->getTraitNames())) {
                    return true;
                }

                $reflection = $reflection->getParentClass();
            } while ($reflection !== false);
        }

        return false;
    }

    /** @param  array<string>  $traits */
    private function usesTrait(array $traits): bool
    {
        foreach ($traits as $trait) {
            if ($trait === BelongsToOrganization::class) {
                return true;
            }

            if ($this->reflection->hasClass($trait)
                && $this->usesTrait($this->reflection->getClass($trait)->getNativeReflection()->getTraitNames())) {
                return true;
            }
        }

        return false;
    }
}
