<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;

final readonly class ExpressionPattern implements MatchPattern, Describable
{
    public function __construct(
        public Source $source,
    ) {}

    public function describe(): string
    {
        return $this->source instanceof Describable
            ? $this->source->describe()
            : (new \ReflectionClass($this->source))->getShortName();
    }
}
