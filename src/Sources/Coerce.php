<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;
use Superscript\Axiom\Types\Type;

/**
 * Convert a messy value into the declared type via coerce(): '5' becomes
 * 5 under Coerce(Number, …). The checker takes the declared type at face
 * value and never asks whether the conversion can succeed — a conversion
 * that fails at runtime is an Err naming this node. For a checked claim
 * about a value that should already inhabit a type, use {@see Ascription}.
 *
 * @template T = mixed
 * @implements Source<T>
 */
final readonly class Coerce implements Source, Describable
{
    use DescribesTypedSource;

    /**
     * @param Type<T> $type
     */
    public function __construct(
        public Type $type,
        public Source $source,
    ) {}

    public function describe(): string
    {
        return sprintf('%s (as %s)', $this->describeSource($this->source), $this->describeType($this->type));
    }
}
