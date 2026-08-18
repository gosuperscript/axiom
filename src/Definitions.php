<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use InvalidArgumentException;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Monads\Option\Option;

/**
 * A map of stable, root-named expressions. New definitions have root names;
 * deprecated nested maps and dotted keys remain readable while persisted
 * SymbolSource values migrate to ReferencePath.
 */
final readonly class Definitions
{
    /** @var array<string, Source> */
    private array $entries;

    /** @param array<string, Source|array<string, Source>> $definitions */
    public function __construct(array $definitions = [])
    {
        $entries = [];

        foreach ($definitions as $name => $definition) {
            if (!is_string($name) || $name === '') {
                throw new InvalidArgumentException('Every definition must have a non-empty name.');
            }

            if ($definition instanceof Source) {
                $entries[$name] = $definition;

                continue;
            }

            if (is_array($definition)) {
                foreach ($definition as $nestedName => $nestedDefinition) {
                    if (!is_string($nestedName) || $nestedName === '' || !$nestedDefinition instanceof Source) {
                        throw new InvalidArgumentException('Every nested definition must have a non-empty name and a Source value.');
                    }

                    $entries[SymbolSource::key($nestedName, $name)] = $nestedDefinition;
                }

                continue;
            }

            throw new InvalidArgumentException('Every definition must be a Source instance or a nested map of Sources.');
        }

        $this->entries = $entries;
    }

    public function has(string $name, ?string $namespace = null): bool
    {
        return array_key_exists(SymbolSource::key($name, $namespace), $this->entries);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->entries);
    }

    /** @return Option<Source> */
    public function get(string $name, ?string $namespace = null): Option
    {
        return Option::from($this->entries[SymbolSource::key($name, $namespace)] ?? null);
    }

    /** The definition prefix consumed by a structural reference, if any. */
    public function keyOf(ReferencePath $reference): ?string
    {
        foreach (array_reverse(array_keys($reference->segments)) as $index) {
            $key = implode('.', array_slice($reference->segments, 0, $index + 1));

            if ($this->has($key)) {
                return $key;
            }
        }

        return null;
    }
}
