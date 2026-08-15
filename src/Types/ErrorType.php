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
 * It is minted only where a diagnostic is recorded alongside it, and a
 * {@see \Superscript\Axiom\Program} refuses to be constructed from a node
 * tree containing one. An ErrorType therefore never reaches evaluation.
 *
 * ## The guarantee, and what holds it
 *
 * A certified program contains no node that failed and no ErrorType visible
 * through any structure this library defines. What makes that hold is that
 * host code cannot obtain the mark at all:
 *
 *  - **It cannot be minted.** The constructor is private, so {@see shared()}
 *    — internal to the library — is the only mint.
 *  - **It cannot be taken from a failed child.** That was the one channel:
 *    a compiler is handed a child for every source it compiles, and reading
 *    a failed one's type used to answer with the mark. {@see
 *    \Superscript\Axiom\CompiledSource::$returns} refuses instead, and the
 *    judgments on {@see \Superscript\Axiom\SourceCompilation} answer for a
 *    failed child so nothing is lost by refusing.
 *
 * The mark is public in exactly one place — {@see
 * \Superscript\Axiom\Analysis\Diagnosis::$returns}, where an expression
 * whose root failed says so. Two guards answer for that one, and for
 * whatever a host smuggles inside a `Type` of its own, which no walk over
 * this library's composites can see into:
 *
 *  - **{@see refuseClaimed()} at every claim door.** Every type a host
 *    claims as a node's return — through `produces()`, `custom()`,
 *    `constant()`, an operator rule, a literal factory, a field declaration
 *    — arrives at {@see \Superscript\Axiom\CompiledNode::returning()}, which
 *    refuses the mark at top level. One instanceof, per node.
 *  - **{@see refuseAuthored()} at every authored-data door.** A declaration
 *    on `Expression` or `TypeEnvironment`, the type on `Ascription` or
 *    `Coerce`, an operator rule's operands and return: these take a type an
 *    author wrote down, so containment is walked here — `Option<Error>` and
 *    `{quotes: List<Error>}` are refused too — and the complaint names the
 *    declaration that is wrong. The walk costs one pass per expression or
 *    per dialect, never one per node.
 *
 * Behind both, **{@see \Superscript\Axiom\Program}'s certification** answers
 * for the whole tree at the moment a type stops being a claim and becomes a
 * promise, and it costs one boolean read.
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
     * Refuse a host-supplied type that is, or contains, the compiler's mark
     * for a node that failed. $door names what was being supplied, because
     * the caller's fault is a specific declaration or claim, not "a type
     * somewhere".
     *
     * This is programmer error and says so immediately: an authored
     * ErrorType is not a fault of the expression, so it must not become a
     * diagnostic — a diagnosis reports what compilation found, and it would
     * find a failure that nothing failed at.
     *
     * Containment is walked over the library's own composites (option,
     * union, list, dict, record, opaque parameters), which is every way this
     * library builds a type out of other types. A host type that wraps a
     * `Type` of its own is opaque to the walk; nothing at this level can see
     * inside it, and the mark being unobtainable is what covers that case.
     *
     * The walk runs only where a type an author wrote down is taken in —
     * once per expression, once per dialect — and never per compiled node.
     *
     * @internal Called by the public doors that ingest a type.
     */
    public static function refuseAuthored(Type $type, string $door): void
    {
        if (self::occursIn($type)) {
            self::refuseSupplied($door);
        }
    }

    /**
     * Refuse every declaration in a set of them, naming the one at fault. A
     * declaration is the type its symbol compiles to, and the two doors that
     * take a whole set — {@see \Superscript\Axiom\Expression} and
     * {@see TypeEnvironment} — ask exactly this question of it.
     *
     * @internal
     *
     * @param array<string, Type> $declarations
     */
    public static function refuseAuthoredDeclarations(array $declarations): void
    {
        foreach ($declarations as $key => $type) {
            self::refuseAuthored($type, sprintf('the declaration of [%s]', $key));
        }
    }

    /**
     * Refuse a type claimed as a compiled node's return. This one is not
     * walked into, because nothing can be hidden inside it: the mark cannot
     * be obtained, so a composite built around one cannot be built either.
     * What is left to refuse is the single place the mark is legitimately
     * public — {@see \Superscript\Axiom\Analysis\Diagnosis::$returns}, the
     * type of an expression whose root failed — handed straight back, and
     * that arrives whole.
     *
     * Answering in one instanceof rather than a walk is what keeps the gate
     * off compilation's cost: every node of every program passes through it,
     * while an authored type is taken in once for the whole expression.
     *
     * @internal Called where a node takes the type its compiler claims.
     */
    public static function refuseClaimed(Type $type, string $door): void
    {
        if ($type instanceof self) {
            self::refuseSupplied($door);
        }
    }

    private static function refuseSupplied(string $door): never
    {
        throw new InvalidArgumentException(sprintf(
            'The compiler marks a node it gave up on with a type of its own, and %s is or contains one. It is minted only alongside the diagnostic that explains it, so nothing outside compilation has one to give; supply the type the value really has.',
            $door,
        ));
    }

    private static function occursIn(Type $type): bool
    {
        return match (true) {
            $type instanceof self => true,
            $type instanceof OptionType => self::occursIn($type->inner),
            $type instanceof UnionType => array_any($type->members, self::occursIn(...)),
            $type instanceof ListType, $type instanceof DictType => self::occursIn($type->type),
            $type instanceof RecordType => array_any($type->fields, self::occursIn(...)),
            $type instanceof OpaqueType => array_any($type->parameters, self::occursIn(...)),
            default => false,
        };
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
