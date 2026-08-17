<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Exceptions\EvaluationAborted;

/**
 * Restricted runtime capability for advanced source evaluation. It can run
 * already-compiled children and attach domain-specific observations without
 * exposing Runtime, Result, or Option to the source compiler.
 */
final readonly class SourceEvaluation
{
    /** @internal Constructed while a CompiledSource is evaluated. */
    public function __construct(private Runtime $runtime) {}

    /** Evaluate a child; absence is represented as null. */
    public function value(CompiledSource $source): mixed
    {
        $result = $source->node()->evaluate($this->runtime);

        if ($result->isErr()) {
            throw new EvaluationAborted($result->unwrapErr());
        }

        return $result->unwrap()->unwrapOr(null);
    }

    /**
     * Invoke a separately-bound compiled body with values produced by its
     * owning compiled source.
     *
     * @param array<string, mixed> $bindings
     */
    public function invoke(CompiledSubprogram $program, array $bindings): mixed
    {
        $result = $program->invoke($bindings, $this->runtime->observer);

        if ($result->isErr()) {
            throw new EvaluationAborted($result->unwrapErr());
        }

        return $result->unwrap()->unwrapOr(null);
    }

    public function annotate(string $key, mixed $value): void
    {
        $this->runtime->annotate($key, $value);
    }
}
