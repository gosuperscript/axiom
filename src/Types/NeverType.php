<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;

/**
 * Bottom: no value inhabits it. Derived (the element of an empty list
 * literal, the join identity) — never authored as a declaration.
 *
 * @implements Type<mixed>
 */
final class NeverType implements Type
{
    public function assert(mixed $value): Result
    {
        return new Err(new TransformValueException(type: 'never', value: $value));
    }

    public function coerce(mixed $value): Result
    {
        return $this->assert($value);
    }

    public function format(mixed $value): string
    {
        return '';
    }

    public function shape(): Shape
    {
        return new NeverShape();
    }
}
