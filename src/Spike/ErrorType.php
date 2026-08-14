<?php

declare(strict_types=1);

namespace Superscript\Axiom\Spike;

use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;

/**
 * SPIKE ONLY. The type of a node that failed to compile.
 *
 * It exists so a broken subtree still has *a* type and the walk can carry on
 * checking its siblings. Two properties make it work:
 *
 *  - Its shape is NeverShape, so every downstream judgment it touches
 *    succeeds vacuously: assignability holds, UnionType::join drops it, and
 *    match exhaustiveness is satisfied. That is what makes absorption
 *    *silent* rather than a second refusal.
 *  - It is recognised by class, not by shape, so the tolerant compiler can
 *    tell "this operand already failed" from "this operand is genuinely
 *    Never" and skip operator resolution entirely.
 *
 * Every ErrorType is minted alongside a diagnostic (TolerantCompiler::fail
 * is the only mint), which is the whole soundness argument: ErrorType in the
 * tree implies a diagnostic implies program() refuses.
 *
 * @implements Type<mixed>
 */
final class ErrorType implements Type
{
    public function assert(mixed $value): Result
    {
        return new Err(new TransformValueException(type: 'error', value: $value));
    }

    public function coerce(mixed $value): Result
    {
        return $this->assert($value);
    }

    public function format(mixed $value): string
    {
        return '<error>';
    }

    public function shape(): Shape
    {
        return new NeverShape();
    }

    public static function isErrorType(Type $type): bool
    {
        return $type instanceof self;
    }
}
