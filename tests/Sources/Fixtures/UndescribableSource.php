<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Sources\Fixtures;

use Superscript\Axiom\Source;

final readonly class UndescribableSource implements Source
{
    public function children(): iterable
    {
        return [];
    }
}
