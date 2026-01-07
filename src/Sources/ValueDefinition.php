<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Source;
use Superscript\Axiom\Types\Type;

final readonly class ValueDefinition implements Source
{
    public function __construct(
        public Type $type,
        public Source $source,
    ) {}
}
