<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use ReflectionObject;
use ReflectionProperty;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchPattern;
use Superscript\Axiom\Sources\SymbolSource;

/**
 * Walks a {@see Source} tree and collects every {@see SymbolSource} it
 * references — i.e. the symbols that are not yet bound to a value and are
 * waiting for a {@see Bindings} or {@see Definitions} entry to resolve them.
 */
final class UnboundSymbols
{
    /**
     * @return list<SymbolSource>
     */
    public static function in(Source $source): array
    {
        $symbols = [];

        self::walk($source, $symbols);

        return $symbols;
    }

    /**
     * @param list<SymbolSource> $symbols
     */
    private static function walk(mixed $node, array &$symbols): void
    {
        if ($node instanceof SymbolSource) {
            if (! self::contains($symbols, $node)) {
                $symbols[] = $node;
            }

            return;
        }

        if (is_array($node)) {
            foreach ($node as $child) {
                self::walk($child, $symbols);
            }

            return;
        }

        if (! ($node instanceof Source || $node instanceof MatchPattern || $node instanceof MatchArm)) {
            return;
        }

        $reflection = new ReflectionObject($node);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            self::walk($property->getValue($node), $symbols);
        }
    }

    /**
     * @param list<SymbolSource> $symbols
     */
    private static function contains(array $symbols, SymbolSource $needle): bool
    {
        foreach ($symbols as $existing) {
            if ($existing->name === $needle->name && $existing->namespace === $needle->namespace) {
                return true;
            }
        }

        return false;
    }
}
