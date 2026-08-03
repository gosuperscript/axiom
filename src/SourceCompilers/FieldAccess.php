<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use Closure;
use Superscript\Axiom\Types\Type;

/**
 * @internal Pairs a member's certified type with the reader that fetches it
 * at runtime, so both record fields (a reflective key/property read) and
 * opaque fields (a host extractor) flow through one member-access node.
 */
final readonly class FieldAccess
{
    /**
     * @param Closure(mixed): \Superscript\Monads\Result\Result<\Superscript\Monads\Option\Option<mixed>, \Throwable> $read
     */
    public function __construct(
        public Type $returns,
        public Closure $read,
    ) {}
}
