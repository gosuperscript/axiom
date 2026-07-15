<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Fixtures;

use Superscript\Axiom\ResolutionInspector;

final class SpyInspector implements ResolutionInspector
{
    /** @var array<string, mixed> */
    public array $annotations = [];

    /** @var list<array{string, mixed}> */
    public array $timeline = [];

    public function annotate(string $key, mixed $value): void
    {
        $this->annotations[$key] = $value;
        $this->timeline[] = [$key, $value];
    }
}
