<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
use Superscript\Axiom\Analysis\CompilationNode;
use Superscript\Axiom\Analysis\UnreachableEvaluation;
use Superscript\Axiom\Execution\Node;
use Superscript\Axiom\Types\ErrorType;
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
 * ## The one gate on {@see ErrorType}
 *
 * Every type that becomes a certified return type arrives here: what a source
 * compiler claims through `produces()`, `custom()` or `constant()`, what an
 * operator rule returns, what a literal factory infers, what a field
 * declares. So this is where the mark for a node that failed is refused —
 * {@see returning()} runs {@see ErrorType::refuseAuthored()} on the claim,
 * once, for all of them. A host that hangs on to a failed child's type and
 * claims it back therefore hears about it where it made the claim, rather
 * than as a certification that refuses a later, blameless expression.
 *
 * Construction is private so that gate cannot be walked around. The two other
 * ways a node comes into being both stay inside it: {@see failed()} mints the
 * mark itself, which is the compiler's own and the one thing the gate must
 * let through, and {@see evaluatedBy()} and {@see forSource()} re-wrap a node
 * that already passed, carrying its type across unchanged — a wrap cannot
 * present a sound type over a failure, because it does not choose the type.
 *
 * @internal Source compilers compose CompiledSource instead.
 */
final readonly class CompiledNode
{
    /** @var Closure(Runtime): Result<Option<mixed>, Throwable> */
    private Closure $evaluation;

    private string $sourceType;

    /**
     * @param Closure(Runtime): Result<Option<mixed>, Throwable> $evaluation
     * @param list<string> $references
     */
    private function __construct(
        public Type $returns,
        Closure $evaluation,
        ?string $sourceType = null,
        private ?CompilationNode $compilation = null,
        public array $references = [],
    ) {
        $this->evaluation = $evaluation;
        $this->sourceType = $sourceType ?? self::class;
    }

    /**
     * A node returning what its compiler certified. The claim is checked for
     * the compiler's mark for a node that failed: no compiler is entitled to
     * make that claim, and one made here would put a failure into a tree
     * nothing diagnosed.
     *
     * @param Closure(Runtime): Result<Option<mixed>, Throwable> $evaluation
     * @param list<string> $references
     */
    public static function returning(
        Type $returns,
        Closure $evaluation,
        ?string $sourceType = null,
        ?CompilationNode $compilation = null,
        array $references = [],
    ): self {
        ErrorType::refuseAuthored($returns, 'the type this compiled node returns');

        return new self($returns, $evaluation, $sourceType, $compilation, $references);
    }

    /**
     * The node of a source that did not compile: {@see ErrorType} paired with
     * an evaluation that refuses to run. The pair is what makes
     * {@see Program}'s certification guard total — the type says the compiler
     * gave up here, the evaluation makes reaching it a defect rather than a
     * result — so it is minted here and nowhere else, and the two can never
     * come apart.
     */
    public static function failed(): self
    {
        return new self(ErrorType::shared(), UnreachableEvaluation::refuse(...));
    }

    /**
     * The same node with its evaluation wrapped by another — a memoizing
     * slot, an annotation. The type, the source identity and everything
     * recorded about the compilation are the node's own and pass through
     * untouched, which is why this needs no gate of its own: a wrap does not
     * choose a type, so it cannot claim a sound one over a node that failed.
     *
     * @internal
     *
     * @param Closure(Runtime): Result<Option<mixed>, Throwable> $evaluation
     */
    public function evaluatedBy(Closure $evaluation): self
    {
        return new self($this->returns, $evaluation, $this->sourceType, $this->compilation, $this->references);
    }

    /**
     * Attach the source identity known by the compiler. Source compilers only
     * construct the node's type and evaluation; the outer compiler owns this
     * description, so host nodes cannot forget to participate in observation.
     *
     * @internal
     *
     * @param list<string> $references
     */
    public function forSource(Source $source, CompilationNode $compilation, array $references = []): self
    {
        return new self($this->returns, $this->evaluation, $source::class, $compilation, $references);
    }

    /** @internal Compilation infrastructure and Program consume this metadata. */
    public function compilation(): ?CompilationNode
    {
        return $this->compilation;
    }

    /** @return Result<Option<mixed>, Throwable> */
    public function evaluate(Runtime $runtime): Result
    {
        return $runtime->evaluate(
            fn(): Node => new Node($this->sourceType, $this->returns),
            fn(): Result => ($this->evaluation)($runtime),
        );
    }
}
