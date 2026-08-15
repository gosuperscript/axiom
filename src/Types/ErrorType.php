<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use InvalidArgumentException;
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
 * ## The guarantee, and what holds it
 *
 * A certified program contains no node that failed and no ErrorType visible
 * through any structure this library defines. That holds by construction,
 * because the mark has no public appearance and host code has no way to come
 * by one:
 *
 *  - **It cannot be minted.** The constructor is private, so {@see shared()}
 *    — internal to the library — is the only mint.
 *  - **It cannot be taken from a failed child.** A compiler is handed a child
 *    for every source it compiles, and reading a failed one's type would
 *    answer with the mark. {@see \Superscript\Axiom\CompiledSource::$returns}
 *    refuses instead, and the judgments on
 *    {@see \Superscript\Axiom\SourceCompilation} answer for a failed child
 *    so nothing is lost by refusing.
 *  - **It cannot be read off a diagnosis.** An expression whose root failed
 *    returns nothing, and {@see \Superscript\Axiom\Analysis\Diagnosis::$returns}
 *    is null for it rather than the mark.
 *
 * Nothing therefore stands between a host and a type it may legitimately
 * hold, and there is no authored type to walk into looking for a mark that
 * cannot be in one. One backstop remains, against acquisition this library
 * does not support — reflection into the private constructor, an unserialize:
 * {@see refuseClaimed()} at {@see \Superscript\Axiom\CompiledNode::returning()},
 * where every type claimed as a node's return arrives. It is one instanceof
 * per node and walks into nothing.
 *
 * Behind it, **{@see \Superscript\Axiom\Program}'s certification** answers
 * for the whole tree at the moment a type stops being a claim and becomes a
 * promise, and it costs one boolean read.
 *
 * @internal The compiler's own mark; it appears nowhere in the public API.
 *
 * @implements Type<mixed>
 */
final class ErrorType implements Type
{
    private static ?self $shared = null;

    /**
     * The mark is one instance for the whole process, and this is the only
     * place an instance comes into being — construction is private, so
     * {@see shared()} is the only mint.
     */
    private function __construct()
    {
        self::$shared = $this;
    }

    /**
     * The mark itself. An ErrorType carries no state and is recognised by
     * class rather than by identity, so every node that did not compile
     * wears the same one.
     *
     * @internal Minted only alongside the diagnostic that explains it —
     * error-tolerant compilation types the node it gave up on, and nothing
     * else has a node to give up on.
     */
    public static function shared(): self
    {
        return self::$shared ?? new self();
    }

    /**
     * Refuse a type claimed as a compiled node's return. The mark has no
     * public appearance and cannot be obtained through any supported route,
     * so this guards against one that was obtained through an unsupported
     * one — reflection past the private constructor, a hand-built instance —
     * and against a first-party regression that hands the mark back out.
     *
     * It reads the claim and does not walk into it. A host with no instance
     * cannot build `Option<Error>` or `{quotes: List<Error>}` around one
     * either, so the mark can only arrive whole; and every node of every
     * program passes through here, which is what makes one instanceof the
     * right price.
     *
     * @internal Called where a node takes the type its compiler claims.
     */
    public static function refuseClaimed(Type $type): void
    {
        if ($type instanceof self) {
            throw new InvalidArgumentException('The compiler marks a node it gave up on with a type of its own, and the type this compiled node returns is one. It is minted only alongside the diagnostic that explains it, and nothing outside compilation is given one; supply the type the value really has.');
        }
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
