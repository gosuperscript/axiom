<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
use LogicException;
use Superscript\Axiom\Analysis\CompilationNode;
use Superscript\Axiom\Execution\Node;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * A compiled {@see Source} node: its type and its evaluation, one value —
 * the source-level twin of {@see Operators\ResolvedOperation}. The compiler
 * emits one per node; a {@see Program} is the root node plus its boundary.
 *
 * The evaluation trusts the compilation: it performs no dispatch and never
 * inspects a value's type. What remains at runtime is semantics — absence
 * short-circuits, match arms, value-dependent errors like division by
 * zero — and the explicit admission nodes (Coerce, Ascription), which
 * check values because checking values is their job.
 *
 * ## A node that failed has neither half
 *
 * Error-tolerant compilation ({@see Analysis\Diagnosis}) gives up on a source
 * it cannot type and carries on around it. What it emits is this class's
 * other state: a node with **no type and no evaluation**, minted by
 * {@see failed()}. Failure is that absence, not a value — there is no marker
 * type to hand out, wrap, or claim back, and so nothing to guard against a
 * host claiming.
 *
 * Both halves go together, and that is what makes the guarantee total: the
 * missing type means no compiler can present a checked face over the
 * subtree, and the missing evaluation means there is nothing to run if one
 * somehow did. {@see $failed} is the question to ask; reading {@see $returns}
 * or calling {@see evaluate()} on one refuses.
 *
 * The two ways a node comes into being other than {@see returning()} both
 * preserve that: {@see forSource()} carries a node's own type across
 * unchanged, and {@see evaluatedBy()} answers a failed node with itself,
 * because there is no evaluation to wrap.
 *
 * @internal Source compilers compose CompiledSource instead.
 */
final class CompiledNode
{
    /**
     * Null exactly when this node failed to compile — see the class note on
     * why the two halves are missing together.
     *
     * @var ?Closure(Runtime): Result<Option<mixed>, Throwable>
     */
    private readonly ?Closure $evaluation;

    private readonly string $sourceType;

    private readonly ?Type $certifiedType;

    /** Did the source this node stands for fail to compile? */
    public readonly bool $failed;

    /** What this node returns. A node that failed has nothing to return. */
    public Type $returns {
        get => $this->certifiedType ?? throw new LogicException('A node that failed to compile has no return type; a program is never certified from a tree containing one.');
    }

    /**
     * @param ?Closure(Runtime): Result<Option<mixed>, Throwable> $evaluation
     * @param list<ReferencePath> $references
     */
    private function __construct(
        ?Type $returns,
        ?Closure $evaluation,
        ?string $sourceType = null,
        private readonly ?CompilationNode $compilation = null,
        public readonly array $references = [],
    ) {
        $this->certifiedType = $returns;
        $this->evaluation = $evaluation;
        $this->failed = $returns === null;
        $this->sourceType = $sourceType ?? self::class;
    }

    /**
     * A node returning what its compiler certified.
     *
     * @param Closure(Runtime): Result<Option<mixed>, Throwable> $evaluation
     * @param list<ReferencePath> $references
     */
    public static function returning(
        Type $returns,
        Closure $evaluation,
        ?string $sourceType = null,
        ?CompilationNode $compilation = null,
        array $references = [],
    ): self {
        return new self($returns, $evaluation, $sourceType, $compilation, $references);
    }

    /**
     * The node of a source that did not compile: no type and no evaluation.
     * It is minted here and nowhere else, so the two absences can never come
     * apart.
     */
    public static function failed(): self
    {
        return new self(null, null);
    }

    /**
     * The same node with its evaluation wrapped by another — a memoizing
     * slot, an annotation. The type, the source identity and everything
     * recorded about the compilation are the node's own and pass through
     * untouched, which is why this needs no gate of its own: a wrap does not
     * choose a type, so it cannot claim a sound one over a node that failed.
     *
     * A node that failed has no evaluation to wrap and answers with itself:
     * a memoizing slot around nothing would run nothing, and the wrapper
     * would be the one node carrying an evaluation it must never have.
     *
     * @internal
     *
     * @param Closure(Runtime): Result<Option<mixed>, Throwable> $evaluation
     */
    public function evaluatedBy(Closure $evaluation): self
    {
        if ($this->failed) {
            return $this;
        }

        return new self($this->certifiedType, $evaluation, $this->sourceType, $this->compilation, $this->references);
    }

    /**
     * Attach the source identity known by the compiler. Source compilers only
     * construct the node's type and evaluation; the outer compiler owns this
     * description, so host nodes cannot forget to participate in observation.
     *
     * @internal
     *
     * @param list<ReferencePath> $references
     */
    public function forSource(Source $source, CompilationNode $compilation, array $references = []): self
    {
        return new self($this->certifiedType, $this->evaluation, $source::class, $compilation, $references);
    }

    /** @internal Compilation infrastructure and Program consume this metadata. */
    public function compilation(): ?CompilationNode
    {
        return $this->compilation;
    }

    /** @return Result<Option<mixed>, Throwable> */
    public function evaluate(Runtime $runtime): Result
    {
        $evaluation = $this->evaluation ?? throw new LogicException('A node that failed to compile has no evaluation; this program was never certified.');

        return $runtime->evaluate(
            fn(): Node => new Node($this->sourceType, $this->returns),
            fn(): Result => $evaluation($runtime),
        );
    }
}
