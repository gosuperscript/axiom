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
     * Every distinct definition cycle, named: "a → b → a". One witness per
     * cycle the walk closes — enough to name the damage; {@see cyclicKeys()}
     * is the complete set of names that lie on one.
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
     * Lying on a cycle is a question about strongly connected components,
     * not about a walk. In `a → b, b → a, a → c, c → b`, the cycle
     * `a → c → b → a` shares both its other names with `a → b → a`, so a
     * depth-first walk that stops at names an earlier branch already
     * finished never reads `c` as cyclic — yet descending into `c` does not
     * terminate. The three names form one strongly connected component, and
     * every name in a component of more than one name lies on a cycle, as
     * does a name that refers to itself.
     *
     * The names come back in definition order, so the set a caller refuses
     * to descend into reads the same way the definitions do.
     *
     * @return list<string>
     */
    public static function cyclicKeys(Definitions $definitions): array
    {
        $edges = self::edges($definitions);
        $cyclic = [];

        foreach (self::stronglyConnected($edges) as $component) {
            if (count($component) === 1 && !in_array($component[0], $edges[$component[0]], strict: true)) {
                continue;
            }

            foreach ($component as $key) {
                $cyclic[$key] = $key;
            }
        }

        return array_values(array_filter($definitions->keys(), static fn(string $key) => isset($cyclic[$key])));
    }

    /**
     * The definition graph as an adjacency list over every definition name.
     *
     * References that are not definitions are parameters — leaves of the
     * graph, satisfied by bindings, never edges.
     *
     * @return array<string, list<string>>
     */
    private static function edges(Definitions $definitions): array
    {
        $edges = [];

        foreach ($definitions->keys() as $key) {
            $edges[$key] = [];

            // Keys come from the definitions themselves, so the source exists.
            foreach (UnboundSymbols::in($definitions->get($key)->unwrap()) as $reference) {
                $referenced = SymbolSource::key($reference->name, $reference->namespace);

                if ($definitions->has($referenced) && !in_array($referenced, $edges[$key], strict: true)) {
                    $edges[$key][] = $referenced;
                }
            }
        }

        return $edges;
    }

    /**
     * Tarjan's strongly connected components, one depth-first pass with an
     * explicit frame stack — a definition graph is host data and can nest
     * deeply enough to exhaust the PHP call stack, so the recursion is
     * carried in an array instead.
     *
     * Each vertex takes a discovery number; a vertex's low-link is the
     * smallest discovery number reachable from it without leaving the
     * current search stack. A vertex whose low-link is still its own
     * discovery number is the root of a component, and the component is
     * everything above it on the search stack.
     *
     * @param array<string, list<string>> $edges
     * @return list<non-empty-list<string>>
     */
    private static function stronglyConnected(array $edges): array
    {
        /** @var array<string, int> $discovered */
        $discovered = [];
        /** @var array<string, int> $lowLink */
        $lowLink = [];
        /** @var array<string, string> $searching The vertices on the search stack, as a set. */
        $searching = [];
        /** @var list<string> $search */
        $search = [];
        $components = [];

        foreach (array_keys($edges) as $root) {
            if (isset($discovered[$root])) {
                continue;
            }

            // One vertex is numbered per entry in $discovered, so its size is
            // the number the next vertex takes.
            $discovered[$root] = $lowLink[$root] = count($discovered);
            $search[] = $root;
            $searching[$root] = $root;
            /** @var list<array{string, int}> $frames */
            $frames = [[$root, 0]];

            while ($frames !== []) {
                $top = count($frames) - 1;
                [$key, $cursor] = $frames[$top];

                if ($cursor < count($edges[$key])) {
                    $frames[$top][1] = $cursor + 1;
                    $referenced = $edges[$key][$cursor];

                    if (!isset($discovered[$referenced])) {
                        $discovered[$referenced] = $lowLink[$referenced] = count($discovered);
                        $search[] = $referenced;
                        $searching[$referenced] = $referenced;
                        $frames[] = [$referenced, 0];
                    } elseif (isset($searching[$referenced])) {
                        $lowLink[$key] = min($lowLink[$key], $discovered[$referenced]);
                    }

                    continue;
                }

                array_pop($frames);

                if ($lowLink[$key] === $discovered[$key]) {
                    $component = [];

                    do {
                        $member = array_pop($search);
                        // The search stack holds this component's root and
                        // everything discovered after it, so it is non-empty.
                        assert($member !== null);
                        unset($searching[$member]);
                        $component[] = $member;
                    } while ($member !== $key);

                    $components[] = $component;
                }

                if ($frames !== []) {
                    $parent = $frames[count($frames) - 1][0];
                    $lowLink[$parent] = min($lowLink[$parent], $lowLink[$key]);
                }
            }
        }

        return $components;
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
