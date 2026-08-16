<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\RecordPropertyShape;

/** @internal The normalized form of one {@see RecordType} property. */
final readonly class RecordProperty
{
    public function __construct(
        public Type $type,
        public bool $optional,
    ) {}

    /** The type observed by member access, including omission. */
    public function accessedType(): Type
    {
        return $this->optional && !$this->type->shape() instanceof OptionShape
            ? new OptionType($this->type)
            : $this->type;
    }

    public function shape(): RecordPropertyShape
    {
        return new RecordPropertyShape($this->type->shape(), $this->optional);
    }

}
