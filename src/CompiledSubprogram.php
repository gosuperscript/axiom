<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use LogicException;
use Superscript\Axiom\Exceptions\CompilationAborted;
use Superscript\Axiom\Execution\Observer;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * A separately-bound compiled source body.
 *
 * Source compilers receive this only from
 * {@see SourceCompilation::subprogram()}. Its argument values therefore come
 * from already-certified compiled parents and enter a fresh Runtime directly,
 * without pretending to cross a Program's public admission boundary again.
 */
final readonly class CompiledSubprogram
{
    public Type $returns;

    /**
     * @internal Constructed by SourceCompilation.
     * @param list<string> $parameters
     */
    public function __construct(
        private CompiledNode $node,
        private array $parameters,
        private string $path,
    ) {
        $this->returns = $node->returns;
    }

    /** Require the present result to fill one expected type. */
    public function expectPresent(Type $expected): self
    {
        try {
            (new CompiledSource($this->node))->expectPresent($expected);
        } catch (CompilationAborted $aborted) {
            throw new CompilationAborted($aborted->mismatch->at($this->path));
        }

        return $this;
    }

    /**
     * @internal SourceEvaluation owns invocation for source compilers.
     * @param array<string, mixed> $bindings
     * @return Result<Option<mixed>, Throwable>
     */
    public function invoke(array $bindings, ?Observer $observer = null): Result
    {
        $actual = array_keys($bindings);
        $expected = $this->parameters;
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            throw new LogicException('A compiled subprogram requires exactly its declared bindings.');
        }

        return $this->node->evaluate(new Runtime(new Bindings($bindings), $observer));
    }
}
