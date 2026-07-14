<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;
use Superscript\Axiom\Types\Type;

/**
 * The author's type claim on an existing value: "this already is a T".
 * Runtime verifies via assert() and fails loudly — a lying ascription is a
 * tripwire, not a rot vector. Statically checked: the inner type must be
 * Unknown or overlap the declaration; a disjoint claim is an error.
 *
 * Use to refine an Unknown host source or narrow a union. For converting
 * a value INTO a type, use {@see Coerce}.
 *
 * @template T = mixed
 * @implements Source<T>
 */
final readonly class Ascription implements Source, Describable
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
        return sprintf('%s (is %s)', $this->describeSource($this->source), $this->describeType($this->type));
    }
}
