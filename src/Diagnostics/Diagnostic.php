<?php

declare(strict_types=1);

namespace Superscript\Axiom\Diagnostics;

final readonly class Diagnostic
{
    public function __construct(
        public DiagnosticSeverity $severity,
        public string $message,
        public ?SourceLocation $location = null,
    ) {}
}
