<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Context;
use Superscript\Axiom\Source;
use Superscript\Axiom\Types\Type;

/**
 * Coerces the resolved value of the inner {@see Source} into $target using
 * {@see Type::coerce()}. The source's static type is always $target — this
 * is the explicit "convert at this boundary" escape hatch that replaces
 * the old TypeDefinition.
 *
 * Example: `new CoerceSource(new StaticSource('5'), new NumberType())`
 * resolves to int 5.
 */
final readonly class CoerceSource implements Source
{
    public function __construct(
        public Source $source,
        public Type $target,
    ) {}

    public function type(Context $context): Type
    {
        return $this->target;
    }
}
