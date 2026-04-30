<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;

final readonly class MemberAccessSource implements Source, Describable
{
    public function __construct(
        public Source $object,
        public string $property,
    ) {}

    public function describe(): string
    {
        $object = $this->object instanceof Describable
            ? $this->object->describe()
            : (new \ReflectionClass($this->object))->getShortName();

        return sprintf('%s.%s', $object, $this->property);
    }
}
