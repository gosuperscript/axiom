<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;
use Superscript\Axiom\Types\Type;

/**
 * The author's type claim on an existing value: "this already is a T".
 * The claim is checked twice. At compile time the inner type must be
 * Unknown or overlap the declaration — claiming Number on something
 * inferred String is a compile error. At runtime the value must pass
 * assert() — a false claim is a loud error at this exact node, never a
 * wrong value flowing on.
 *
 * Use to refine an Unknown host source or narrow a union. For converting
 * a value INTO a type, use {@see Coerce}.
 *
 * @template T = mixed
 * @implements Source<T>
 */
final readonly class Ascription implements Source, Describable
{
    use DescribesTypeBridge;

    /**
     * @param Type<T> $type
     */
    public function __construct(
        public Type $type,
        public Source $source,
    ) {}

    public function children(): iterable
    {
        return [$this->source];
    }

    public function describe(): string
    {
        return sprintf('%s (is %s)', $this->describeSource($this->source), $this->describeType($this->type));
    }
}
