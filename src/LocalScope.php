<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Analysis\References;

/** @internal Opaque identity and local-read record for one lexical scope. */
final class LocalScope
{
    private References $references;

    public function __construct()
    {
        $this->references = new References();
    }

    public function record(ReferencePath $reference): void
    {
        $this->references->record([$reference]);
    }

    /** @return list<ReferencePath> */
    public function references(): array
    {
        return $this->references->all();
    }
}
