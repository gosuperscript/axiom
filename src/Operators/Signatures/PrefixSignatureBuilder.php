<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators\Signatures;

use InvalidArgumentException;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;

/**
 * Stage one of {@see \Superscript\Axiom\Operators\Operator::prefix()}.
 */
final readonly class PrefixSignatureBuilder
{
    public function __construct(private string $operator) {}

    /**
     * An Option operand is refused loudly: absence never reaches a unary
     * rule — the resolver short-circuits absent operands before any rule
     * runs and optionality propagates structurally — so an Option signature
     * would declare a claim that can never fire.
     */
    public function signature(Type $operand): PrefixSignatureWithOperand
    {
        if ($operand->shape() instanceof OptionShape) {
            throw new InvalidArgumentException(sprintf(
                'A prefix signature cannot declare an Option operand (%s): absence never reaches a unary rule, so the claim could never fire. Declare the present type; optionality propagates structurally.',
                TypeDescriber::describe($operand),
            ));
        }

        return new PrefixSignatureWithOperand($this->operator, $operand);
    }
}
