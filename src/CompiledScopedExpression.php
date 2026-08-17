<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use LogicException;
use Superscript\Axiom\Exceptions\CompilationAborted;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * A lexically scoped compiled source body.
 *
 * Source compilers receive this only from
 * {@see SourceCompilation::scope()}. Its local values therefore come from
 * already-certified compiled parents, while every free symbol resolves
 * lexically through the enclosing program.
 */
final readonly class CompiledScopedExpression
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
        private LocalScope $scope,
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
    public function invoke(array $bindings, Runtime $runtime): Result
    {
        $actual = array_keys($bindings);
        $expected = $this->parameters;
        sort($actual);
        sort($expected);

        if ($actual !== $expected) {
            throw new LogicException('A compiled scoped expression requires exactly its declared bindings.');
        }

        return $runtime->within(
            $this->scope,
            new Bindings($bindings),
            fn(): Result => $this->node->evaluate($runtime),
        );
    }
}
