<?php

declare(strict_types=1);

namespace Superscript\Axiom\Fields;

use InvalidArgumentException;

/**
 * Stage one of {@see Field::on()}: the opaque identity is fixed, name next.
 */
final readonly class FieldBuilder
{
    public function __construct(
        private string $identity,
    ) {}

    public function named(string $name): NamedFieldBuilder
    {
        if ($name === '') {
            throw new InvalidArgumentException('A field name cannot be empty.');
        }

        return new NamedFieldBuilder($this->identity, $name);
    }
}
