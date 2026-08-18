<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * Named properties, exact: a record's accepted values are fully described by
 * its properties and their presence rules. Bare properties are required;
 * optional properties may be missing while still validating supplied values
 * against their own value shapes.
 */
final class RecordShape extends Shape
{
    /** @var array<string, RecordPropertyShape> */
    public readonly array $properties;

    /**
     * Bare shapes remain a concise spelling for required properties.
     *
     * @param array<string, Shape|RecordPropertyShape> $properties
     */
    public function __construct(array $properties)
    {
        $this->properties = array_map(
            static fn(Shape|RecordPropertyShape $property): RecordPropertyShape => $property instanceof RecordPropertyShape
                ? $property
                : new RecordPropertyShape($property, false),
            $properties,
        );
    }

    public function equals(Shape $other): bool
    {
        if (!$other instanceof self || count($this->properties) !== count($other->properties)) {
            return false;
        }

        return array_all(
            $this->properties,
            fn(RecordPropertyShape $property, string $name): bool => isset($other->properties[$name])
                && $property->equals($other->properties[$name]),
        );
    }
}
