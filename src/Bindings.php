<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Monads\Option\None;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Option\Some;

/**
 * A per-call map of input values for an expression.
 *
 * Bindings answer root symbols. Reaching into a record value is structural
 * member access, represented by an explicit Source node and ReferencePath.
 *
 * Bindings hold raw values and are typically constructed fresh for each
 * expression invocation. For stable named expressions (constants, named
 * sub-expressions), use {@see Definitions} instead.
 */
final readonly class Bindings
{
    /**
     * @param array<string, mixed> $values
     */
    public function __construct(private array $values = []) {}

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->values);
    }

    /**
     * @return Option<mixed>
     */
    public function get(string $name): Option
    {
        if (array_key_exists($name, $this->values)) {
            return new Some($this->values[$name]);
        }

        return new None();
    }
}
