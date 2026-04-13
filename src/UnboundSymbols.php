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
        $seen = [];

        self::walk($source, $symbols, $seen);

        return $symbols;
    }

    /**
     * @param list<SymbolSource> $symbols
     * @param array<string, true> $seen
     */
    private static function walk(mixed $node, array &$symbols, array &$seen): void
    {
        if ($node instanceof SymbolSource) {
            $key = ($node->namespace ?? '') . "\0" . $node->name;

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $symbols[] = $node;
            }

            return;
        }

        if (is_array($node)) {
            foreach ($node as $child) {
                self::walk($child, $symbols, $seen);
            }

            return;
        }

        if (! ($node instanceof Source || $node instanceof MatchPattern || $node instanceof MatchArm)) {
            return;
        }

        $reflection = new ReflectionObject($node);

        foreach ($reflection->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            self::walk($property->getValue($node), $symbols, $seen);
        }
    }
}
