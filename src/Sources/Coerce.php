<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;
use Superscript\Axiom\Types\Type;

/**
 * The boundary node: convert a messy value into the declared type via
 * coerce(). Statically opaque by design — coercion is admission policy,
 * not membership, so the checker takes the declared type verbatim and
 * never reasons about satisfiability. For a checked claim about a value
 * that already inhabits a type, use {@see Ascription}.
 *
 * (Formerly named TypeDefinition — retired because it always behaved as
 * coercion while "definition" read as annotation.)
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
