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
        return array_map(
            static fn(array $cycle) => new TypeMismatch(sprintf('Cyclic symbol definition: %s.', implode(' → ', $cycle))),
            self::detect($definitions),
        );
    }

    /**
     * Every definition name that lies on a cycle. Such a name can never
     * compile — following its edges is the non-termination {@see cycles()}
     * reports — so a caller that keeps compiling past that refusal has here
     * exactly the names it must not descend into.
     *
     * @return list<string>
     */
    public static function cyclicKeys(Definitions $definitions): array
    {
        $keys = [];

        foreach (self::detect($definitions) as $cycle) {
            foreach ($cycle as $key) {
                $keys[$key] = $key;
            }
        }

        return array_values($keys);
    }

    /**
     * Every distinct cycle, as the names on it, closed: `['a', 'b', 'a']`.
     *
     * @return list<non-empty-list<string>>
     */
    private static function detect(Definitions $definitions): array
    {
        $cycles = [];
        $explored = [];

        foreach ($definitions->keys() as $key) {
            self::visit($key, $definitions, [], $explored, $cycles);
        }

        return $cycles;
    }

    /**
     * Depth-first walk: a key already on the current path is a cycle; a key
     * fully explored on an earlier walk cannot start a new one.
     *
     * @param list<string> $path
     * @param array<string, true> $explored
     * @param list<non-empty-list<string>> $cycles
     */
    private static function visit(string $key, Definitions $definitions, array $path, array &$explored, array &$cycles): void
    {
        $position = array_search($key, $path, strict: true);

        if ($position !== false) {
            $cycles[] = [...array_slice($path, $position), $key];

            return;
        }

        if ($explored[$key] ?? false) {
            return;
        }

        // Keys come from the definitions themselves, so the source exists.
        $source = $definitions->get($key)->unwrap();

        foreach (UnboundSymbols::in($source) as $reference) {
            $referenced = SymbolSource::key($reference->name, $reference->namespace);

            // References that are not definitions are parameters — leaves of
            // the graph, satisfied by bindings, never edges.
            if ($definitions->has($referenced)) {
                self::visit($referenced, $definitions, [...$path, $key], $explored, $cycles);
            }
        }

        $explored[$key] = true;
    }
}
