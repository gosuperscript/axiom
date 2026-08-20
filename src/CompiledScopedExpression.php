<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use LogicException;
use Superscript\Axiom\Exceptions\CompilationAborted;
use Superscript\Axiom\Exceptions\MissingRequiredInput;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Result\Ok;

/**
 * A lexically scoped compiled source body.
 *
 * Source compilers receive this only from
 * {@see SourceCompilation::scope()}. Each invocation admits the local values
 * it reads through the supplied parameter declarations, while every free
 * symbol resolves lexically through the enclosing program.
 */
final readonly class CompiledScopedExpression
{
    public Type $returns;

    /** @var list<string> */
    private array $parameters;

    private InputBoundary $inputs;

    /**
     * @internal Constructed by SourceCompilation.
     * @param list<string> $parameters
     * @param array<string, Type> $parameterTypes
     */
    public function __construct(
        private CompiledNode $node,
        array $parameters,
        array $parameterTypes,
        private string $path,
        private LocalScope $scope,
        Boundary $boundary,
    ) {
        sort($parameters);

        $this->parameters = $parameters;
        $this->returns = $node->returns;
        $this->inputs = new InputBoundary(new RecordType($parameterTypes), $scope->references(), $boundary);
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
        sort($actual);

        if ($actual !== $this->parameters) {
            throw new LogicException('A compiled scoped expression requires exactly its declared bindings.');
        }

        $admitted = $this->inputs->admit($bindings);

        if ($admitted->isErr()) {
            $failure = $admitted->unwrapErr();

            return $failure instanceof MissingRequiredInput ? Ok(None()) : $admitted;
        }

        return $runtime->within(
            $this->scope,
            $admitted->unwrap(),
            fn(): Result => $this->node->evaluate($runtime),
        );
    }
}
