<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Closure;
use Superscript\Axiom\Analysis\OperatorRuleProvenance;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Result\Result;
use Throwable;

use function Superscript\Monads\Result\Ok;

/**
 * The successful verdict of an operator rule or structural elaboration: the return type
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
final readonly class ResolvedOperation implements OperatorResolution
{
    public function __construct(
        public Type $returns,
        private Closure $evaluation,
        public ?OperatorRuleProvenance $provenance = null,
    ) {}

    /** @internal The resolver attaches the identity of the rule it selected. */
    public function attributedTo(OperatorRuleProvenance $provenance): self
    {
        return new self($this->returns, $this->evaluation, $provenance);
    }

    /**
     * The absence-propagating form of an operation whose rule matched on the
     * operands' present types: an absent operand answers absence without the
     * rule running, and the return type becomes optional. This is the same
     * law {@see \Superscript\Axiom\CompiledSource::mapPresent()} applies to
     * one child — operations are strict in present values; the boundary
     * decides what absence means.
     *
     * @internal The resolvers construct lifted operations.
     */
    public function liftedOverAbsence(): self
    {
        $evaluation = $this->evaluation;

        return new self(
            $this->returns->shape() instanceof OptionShape ? $this->returns : new OptionType($this->returns),
            static fn(mixed ...$operands) => in_array(null, $operands, true) ? null : $evaluation(...$operands),
            $this->provenance,
        );
    }

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
