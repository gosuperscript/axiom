<?php

declare(strict_types=1);

namespace Superscript\Axiom\Predicates;

use Superscript\Axiom\Source;
use Throwable;

/** A proposition whose meaning is deliberately opaque to the relation. */
final readonly class Atom extends Predicate
{
    public function __construct(public Source $source) {}

    public function equals(Predicate $other): bool
    {
        if (!$other instanceof self) {
            return false;
        }

        if ($this->source === $other->source) {
            return true;
        }

        try {
            return serialize($this->source) === serialize($other->source);
        } catch (Throwable) {
            // A Source is persistable description data. If a host source breaks
            // that contract, equality remains conservatively unproved.
            return false;
        }
    }
}
