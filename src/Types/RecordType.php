<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use InvalidArgumentException;
use Superscript\Axiom\Exceptions\RecordPropertyViolation;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\ReferencePath;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\RecordShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * Named properties, exact. Properties are required by default; wrapping a
 * declaration in {@see Optional} permits its key to be omitted without
 * changing the type a supplied value must inhabit.
 *
 * Property presence and value absence are independent:
 *
 *  - `T` requires a key whose value inhabits `T`;
 *  - `Option<T>` requires a key whose value may be absent;
 *  - `Optional(T)` permits omission but rejects an explicitly absent value;
 *  - `Optional(Option<T>)` permits either omission or an absent value.
 *
 * Missing optional keys remain missing. Member access observes omission as
 * `Option<T>`, while retaining the distinction at admission so an omitted
 * `Optional(T)` is legal and an explicitly null one is not.
 *
 * Records are exact under {@see assert()}: undeclared keys are rejected.
 * {@see coerce()} deliberately accepts wider input and retains only this
 * record's declared slice. A Program likewise slices its projected input
 * paths before applying its selected boundary policy.
 *
 * @implements Type<array<array-key, mixed>>
 */
final readonly class RecordType implements Type
{
    /** @var array<string, RecordProperty> */
    public array $properties;

    /**
     * @param array<string, Type|Optional> $properties
     */
    public function __construct(array $properties = [])
    {
        foreach (array_keys($properties) as $name) {
            if (!is_string($name)) {
                throw new InvalidArgumentException('Every record property must have a non-empty name without dots.');
            }

            if ($name === '' || str_contains($name, '.')) {
                throw new InvalidArgumentException('Every record property must have a non-empty name without dots.');
            }
        }

        $this->properties = array_map(
            static fn(Type|Optional $property): RecordProperty => $property instanceof Optional
                ? new RecordProperty($property->type, true)
                : new RecordProperty($property, false),
            $properties,
        );
    }

    public function has(string $name): bool
    {
        return isset($this->properties[$name]);
    }

    public function property(string $name): ?RecordProperty
    {
        return $this->properties[$name] ?? null;
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_keys($this->properties);
    }

    /**
     * The smallest record containing the properties the compiled program
     * reads. A root reference retains its complete property type; a nested
     * reference projects nested concrete records recursively.
     *
     * @param list<ReferencePath> $references
     */
    public function project(array $references): self
    {
        return $this->projectSegments(array_map(
            static fn(ReferencePath $reference): array => $reference->segments,
            $references,
        ));
    }

    public function assert(mixed $value): Result
    {
        if (!is_array($value)) {
            return new Err(new TransformValueException(type: 'record', value: $value));
        }

        foreach (array_keys($value) as $key) {
            if (!isset($this->properties[$key])) {
                return new Err(new InvalidArgumentException(sprintf('Property [%s] is not part of the record.', $key)));
            }
        }

        return $this->transform($value, static fn(Type $property, mixed $item): Result => $property->assert($item));
    }

    public function coerce(mixed $value): Result
    {
        if (is_string($value) && json_validate($value) && $decoded = \Psl\Json\decode($value)) {
            $value = $decoded;
        }

        if (!is_array($value)) {
            return new Err(new TransformValueException(type: 'record', value: $value));
        }

        return $this->transform($value, static fn(Type $property, mixed $item): Result => $property->coerce($item));
    }

    /**
     * @param array<array-key, mixed> $value
     * @param callable(Type, mixed): Result<Option<mixed>, \Throwable> $transform
     * @return Result<Option<array<array-key, mixed>>, \Throwable>
     */
    private function transform(array $value, callable $transform): Result
    {
        /** @var array<array-key, mixed> $record */
        $record = [];
        $missing = null;

        foreach ($this->properties as $name => $property) {
            if (!array_key_exists($name, $value)) {
                if ($property->optional) {
                    continue;
                }

                $missing ??= RecordPropertyViolation::missing($name);

                continue;
            }

            $result = $transform($property->type, $value[$name]);

            if ($result->isErr()) {
                $failure = $result->unwrapErr();
                $violation = $failure instanceof RecordPropertyViolation
                    ? $failure->beneath($name)
                    : RecordPropertyViolation::invalid($name, $failure);

                if ($violation->missing) {
                    $missing ??= $violation;

                    continue;
                }

                return new Err($violation);
            }

            $admitted = $result->unwrap();

            if ($admitted->isNone() && !$property->type->shape() instanceof OptionShape) {
                return new Err(RecordPropertyViolation::absent($name, TypeDescriber::describe($property->type)));
            }

            $record[$name] = $admitted->unwrapOr(null);
        }

        if ($missing !== null) {
            return new Err($missing);
        }

        return Ok(Some($record));
    }

    /** @param array<string, mixed> $value */
    public function format(mixed $value): string
    {
        $parts = [];

        foreach ($this->properties as $key => $property) {
            if (!array_key_exists($key, $value)) {
                continue;
            }

            $parts[] = sprintf('%s: %s', $key, $property->type->format($value[$key]));
        }

        return implode(', ', $parts);
    }

    public function shape(): Shape
    {
        return new RecordShape(array_map(
            static fn(RecordProperty $property) => $property->shape(),
            $this->properties,
        ));
    }

    /**
     * @param list<non-empty-list<string>> $paths
     */
    private function projectSegments(array $paths): self
    {
        /** @var array<string, list<list<string>>> $grouped */
        $grouped = [];

        foreach ($paths as $path) {
            $name = array_shift($path);

            if ($name !== null && isset($this->properties[$name])) {
                $grouped[$name][] = $path;
            }
        }

        $projected = [];

        foreach ($grouped as $name => $tails) {
            $property = $this->properties[$name];
            $type = array_any($tails, static fn(array $tail): bool => $tail === [])
                ? $property->type
                : self::projectType($property->type, $tails);

            $projected[$name] = $property->optional ? new Optional($type) : $type;
        }

        return new self($projected);
    }

    /** @param list<list<string>> $paths */
    private static function projectType(Type $type, array $paths): Type
    {
        if ($type instanceof self) {
            /** @var list<non-empty-list<string>> $paths */
            return $type->projectSegments($paths);
        }

        if ($type instanceof OptionType) {
            return new OptionType(self::projectType($type->inner, $paths));
        }

        return $type;
    }

}
