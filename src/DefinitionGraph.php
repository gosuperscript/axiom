<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Types\TypeMismatch;

/**
 * Well-foundedness of the definition graph, checked as a graph property.
 *
 * This is deliberately not a typing feature: the runtime follows definition
 * edges whenever a binding is absent, so termination is a property of the
 * {@see Definitions} alone. Declarations answer the typing question and
 * must never terminate the cycle walk — an in-progress set inside
 * inference misses exactly the cycles a declaration short-circuit
 * truncates, self-cycles and mutual cycles alike.
 */
final class DefinitionGraph
{
    /**
     * Every distinct definition cycle, named: "a → b → a".
     *
     * @return list<TypeMismatch>
     */
    public static function cycles(Definitions $definitions): array
    {
        $mismatches = [];
        $explored = [];

        foreach ($definitions->keys() as $key) {
            self::visit($key, $definitions, [], $explored, $mismatches);
        }

        return $mismatches;
    }

    /**
     * Depth-first walk: a key already on the current path is a cycle; a key
     * fully explored on an earlier walk cannot start a new one.
     *
     * @param list<string> $path
     * @param array<string, true> $explored
     * @param list<TypeMismatch> $mismatches
     */
    private static function visit(string $key, Definitions $definitions, array $path, array &$explored, array &$mismatches): void
    {
        $position = array_search($key, $path, strict: true);

        if ($position !== false) {
            $mismatches[] = new TypeMismatch(sprintf(
                'Cyclic symbol definition: %s.',
                implode(' → ', [...array_slice($path, $position), $key]),
            ));

            return;
        }

        if (isset($explored[$key])) {
            return;
        }

        // Keys come from the definitions themselves, so the source exists.
        $source = $definitions->get($key)->unwrap();

        foreach (UnboundSymbols::in($source) as $reference) {
            $referenced = self::key($reference);

            // References that are not definitions are parameters — leaves of
            // the graph, satisfied by bindings, never edges.
            if ($definitions->has($referenced)) {
                self::visit($referenced, $definitions, [...$path, $key], $explored, $mismatches);
            }
        }

        $explored[$key] = true;
    }

    private static function key(SymbolSource $symbol): string
    {
        return $symbol->namespace !== null
            ? $symbol->namespace . '.' . $symbol->name
            : $symbol->name;
    }
}
