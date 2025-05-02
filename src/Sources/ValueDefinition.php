<?php

namespace Superscript\Abacus\Sources;

use Superscript\Abacus\Source;
use Superscript\Abacus\Types\Type;

final readonly class ValueDefinition implements Source
{
    public function __construct(
        public Type $type,
        public Source $source,
    ) {
    }
}