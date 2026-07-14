<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * Named fields, open or closed. Width subtyping when open;
 * vocabulary-policing when closed.
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
        public readonly bool $open = false,
    ) {}

    public function equals(Shape $other): bool
    {
        if (!$other instanceof self || $this->open !== $other->open || count($this->fields) !== count($other->fields)) {
            return false;
        }

        return array_all(
            $this->fields,
            fn(Shape $field, string $name) => isset($other->fields[$name]) && $field->equals($other->fields[$name]),
        );
    }
}
