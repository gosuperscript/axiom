<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;

final readonly class MatchArm implements Describable
{
    public function __construct(
        public MatchPattern $pattern,
        public Source $expression,
    ) {}

    public function describe(): string
    {
        $pattern = $this->pattern instanceof Describable
            ? $this->pattern->describe()
            : (new \ReflectionClass($this->pattern))->getShortName();

        $expression = $this->expression instanceof Describable
            ? $this->expression->describe()
            : (new \ReflectionClass($this->expression))->getShortName();

        return sprintf('%s => %s', $pattern, $expression);
    }
}
