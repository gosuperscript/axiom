<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Monads\Option\None;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Option\Some;

/**
 * A per-call map of input values for an expression.
 *
 * Bindings hold raw values and are typically constructed fresh for each
 * expression invocation. For stable named expressions (constants, named
 * sub-expressions), use {@see Definitions} instead.
 */
final readonly class Bindings
{
    /** @var array<string, mixed> */
    private array $values;

    /**
     * @param array<string, mixed|array<string, mixed>> $bindings
     */
    public function __construct(array $bindings = [])
    {
        $values = [];

        foreach ($bindings as $key => $value) {
            if (is_array($value) && self::isAssoc($value)) {
                foreach ($value as $name => $inner) {
                    $values[$key . '.' . $name] = $inner;
                }

                continue;
            }

            $values[$key] = $value;
        }

        $this->values = $values;
    }

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

        if (! array_key_exists($key, $this->values)) {
            return new None();
        }

        return new Some($this->values[$key]);
    }

    private function key(string $name, ?string $namespace): string
    {
        return $namespace !== null ? $namespace . '.' . $name : $name;
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private static function isAssoc(array $value): bool
    {
        if ($value === []) {
            return false;
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key)) {
                return false;
            }
        }

        return true;
    }
}
