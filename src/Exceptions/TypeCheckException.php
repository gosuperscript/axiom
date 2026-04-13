<?php

declare(strict_types=1);

namespace Superscript\Axiom\Exceptions;

use RuntimeException;
use Superscript\Axiom\Source;
use Throwable;

/**
 * Thrown by {@see \Superscript\Axiom\TypeChecker} when an expression cannot
 * be proven type-safe. The offending {@see Source} node is attached so
 * callers can pinpoint the failure without re-walking the tree.
 */
class TypeCheckException extends RuntimeException
{
    public function __construct(
        string $reason,
        public readonly Source $node,
        ?Throwable $previous = null,
    ) {
        parent::__construct($reason, previous: $previous);
    }
}
