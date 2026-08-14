<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

use Superscript\Axiom\Types\TypeMismatch;

/**
 * One refusal collected by a {@see Diagnosis}, located the way
 * {@see \Superscript\Axiom\Expression::compile()} locates its single one:
 * `$path` is the node the refusal was stamped with as it left the node that
 * made it (first location wins), and is null for a refusal about the whole
 * program rather than a position in it — a definition cycle.
 *
 * It carries no more than the {@see TypeMismatch} it wraps, and is kept
 * anyway because it is the vocabulary a reader of a diagnosis sees.
 * Severity, related locations and suggested fixes are things a diagnostic
 * grows; each would be wrong on a TypeMismatch, which is a verdict the
 * compiler reaches rather than a report anyone reads.
 */
final readonly class Diagnostic
{
    public ?string $path;

    public string $message;

    public function __construct(public TypeMismatch $mismatch)
    {
        $this->path = $mismatch->path;
        $this->message = $mismatch->message;
    }

    /** The full cause chain, prefixed with the node when there is one. */
    public function describe(): string
    {
        if ($this->path === null) {
            return $this->mismatch->describe();
        }

        return sprintf('[%s] %s', $this->path, $this->mismatch->describe());
    }
}
