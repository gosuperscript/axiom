<?php

declare(strict_types=1);

namespace Superscript\Lookups\Tests\Fixtures;

final readonly class Dependency
{
    public function __construct(
        public string $info,
    ) {}
}
