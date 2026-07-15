<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
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
 * short-circuits, match arms, value-dependent partiality — and the explicit
 * admission nodes (Coerce, Ascription), which check values by design.
 */
final readonly class CompiledNode
{
    /** @param Closure(Runtime): Result<Option<mixed>, Throwable> $evaluate */
    public function __construct(
        public Type $returns,
        public Closure $evaluate,
    ) {}
}
