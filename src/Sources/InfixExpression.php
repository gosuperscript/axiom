<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;

final readonly class InfixExpression implements Source, Describable
{
    public function __construct(
        public Source $left,
        public string $operator,
        public Source $right,
    ) {}

    public function describe(): string
    {
        $left = $this->left instanceof Describable
            ? $this->left->describe()
            : (new \ReflectionClass($this->left))->getShortName();

        $right = $this->right instanceof Describable
            ? $this->right->describe()
            : (new \ReflectionClass($this->right))->getShortName();

        return sprintf('(%s %s %s)', $left, $this->operator, $right);
    }
}
