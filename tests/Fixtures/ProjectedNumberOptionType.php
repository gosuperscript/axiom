<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Fixtures;

use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Result\Result;

/** @implements Type<int|float|null> */
final readonly class ProjectedNumberOptionType implements Type
{
    private OptionType $type;

    public function __construct()
    {
        $this->type = new OptionType(new NumberType());
    }

    public function assert(mixed $value): Result
    {
        return $this->type->assert($value);
    }

    public function coerce(mixed $value): Result
    {
        return $this->type->coerce($value);
    }

    public function format(mixed $value): string
    {
        return $this->type->format($value);
    }

    public function shape(): Shape
    {
        return new OptionShape((new NumberType())->shape());
    }
}
