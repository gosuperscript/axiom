<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use InvalidArgumentException;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;
use Superscript\Axiom\Types\Shapes\RecordShape;
use Superscript\Axiom\Types\Shapes\Shape;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * Named fields, exact. An optional field is a field whose type is
 * OptionType — there is no separate presence flag.
 *
 * The two admission faces diverge on undeclared keys, by design: assert is
 * strict membership (an extra key means the value is not a member), while
 * coerce takes the declared slice of wide input — dropping undeclared keys
 * is a conversion, like '5' → 5 — so hosts may pass a whole context row
 * and only the declared fields enter.
 *
 * Coercion canonicalizes absence: a missing optional key becomes a present
 * null, so evaluation only ever sees one representation. A field coercion
 * that yields None reads as "required but missing" (OptionType never yields
 * None — it wraps absence as Some(null) — so optional fields are immune).
 *
 * @implements Type<array<array-key, mixed>>
 */
final readonly class RecordType implements Type
{
    /**
     * @param array<string, Type> $fields
     */
    public function __construct(
        public array $fields,
    ) {}

    public function assert(mixed $value): Result
    {
        if (!is_array($value)) {
            return new Err(new TransformValueException(type: 'record', value: $value));
        }

        // Strict membership: records are exact, so an undeclared key means
        // the value is not a member. Taking the declared slice belongs to
        // coerce — asserting never converts.
        foreach (array_keys($value) as $key) {
            if (!isset($this->fields[$key])) {
                return new Err(new InvalidArgumentException(sprintf('Field [%s] is not part of the record.', $key)));
            }
        }

        return $this->transform($value, fn(Type $field, mixed $item) => $field->assert($item));
    }

    public function coerce(mixed $value): Result
    {
        if (is_string($value) && json_validate($value) && $decoded = \Psl\Json\decode($value)) {
            $value = $decoded;
        }

        if (!is_array($value)) {
            return new Err(new TransformValueException(type: 'record', value: $value));
        }

        return $this->transform($value, fn(Type $field, mixed $item) => $field->coerce($item));
    }

    /**
     * Builds the record from the declared fields alone — undeclared keys in
     * $value simply never enter, which is coerce's declared-slice behavior
     * (assert has already rejected them).
     *
     * @param array<array-key, mixed> $value
     * @param callable(Type, mixed): Result<Option<mixed>, \Throwable> $transform
     * @return Result<Option<array<array-key, mixed>>, \Throwable>
     */
    private function transform(array $value, callable $transform): Result
    {
        /** @var array<array-key, mixed> $record */
        $record = [];

        foreach ($this->fields as $name => $field) {
            $result = $transform($field, $value[$name] ?? null);

            if ($result->isErr()) {
                return new Err(new InvalidArgumentException(
                    sprintf('Field [%s]: %s', $name, $result->unwrapErr()->getMessage()),
                ));
            }

            $option = $result->unwrap();

            if ($option->isNone()) {
                return new Err(new InvalidArgumentException(sprintf('Required field [%s] is missing.', $name)));
            }

            $record[$name] = $option->unwrap();
        }

        return Ok(Some($record));
    }

    public function format(mixed $value): string
    {
        $parts = [];

        foreach ($this->fields as $key => $field) {
            $parts[] = sprintf('%s: %s', $key, $field->format($value[$key]));
        }

        return implode(', ', $parts);
    }

    public function shape(): Shape
    {
        $fields = [];

        foreach ($this->fields as $name => $field) {
            $fields[$name] = $field->shape();
        }

        return new RecordShape($fields);
    }
}
