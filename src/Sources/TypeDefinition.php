<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;
use Superscript\Axiom\Types\Type;

final readonly class TypeDefinition implements Source, Describable
{
    public function __construct(
        public Type $type,
        public Source $source,
    ) {}

    public function describe(): string
    {
        $typeName = lcfirst(preg_replace('/Type$/', '', (new \ReflectionClass($this->type))->getShortName()));
        $sourceDescription = $this->source instanceof Describable
            ? $this->source->describe()
            : (new \ReflectionClass($this->source))->getShortName();

        return sprintf('%s(%s)', $typeName, $sourceDescription);
    }
}
