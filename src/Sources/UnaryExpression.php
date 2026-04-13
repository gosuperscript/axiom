<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Context;
use Superscript\Axiom\Source;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnresolvedType;

final readonly class UnaryExpression implements Source
{
    public function __construct(
        public string $operator,
        public Source $operand,
    ) {}

    public function type(Context $context): Type
    {
        $operand = $this->operand->type($context);

        if ($operand instanceof UnresolvedType) {
            return $operand;
        }

        return match ($this->operator) {
            '!', 'not' => new BooleanType(),
            '-' => $operand instanceof NumberType
                ? new NumberType()
                : new UnresolvedType("unary '-' requires numeric operand, got " . $operand->name()),
            default => new UnresolvedType("unsupported unary operator '{$this->operator}'"),
        };
    }
}
