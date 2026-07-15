<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Monads\Option\None;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Option\Some;

/**
 * A per-call map of input values for an expression.
 *
 * Bindings answer exact keys only: a namespaced lookup is the flat dotted
 * key ('customer.turnover'), and nothing ever digs into a binding's value
 * to answer a symbol lookup — a namespace is a naming convention, not a
 * view into structure. Reaching into a record value is member access, an
 * explicit Source node with its own static judgment.
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

    public function has(string $name, ?string $namespace = null): bool
    {
        return array_key_exists($this->key($name, $namespace), $this->values);
    }

    /**
     * @return Option<mixed>
     */
    public function get(string $name, ?string $namespace = null): Option
    {
        $key = $this->key($name, $namespace);

        if (array_key_exists($key, $this->values)) {
            return new Some($this->values[$key]);
        }

        return new None();
    }

    private function key(string $name, ?string $namespace): string
    {
        return $namespace !== null ? $namespace . '.' . $name : $name;
    }
}
