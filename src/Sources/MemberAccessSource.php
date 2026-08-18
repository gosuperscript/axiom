<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use InvalidArgumentException;
use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;

final readonly class MemberAccessSource implements Source, Describable
{
    public function __construct(
        public Source $object,
        public string $property,
    ) {
        if ($property === '') {
            throw new InvalidArgumentException('A member access property must be non-empty.');
        }

        if (str_contains($property, '.')) {
            throw new InvalidArgumentException('A member access property cannot contain a dot. Nest MemberAccessSource nodes for structural access.');
        }
    }

    public function describe(): string
    {
        $object = $this->object instanceof Describable
            ? $this->object->describe()
            : (new \ReflectionClass($this->object))->getShortName();

        return sprintf('%s.%s', $object, $this->property);
    }
}
