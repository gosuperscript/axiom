<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use SebastianBergmann\Exporter\Exporter;
use Superscript\Axiom\Describable;

final readonly class LiteralPattern implements MatchPattern, Describable
{
    public function __construct(
        public mixed $value,
    ) {}

    public function describe(): string
    {
        return (new Exporter())->shortenedExport($this->value);
    }
}
