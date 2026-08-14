<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use SebastianBergmann\Exporter\Exporter;
use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;

/**
 * @template T = mixed
 * @implements Source<T>
 */
final readonly class StaticSource implements Source, Describable
{
    /**
     * @param T $value
     */
    public function __construct(
        public mixed $value,
    ) {}

    public function describe(): string
    {
        return (new Exporter())->shortenedExport($this->value);
    }
}
