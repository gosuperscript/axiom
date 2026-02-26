<?php

declare(strict_types=1);

namespace Superscript\Axiom;

interface Describable
{
    /**
     * Return a human-readable description of this source,
     * suitable for textualising the source tree for AI consumption.
     */
    public function describe(): string;
}
