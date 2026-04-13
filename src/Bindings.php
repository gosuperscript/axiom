<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Types\Type;
use Superscript\Monads\Option\None;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Option\Some;

/**
 * A per-call map of input values for an expression.
 *
 * Bindings hold raw values and are typically constructed fresh for each
 * expression invocation. For stable named expressions (constants, named
 * sub-expressions), use {@see Definitions} instead.
 *
 * Optionally, bindings carry a **schema** — a map of declared parameter
 * {@see Type}s — so pre-execution type checking can validate that the
 * expression body is consistent with the caller's declared inputs.
 */
final readonly class Bindings
{
    /** @var array<string, mixed> */
    private array $values;

    /** @var array<string, Type> */
    private array $schema;

    /**
     * @param array<string, mixed|array<string, mixed>> $bindings
     * @param array<string, Type|array<string, Type>> $schema
     */
    public function __construct(array $bindings = [], array $schema = [])
    {
        $this->values = self::flattenBindings($bindings);
        $this->schema = self::flattenSchema($schema);
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

    /**
     * Declared type of a parameter, or null if none was declared.
     */
    public function typeOf(string $name, ?string $namespace = null): ?Type
    {
        return $this->schema[$this->key($name, $namespace)] ?? null;
    }

    private function key(string $name, ?string $namespace): string
    {
        return $namespace !== null ? $namespace . '.' . $name : $name;
    }

    /**
     * @param array<string, mixed|array<string, mixed>> $input
     * @return array<string, mixed>
     */
    private static function flattenBindings(array $input): array
    {
        $out = [];

        foreach ($input as $key => $value) {
            if (is_array($value) && self::isAssoc($value)) {
                foreach ($value as $name => $inner) {
                    $out[$key . '.' . $name] = $inner;
                }
                continue;
            }

            $out[$key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, Type|array<string, Type>> $input
     * @return array<string, Type>
     */
    private static function flattenSchema(array $input): array
    {
        $out = [];

        foreach ($input as $key => $value) {
            if ($value instanceof Type) {
                $out[$key] = $value;
                continue;
            }

            foreach ($value as $name => $inner) {
                $out[$key . '.' . $name] = $inner;
            }
        }

        return $out;
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
