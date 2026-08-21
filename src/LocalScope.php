<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use LogicException;
use Superscript\Axiom\Analysis\References;

/** @internal Opaque identity and local-read record for one lexical scope. */
final class LocalScope
{
    private References $references;

    private bool $sealed = false;

    public function __construct()
    {
        $this->references = new References();
    }

    public function record(ReferencePath $reference): void
    {
        if ($this->sealed) {
            throw new LogicException('A local scope cannot record reads after its input boundary is sealed.');
        }

        $this->references->record([$reference]);
    }

    /** @return list<ReferencePath> */
    public function seal(): array
    {
        $this->sealed = true;

        return $this->references->all();
    }
}
