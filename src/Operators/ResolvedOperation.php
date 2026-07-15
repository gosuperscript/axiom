<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Closure;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Result\Result;
use Throwable;

use function Superscript\Monads\Result\Ok;

/**
 * The success of {@see OperatorOverloader::resolve()}: the return type
 * and the evaluation, together in one value — the compiler keeps the type
 * and binds the evaluation into the program, so a node can never run
 * under a different rule than the one that typed it.
 *
 * The evaluation closure takes one operand for unary rules and two for
 * binary rules, and must be total over the operand types the rule resolved
 * for — every value of those types evaluates without escaping, to a result
 * inhabiting the returned type. Value-dependent partiality remains: a
 * closure may return an Err (division by zero); a throw is a defect of the
 * rule, not a property of the input, and propagates.
 */
final readonly class ResolvedOperation
{
    public function __construct(
        public Type $returns,
        private Closure $evaluation,
    ) {}

    /**
     * A plain return value is wrapped in Ok; a returned Result passes
     * through (value-dependent partiality); a throw propagates.
     *
     * @return Result<mixed, Throwable>
     */
    public function evaluate(mixed ...$operands): Result
    {
        $value = ($this->evaluation)(...$operands);

        if ($value instanceof Result) {
            /** @var Result<mixed, Throwable> $value */
            return $value;
        }

        return Ok($value);
    }
}
