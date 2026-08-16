<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

use ReflectionClass;
use Superscript\Axiom\Describable;

/**
 * How a report renders one node. A rewrite report is read by a human
 * deciding whether to take the new tree, so it shows the node the way the
 * language spells it whenever the node can spell itself, and falls back to
 * its class name when a host node cannot.
 *
 * @internal
 */
final readonly class Describes
{
    public static function node(object $node): string
    {
        return $node instanceof Describable
            ? $node->describe()
            : (new ReflectionClass($node))->getShortName();
    }
}
