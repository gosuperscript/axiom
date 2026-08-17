<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use ReflectionObject;
use ReflectionProperty;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchPattern;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\SymbolSource;

/**
 * Walks a {@see Source} tree and collects every rooted {@see ReferencePath}
 * waiting for a {@see Bindings} or {@see Definitions} entry to resolve it.
 * Deprecated symbol/member chains are normalized while they remain readable.
 */
final class UnboundSymbols
{
    /**
     * @return list<ReferencePath>
     */
    public static function in(Source $source): array
    {
        $symbols = [];

        self::walk($source, $symbols, []);

        return $symbols;
    }

    /**
     * @param list<ReferencePath> $symbols
     * @param list<string> $bound
     */
    private static function walk(mixed $node, array &$symbols, array $bound): void
    {
        $reference = match (true) {
            $node instanceof ReferencePath => $node,
            $node instanceof SymbolSource => $node->reference(),
            $node instanceof MemberAccessSource => self::legacyReferencePath($node),
            default => null,
        };

        if ($reference !== null) {
            if (!in_array($reference->root(), $bound, true) && !self::contains($symbols, $reference)) {
                $symbols[] = $reference;
            }

            return;
        }

        if ($node instanceof ScopedExpression) {
            self::walk($node->body, $symbols, [...$bound, ...$node->parameters]);

            return;
        }

        if (is_array($node)) {
            foreach ($node as $child) {
                self::walk($child, $symbols, $bound);
            }

            return;
        }

        if (! ($node instanceof Source || $node instanceof MatchPattern || $node instanceof MatchArm)) {
            return;
        }

        $reflection = new ReflectionObject($node);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            self::walk($property->getValue($node), $symbols, $bound);
        }
    }

    /**
     * @param list<ReferencePath> $symbols
     */
    private static function contains(array $symbols, ReferencePath $needle): bool
    {
        foreach ($symbols as $existing) {
            if ($existing->key() === $needle->key()) {
                return true;
            }
        }

        return false;
    }

    private static function legacyReferencePath(MemberAccessSource $source): ?ReferencePath
    {
        $properties = [];
        $current = $source;

        while ($current instanceof MemberAccessSource) {
            array_unshift($properties, $current->property);
            $current = $current->object;
        }

        if (!$current instanceof SymbolSource) {
            return null;
        }

        $reference = $current->reference();

        foreach ($properties as $property) {
            $reference = $reference->append($property);
        }

        return $reference;
    }
}
