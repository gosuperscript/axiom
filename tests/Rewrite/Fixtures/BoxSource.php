<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Rewrite\Fixtures;

use Superscript\Axiom\Describable;
use Superscript\Axiom\Source;

/** A host source with a child: transparent at compile time, so only its descent is under test. */
final readonly class BoxSource implements Source, Describable
{
    public function __construct(public Source $inner) {}

    public function describe(): string
    {
        return sprintf('box(%s)', $this->inner instanceof Describable ? $this->inner->describe() : $this->inner::class);
    }
}
