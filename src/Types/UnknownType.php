<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use SebastianBergmann\Exporter\Exporter;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\UnknownShape;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * The statically unnameable. Admits every value; certifies nothing.
 * Derived by inference (or bound explicitly by a scope that tolerates
 * unknown symbols) — never authored as a declaration.
 *
 * @implements Type<mixed>
 */
final class UnknownType implements Type
{
    public function assert(mixed $value): Result
    {
        return Ok($value === null ? None() : Some($value));
    }

    public function coerce(mixed $value): Result
    {
        return $this->assert($value);
    }

    public function format(mixed $value): string
    {
        return (new Exporter())->export($value);
    }

    public function shape(): Shape
    {
        return new UnknownShape();
    }
}
