<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use LogicException;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Monads\Result\Result;

/**
 * The type of a node that did not compile. It exists so error-tolerant
 * compilation ({@see \Superscript\Axiom\Analysis\Diagnosis}) can give a
 * broken subtree *a* type and carry on checking everything around it.
 *
 * Two properties make it work:
 *
 *  - Its shape is {@see NeverShape}, so every judgment phrased as "may these
 *    values flow here" passes vacuously: it is assignable everywhere,
 *    `UnionType::join` drops it, and match exhaustiveness is satisfied.
 *  - It is recognised by class, not by shape, so the compiler can tell
 *    "this operand already failed" from "this operand is genuinely Never"
 *    and skip the judgment entirely.
 *
 * The shape alone is not enough, because not every judgment is phrased that
 * way. Overlap asks whether two types *share* a value, and an uninhabited
 * type shares none; member access asks what a type promises, and an
 * uninhabited type promises nothing. Both refuse where the question is put
 * to them. So each such judgment tests the class and absorbs before asking —
 * the judgments live on {@see \Superscript\Axiom\SourceCompilation} for
 * exactly that reason — and that, not the shape, is what keeps a node above a
 * failure from refusing again.
 *
 * It is minted only where a diagnostic is recorded alongside it, and a
 * {@see \Superscript\Axiom\Program} refuses to be constructed from a node
 * tree containing one. An ErrorType therefore never reaches evaluation.
 *
 * @implements Type<mixed>
 */
final class ErrorType implements Type
{
    private static ?self $shared = null;

    /**
     * The mark itself. An ErrorType carries no state and is recognised by
     * class rather than by identity, so every node that did not compile
     * wears the same one.
     */
    public static function shared(): self
    {
        return self::$shared ??= new self();
    }

    public function assert(mixed $value): Result
    {
        self::refuse();
    }

    public function coerce(mixed $value): Result
    {
        self::refuse();
    }

    public function format(mixed $value): string
    {
        self::refuse();
    }

    public function shape(): Shape
    {
        return new NeverShape();
    }

    /**
     * The three questions a type answers about a *value* — does this value
     * inhabit me, can I make one that does, how do I render one — and an
     * ErrorType is asked none of them. It marks a node the compiler gave up
     * on; no Program is certified from a tree containing one, and nothing
     * outside a certified program ever holds a value to put to a type.
     * Reaching this is a defect in that guard, not a program error, so it
     * says so rather than inventing an answer.
     */
    private static function refuse(): never
    {
        throw new LogicException('A type that marks a node that failed to compile answers nothing about a value; this program was never certified.');
    }
}
