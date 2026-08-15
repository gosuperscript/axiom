<?php

declare(strict_types=1);

namespace Superscript\Axiom\Exceptions;

/**
 * One input a {@see BoundaryViolation} refused, and why: the name the call
 * bound (or failed to bind) and the message about it. An input is answered
 * for once, so a rejection names exactly one binding.
 */
final readonly class RejectedBinding
{
    public function __construct(
        public string $input,
        public string $message,
    ) {}
}
