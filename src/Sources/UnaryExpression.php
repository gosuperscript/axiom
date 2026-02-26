<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;

final readonly class UnaryExpression implements Source, Describable
{
    public function __construct(
        public string $operator,
        public Source $operand,
    ) {}

    public function describe(): string
    {
        $operand = $this->operand instanceof Describable
            ? $this->operand->describe()
            : (new \ReflectionClass($this->operand))->getShortName();

        return sprintf('(%s%s)', $this->operator, $operand);
    }
}
