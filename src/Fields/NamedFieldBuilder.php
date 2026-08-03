<?php

declare(strict_types=1);

namespace Superscript\Axiom\Fields;

use Superscript\Axiom\Types\Type;

/**
 * Stage two: the field's return type — what `opaque.name` certifies to.
 */
final readonly class NamedFieldBuilder
{
    public function __construct(
        private string $identity,
        private string $name,
    ) {}

    public function returns(Type $returns): TypedFieldBuilder
    {
        return new TypedFieldBuilder($this->identity, $this->name, $returns);
    }
}
