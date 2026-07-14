<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use InvalidArgumentException;
use SebastianBergmann\Exporter\Exporter;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;
use Superscript\Axiom\Types\Shapes\RecordShape;
use Superscript\Axiom\Types\Shapes\Shape;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * Named fields, open or closed. An optional field is a field whose type is
 * OptionType — there is no separate presence flag.
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
        public bool $open = false,
    ) {}

    public function assert(mixed $value): Result
    {
        return $this->transform($value, fn(Type $field, mixed $item) => $field->assert($item));
    }

    public function coerce(mixed $value): Result
    {
        if (is_string($value) && json_validate($value) && $decoded = \Psl\Json\decode($value)) {
            $value = $decoded;
        }

        return $this->transform($value, fn(Type $field, mixed $item) => $field->coerce($item));
    }

    /**
     * @param callable(Type, mixed): Result<Option<mixed>, \Throwable> $transform
     * @return Result<Option<array<array-key, mixed>>, \Throwable>
     */
    private function transform(mixed $value, callable $transform): Result
    {
        if (!is_array($value)) {
            return new Err(new TransformValueException(type: 'record', value: $value));
        }

        if (!$this->open) {
            foreach (array_keys($value) as $key) {
                if (!isset($this->fields[$key])) {
                    return new Err(new InvalidArgumentException(sprintf('Field [%s] is not permitted by the closed record.', $key)));
                }
            }
        }

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

        if ($this->open) {
            foreach ($value as $key => $item) {
                if (!isset($this->fields[$key])) {
                    $record[$key] = $item;
                }
            }
        }

        return Ok(Some($record));
    }

    public function compare(mixed $a, mixed $b): bool
    {
        return array_keys($a) === array_keys($b) && array_all(
            array_keys($a),
            fn(int|string $key) => isset($this->fields[$key])
                ? $this->fields[$key]->compare($a[$key], $b[$key])
                : $a[$key] === $b[$key],
        );
    }

    public function format(mixed $value): string
    {
        $parts = [];

        foreach ($value as $key => $item) {
            $parts[] = sprintf(
                '%s: %s',
                $key,
                isset($this->fields[$key]) ? $this->fields[$key]->format($item) : (new Exporter())->export($item),
            );
        }

        return implode(', ', $parts);
    }

    public function shape(): Shape
    {
        $fields = [];

        foreach ($this->fields as $name => $field) {
            $fields[$name] = $field->shape();
        }

        return new RecordShape($fields, $this->open);
    }
}
