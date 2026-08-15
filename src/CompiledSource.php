<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
use Superscript\Axiom\Exceptions\CompilationAborted;
use Superscript\Axiom\Exceptions\EvaluationAborted;
use Superscript\Axiom\Types\ErrorType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\PresentType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * The source-compiler-facing form of a compiled source: its certified return
 * type plus composable evaluation. Axiom keeps Runtime, Result<Option<...>>,
 * node construction, and observation scopes behind this interface.
 */
final readonly class CompiledSource
{
    public Type $returns;

    /** @internal Use SourceCompilation to construct compiled sources. */
    public function __construct(private CompiledNode $node)
    {
        $this->returns = $node->returns;
    }

    /**
     * Did the source this came from fail to compile? Error-tolerant
     * compilation types such a source {@see ErrorType} so the walk can carry
     * on around it, which leaves a compiler holding a child whose type is a
     * placeholder rather than a claim. A compiler about to judge that child
     * has nothing to judge and absorbs instead, which the judgments on
     * {@see SourceCompilation} do for it — this is the question they ask.
     */
    public function failed(): bool
    {
        return $this->returns instanceof ErrorType;
    }

    /**
     * Transform a present value. Absence propagates without invoking the
     * callback and makes the result type optional. $returns describes the
     * callback's present result. Plain values succeed; Results pass through.
     */
    public function mapPresent(Type $returns, callable $evaluate): self
    {
        $evaluate = $evaluate(...);
        $returns = $this->propagateAbsence($returns);

        return new self(new CompiledNode($returns, function (Runtime $runtime) use ($evaluate) {
            return $this->node->evaluate($runtime)->andThen(function ($option) use ($evaluate) {
                if ($option->isNone()) {
                    return Ok(None());
                }

                return self::normalize(fn() => $evaluate($option->unwrap()));
            });
        }));
    }

    /**
     * Certify the value seen by mapPresent(). For an optional child this
     * checks its present member; absence remains structural and propagates.
     */
    public function expectPresent(Type $expected): self
    {
        $present = PresentType::of($this->returns);
        $admitted = TypeRelations::admits($present, $expected);

        if ($admitted->isErr()) {
            throw new CompilationAborted(new TypeMismatch(
                sprintf(
                    'This source must provide %s when present; it provides %s.',
                    TypeDescriber::describe($expected),
                    TypeDescriber::describe($this->returns),
                ),
                [$admitted->unwrapErr()],
            ));
        }

        return $this;
    }

    /**
     * Transform a value while representing absence as null. The callback is
     * always invoked after the child successfully evaluates.
     */
    public function mapIncludingAbsent(Type $returns, callable $evaluate): self
    {
        $evaluate = $evaluate(...);

        return new self(new CompiledNode($returns, fn(Runtime $runtime) => $this->node
            ->evaluate($runtime)
            ->andThen(fn($option) => self::normalize(
                fn() => $evaluate($option->unwrapOr(null)),
            ))));
    }

    /** Apply a unary operation to present values; absence propagates. */
    public function apply(BoundOperation $operation, ?Type $returns = null): self
    {
        return $this->mapPresent(
            $returns ?? $operation->returns,
            fn(mixed $operand) => $operation($operand),
        );
    }

    /**
     * Advanced escape hatch for lazy and source-specific control flow.
     * Already-compiled children are evaluated through SourceEvaluation.
     */
    public static function custom(Type $returns, callable $evaluate): self
    {
        $evaluate = $evaluate(...);

        return new self(new CompiledNode($returns, fn(Runtime $runtime) => self::normalize(
            fn() => $evaluate(new SourceEvaluation($runtime)),
        )));
    }

    /** Construct a total constant source; null is absence. */
    public static function constant(Type $returns, mixed $value): self
    {
        return new self(new CompiledNode($returns, fn() => Ok(Option::from($value))));
    }

    /** @internal The compiler and Program consume the execution node. */
    public function node(): CompiledNode
    {
        return $this->node;
    }

    /** Mapping a present value preserves optionality without nesting it. */
    private function propagateAbsence(Type $returns): Type
    {
        return $this->returns->shape() instanceof OptionShape
            && !$returns->shape() instanceof OptionShape
                ? new OptionType($returns)
                : $returns;
    }

    /** @return Result<\Superscript\Monads\Option\Option<mixed>, \Throwable> */
    private static function normalize(Closure $evaluate): Result
    {
        try {
            $value = $evaluate();
        } catch (EvaluationAborted $aborted) {
            return Err($aborted->failure);
        }

        if ($value instanceof Result) {
            /** @var Result<mixed, \Throwable> $value */
            return $value->map(Option::from(...));
        }

        return Ok(Option::from($value));
    }
}
