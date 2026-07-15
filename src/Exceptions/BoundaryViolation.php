<?php

declare(strict_types=1);

namespace Superscript\Axiom\Exceptions;

use RuntimeException;

/**
 * Declared inputs failed at the Expression boundary. Violations are
 * aggregated — every bad, missing, or unlicensed binding is reported at
 * once, named by binding, before any evaluation happens.
 */
final class BoundaryViolation extends RuntimeException
{
    /**
     * @param non-empty-list<string> $violations
     */
    public function __construct(
        public readonly array $violations,
    ) {
        parent::__construct("Bindings rejected at the boundary:\n- " . implode("\n- ", $violations));
    }
}
