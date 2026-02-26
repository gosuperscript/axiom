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
        $left = $this->describeOperand($this->left);
        $right = $this->describeOperand($this->right);

        return sprintf('%s %s %s', $left, $this->operator, $right);
    }

    private function describeOperand(Source $source): string
    {
        $description = $source instanceof Describable
            ? $source->describe()
            : (new \ReflectionClass($source))->getShortName();

        if ($source instanceof self) {
            return sprintf('(%s)', $description);
        }

        return $description;
    }
}
