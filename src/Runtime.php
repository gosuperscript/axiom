<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * The per-invocation state of a compiled {@see Program}: the admitted
 * bindings, the lazily-evaluated definition slots, and the observability
 * inspector. It carries no dialect and no resolver — a compiled program has
 * nothing left to dispatch.
 */
final class Runtime
{
    /** @var array<string, Result<Option<mixed>, Throwable>> */
    private array $slots = [];

    public function __construct(
        public readonly Bindings $bindings = new Bindings(),
        public readonly ?ResolutionInspector $inspector = null,
    ) {}

    /**
     * A definition evaluates lazily and at most once per invocation; every
     * later reference reads the memoized result.
     *
     * @param Closure(): Result<Option<mixed>, Throwable> $evaluate
     * @return Result<Option<mixed>, Throwable>
     */
    public function slot(string $key, Closure $evaluate): Result
    {
        if (array_key_exists($key, $this->slots)) {
            $this->inspector?->annotate('memo', 'hit');

            return $this->slots[$key];
        }

        $this->inspector?->annotate('memo', 'miss');

        return $this->slots[$key] = $evaluate();
    }
}
