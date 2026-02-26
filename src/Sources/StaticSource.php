<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use SebastianBergmann\Exporter\Exporter;
use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;

final readonly class StaticSource implements Source, Describable
{
    public function __construct(
        public mixed $value,
    ) {}

    public function describe(): string
    {
        return sprintf('the value %s', (new Exporter())->shortenedExport($this->value));
    }
}
