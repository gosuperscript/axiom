<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;

/**
 * The type of a node that did not compile. It exists so error-tolerant
 * compilation ({@see \Superscript\Axiom\Analysis\Diagnosis}) can give a
 * broken subtree *a* type and carry on checking everything around it.
 *
 * Two properties make it work:
 *
 *  - Its shape is {@see NeverShape}, so every downstream judgment it touches
 *    succeeds vacuously: it is assignable everywhere, `UnionType::join` drops
 *    it, and match exhaustiveness is satisfied. Absorption is therefore
 *    silent — a node above a failure produces no second refusal.
 *  - It is recognised by class, not by shape, so the compiler can tell
 *    "this operand already failed" from "this operand is genuinely Never"
 *    and skip operator resolution entirely.
 *
 * It is minted only where a diagnostic is recorded alongside it, and a
 * {@see \Superscript\Axiom\Program} refuses to be constructed from a node
 * tree containing one. An ErrorType therefore never reaches evaluation.
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
}
