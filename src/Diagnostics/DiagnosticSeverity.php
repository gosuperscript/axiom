<?php

declare(strict_types=1);

namespace Superscript\Axiom\Diagnostics;

enum DiagnosticSeverity: string
{
    case Error = 'error';
    case Warning = 'warning';
    case Info = 'info';
}
