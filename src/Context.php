<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * Per-call state threaded through resolution.
 *
 * Carries the inputs ({@see Bindings}), the stable named expressions
 * ({@see Definitions}), an optional {@see ResolutionInspector}, and a
 * per-call symbol memo. Resolvers are expected to be stateless and read
 * all per-call state from the context.
 *
 * The optional {@see OperatorOverloader} is consumed by
 * {@see Source::type()} implementations during pre-execution type
 * checking — it is not used during evaluation (resolvers read their
 * overloader from the DI container).
 */
final class Context
{
    /** @var array<string, Result<Option<mixed>, Throwable>> */
    private array $symbolMemo = [];

    public function __construct(
        public readonly Bindings $bindings = new Bindings(),
        public readonly Definitions $definitions = new Definitions(),
        public readonly ?ResolutionInspector $inspector = null,
        public readonly ?OperatorOverloader $operators = null,
    ) {}

    public function hasMemoizedSymbol(string $key): bool
    {
        return array_key_exists($key, $this->symbolMemo);
    }

    /**
     * @return Result<Option<mixed>, Throwable>
     */
    public function getMemoizedSymbol(string $key): Result
    {
        return $this->symbolMemo[$key];
    }

    /**
     * @param Result<Option<mixed>, Throwable> $result
     */
    public function memoizeSymbol(string $key, Result $result): void
    {
        $this->symbolMemo[$key] = $result;
    }
}
