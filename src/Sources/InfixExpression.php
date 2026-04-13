<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Context;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Source;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnresolvedType;

final readonly class InfixExpression implements Source
{
    public function __construct(
        public Source $left,
        public string $operator,
        public Source $right,
    ) {}

    public function type(Context $context): Type
    {
        $left = $this->left->type($context);
        $right = $this->right->type($context);

        if ($left instanceof UnresolvedType) {
            return $left;
        }
        if ($right instanceof UnresolvedType) {
            return $right;
        }

        $overloader = $context->operators;
        if ($overloader === null) {
            return new UnresolvedType(
                'no operator overloader available to type-check ' . $left->name() . ' ' . $this->operator . ' ' . $right->name(),
            );
        }

        $inferred = $overloader->inferType($left, $right, $this->operator);
        if ($inferred->isSome()) {
            return $inferred->unwrap();
        }

        return new UnresolvedType(
            'no operator overload for ' . $left->name() . ' ' . $this->operator . ' ' . $right->name(),
        );
    }
}
