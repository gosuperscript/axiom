<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * The sealed structural vocabulary type relations are defined over.
 *
 * The set of shapes is closed: relation rules do exhaustive case analysis
 * over these constructors, so extension happens by *projection* (a domain
 * type implements Shaped and maps into this vocabulary), never by adding
 * a constructor.
 */
abstract class Shape
{
    abstract public function equals(Shape $other): bool;
}
