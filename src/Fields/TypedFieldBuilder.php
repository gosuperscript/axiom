<?php

declare(strict_types=1);

namespace Superscript\Axiom\Fields;

use Superscript\Axiom\Types\Type;

/**
 * Stage three: the extractor completes the field. It receives the concrete
 * runtime value natively — the compiler proved the operand's opaque identity
 * — and returns an inhabitant of the declared return type: the raw value,
 * never an Option. Null means absence and is only legal on an Option-typed
 * field; elsewhere it is refused as a defect of the declaration. A plain
 * return is wrapped in Ok; a returned Result passes through; a throw
 * propagates ({@see OpaqueField::extract()}).
 */
final readonly class TypedFieldBuilder
{
    public function __construct(
        private string $identity,
        private string $name,
        private Type $returns,
    ) {}

    public function extractedWith(callable $extractor): OpaqueField
    {
        return new OpaqueField($this->identity, $this->name, $this->returns, $extractor(...));
    }
}
