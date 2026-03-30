<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Source;

final readonly class MemberAccessSource implements Source
{
    public function __construct(
        public Source $object,
        public string $property,
    ) {}
}
