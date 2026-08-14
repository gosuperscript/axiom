<?php

declare(strict_types=1);

namespace Superscript\Axiom\Spike;

use Superscript\Axiom\Types\TypeMismatch;

/** SPIKE ONLY. One accumulated refusal, located the way compile() locates its one refusal. */
final readonly class SpikeDiagnostic
{
    public function __construct(
        public string $path,
        public TypeMismatch $mismatch,
    ) {}

    public function message(): string
    {
        return $this->mismatch->message;
    }

    public function describe(): string
    {
        return sprintf('[%s] %s', $this->path, $this->mismatch->describe());
    }
}
