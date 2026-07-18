<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
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
 * @internal Source compilers compose CompiledSource instead.
 */
final readonly class CompiledNode
{
    /** @var Closure(Runtime): Result<Option<mixed>, Throwable> */
    private Closure $evaluation;

    private string $sourceType;

    /** @param Closure(Runtime): Result<Option<mixed>, Throwable> $evaluation */
    public function __construct(
        public Type $returns,
        Closure $evaluation,
        ?string $sourceType = null,
    ) {
        $this->evaluation = $evaluation;
        $this->sourceType = $sourceType ?? self::class;
    }

    /**
     * Attach the source identity known by the compiler. Source compilers only
     * construct the node's type and evaluation; the outer compiler owns this
     * description, so host nodes cannot forget to participate in observation.
     *
     * @internal
     */
    public function forSource(Source $source): self
    {
        return new self($this->returns, $this->evaluation, $source::class);
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
