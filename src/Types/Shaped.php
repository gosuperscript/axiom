<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Types\Shapes\Shape;

/**
 * A type that owns its projection into the sealed shape vocabulary.
 * Adding a type is adding a shape — never editing a relation.
 */
interface Shaped
{
    public function shape(): Shape;
}
