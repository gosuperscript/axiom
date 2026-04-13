<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use ReflectionObject;
use ReflectionProperty;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchPattern;
use Superscript\Axiom\Sources\SymbolSource;

/**
 * Walks a {@see Source} tree and collects all {@see SymbolSource} references
 * (the "free variables" of the expression).
 */
final class FreeVariables
{
    /**
     * @return list<array{name: string, namespace: ?string}>
     */
    public static function of(Source $source): array
    {
        $variables = [];
        $seen = [];

        self::walk($source, $variables, $seen);

        return $variables;
    }

    /**
     * @param list<array{name: string, namespace: ?string}> $variables
     * @param array<string, true> $seen
     */
    private static function walk(mixed $node, array &$variables, array &$seen): void
    {
        if ($node instanceof SymbolSource) {
            $key = ($node->namespace ?? '') . "\0" . $node->name;

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $variables[] = ['name' => $node->name, 'namespace' => $node->namespace];
            }

            return;
        }

        if (is_array($node)) {
            foreach ($node as $child) {
                self::walk($child, $variables, $seen);
            }

            return;
        }

        if (! ($node instanceof Source || $node instanceof MatchPattern || $node instanceof MatchArm)) {
            return;
        }

        $reflection = new ReflectionObject($node);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            self::walk($property->getValue($node), $variables, $seen);
        }
    }
}
