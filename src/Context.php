<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Operators\UnaryOverloader;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * Per-call state threaded through resolution.
 *
 * Carries the inputs ({@see Bindings}), the stable named expressions
 * ({@see Definitions}), the {@see Dialect} whose operator rules this
 * evaluation runs (the same instance the checker reads — the rules travel
 * with the call, exactly as bindings do, so no resolver-held stack exists
 * to diverge from the checked one), an optional {@see ResolutionInspector},
 * and a per-call symbol memo. Resolvers are expected to be stateless and
 * read all per-call state from the context.
 */
final class Context
{
    public readonly Dialect $dialect;

    /** @var array<string, Result<Option<mixed>, Throwable>> */
    private array $symbolMemo = [];

    /** @var array<string, true> */
    private array $resolvingSymbols = [];

    private ?OperatorOverloader $operators = null;

    private ?UnaryOverloader $unaryOperators = null;

    public function __construct(
        public readonly Bindings $bindings = new Bindings(),
        public readonly Definitions $definitions = new Definitions(),
        public readonly ?ResolutionInspector $inspector = null,
        ?Dialect $dialect = null,
    ) {
        $this->dialect = $dialect ?? Dialect::core();
    }

    public function operators(): OperatorOverloader
    {
        return $this->operators ??= $this->dialect->operators();
    }

    public function unaryOperators(): UnaryOverloader
    {
        return $this->unaryOperators ??= $this->dialect->unaryOperators();
    }

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

    /**
     * The runtime backstop for definition cycles: the memo alone cannot
     * break one (it is written only after resolution completes), so a
     * symbol marks itself in-progress before its definition is followed.
     * Re-entry means the definition graph is cyclic — a named error, where
     * unguarded recursion would run unbounded. Marks are never cleared: a
     * completed resolution is memoized before any repeated lookup could
     * reach this guard, so only a genuine cycle re-enters.
     *
     * @return bool false when the symbol is already being resolved
     */
    public function beginSymbolResolution(string $key): bool
    {
        if ($this->resolvingSymbols[$key] ?? false) {
            return false;
        }

        $this->resolvingSymbols[$key] = true;

        return true;
    }
}
