<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use InvalidArgumentException;
use Superscript\Monads\Option\Option;

/**
 * A map of stable, root-named expressions. Structure belongs in the value a
 * definition returns and is reached through member access; dotted namespace
 * keys are not a second naming system.
 */
final readonly class Definitions
{
    /** @var array<string, Source> */
    private array $entries;

    /** @param array<string, Source> $definitions */
    public function __construct(array $definitions = [])
    {
        foreach ($definitions as $name => $definition) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('Every definition must have a non-empty root symbol name without dots.');
            }

            if ($name === '' || str_contains($name, '.')) {
                throw new InvalidArgumentException('Every definition must have a non-empty root symbol name without dots.');
            }

            if (!$definition instanceof Source) {
                throw new InvalidArgumentException('Every definition must be a Source instance.');
            }
        }

        $this->entries = $definitions;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->entries);
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->entries);
    }

    /** @return Option<Source> */
    public function get(string $name): Option
    {
        return Option::from($this->entries[$name] ?? null);
    }
}
