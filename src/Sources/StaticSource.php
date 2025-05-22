<?php

declare(strict_types=1);

namespace Superscript\Abacus\Sources;

use Superscript\Abacus\Source;

final readonly class StaticSource implements Source
{
    public function __construct(
        public mixed $value,
    ) {}
}
