<?php

declare(strict_types=1);

namespace Tamiroh\Phmake\PHPStan;

use Override;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\ClassNameUsageLocation;
use PHPStan\Rules\RestrictedUsage\RestrictedClassNameUsageExtension;
use PHPStan\Rules\RestrictedUsage\RestrictedUsage;

use function str_starts_with;
use function strrpos;
use function strtolower;
use function substr;

/**
 * A\Internal\B is visible only inside A and its descendants.
 * The innermost Internal segment defines the boundary. Tests get no exemption.
 * Vendor namespaces are outside this project's policy.
 */
final readonly class InternalNamespaceExtension implements RestrictedClassNameUsageExtension
{
    #[Override]
    public function isRestrictedClassNameUsage(
        ClassReflection $classReflection,
        Scope $scope,
        ClassNameUsageLocation $location,
    ): ?RestrictedUsage {
        $name = $classReflection->getName();
        $normalized = strtolower($name);
        if (!str_starts_with($normalized, 'tamiroh\\phmake\\')) {
            return null;
        }
        $boundary = strrpos($normalized, '\\internal\\');
        if ($boundary === false) {
            return null;
        }
        $owner = substr($normalized, 0, $boundary);
        $namespace = strtolower($scope->getNamespace() ?? '');
        if ($namespace === $owner || str_starts_with($namespace, $owner . '\\')) {
            return null;
        }
        $displayOwner = substr($name, 0, $boundary);
        return RestrictedUsage::create(
            $location->createMessage("internal class {$name}")
                . " Only {$displayOwner} and its subnamespaces may use it.",
            'architecture.internalNamespace',
        );
    }
}
