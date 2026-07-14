<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Monads\Option\None;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Option\Some;

/**
 * A per-call map of input values for an expression.
 *
 * Bindings store what they are given — descent, not flattening. An array
 * binding binds its key whole (a record value: coercible at the boundary,
 * member-accessible), and a namespaced lookup descends one level into it,
 * so ['customer' => ['turnover' => 600000]] answers both 'customer' and
 * SymbolSource('turnover', 'customer'). An explicit dotted key
 * ('customer.turnover') wins over descent. A namespace is the record view
 * of a binding — one value, both readings.
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
        if (array_key_exists($this->key($name, $namespace), $this->values)) {
            return true;
        }

        return $namespace !== null
            && is_array($this->values[$namespace] ?? null)
            && array_key_exists($name, $this->values[$namespace]);
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

        if ($namespace !== null && is_array($this->values[$namespace] ?? null) && array_key_exists($name, $this->values[$namespace])) {
            return new Some($this->values[$namespace][$name]);
        }

        return new None();
    }

    private function key(string $name, ?string $namespace): string
    {
        return $namespace !== null ? $namespace . '.' . $name : $name;
    }
}
