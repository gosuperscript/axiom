<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * Named fields, exact: a record's value set is fully described by its
 * fields — there is no open variant and no width subtyping. Exactness is
 * what lets a total verdict claim whole-record operations (equality over
 * a record is exactly equality over its fields). Data with unenumerable
 * keys is a Dict.
 *
 * There is no presence flag: an optional field is a field whose shape is
 * OptionShape. Missing-key and present-null are one absence concept —
 * record coercion canonicalizes, inserting null for missing optional keys.
 * (Revisit if a keys()-like operation over records ever enters the language.)
 */
final class RecordShape extends Shape
{
    /**
     * @param array<string, Shape> $fields
     */
    public function __construct(
        public readonly array $fields,
    ) {}

    public function equals(Shape $other): bool
    {
        if (!$other instanceof self || count($this->fields) !== count($other->fields)) {
            return false;
        }

        return array_all(
            $this->fields,
            fn(Shape $field, string $name) => isset($other->fields[$name]) && $field->equals($other->fields[$name]),
        );
    }
}
