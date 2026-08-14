<?php

declare(strict_types=1);

namespace Superscript\Axiom\Sources;

use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;

/**
 * Replace absence with a value coerced to the source's present type.
 *
 * @template T = mixed
 * @implements Source<T>
 */
final readonly class DefaultValue implements Source, Describable
{
    /** @param T $default */
    public function __construct(
        public Source $source,
        public mixed $default,
    ) {}

    public function children(): iterable
    {
        return [$this->source];
    }

    public function describe(): string
    {
        $source = $this->source instanceof Describable
            ? $this->source->describe()
            : (new \ReflectionClass($this->source))->getShortName();

        return sprintf('%s ?? %s', $source, (new StaticSource($this->default))->describe());
    }
}
