<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/** The value shape and key-presence rule of one record property. */
final readonly class RecordPropertyShape
{
    public function __construct(
        public Shape $value,
        public bool $optional,
    ) {}

    /** The shape observed by member access, including omission. */
    public function accessed(): Shape
    {
        return $this->optional && !$this->value instanceof OptionShape
            ? new OptionShape($this->value)
            : $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->optional === $other->optional
            && $this->value->equals($other->value);
    }
}
