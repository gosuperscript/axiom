<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Resolvers\Fixtures;

use Superscript\Axiom\Source;

final readonly class CustomSource implements Source
{
    public function __construct(
        public string $tag,
    ) {}
}
