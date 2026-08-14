<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

use LogicException;

/**
 * @internal The evaluation of a node or operation that never certified.
 *
 * A node typed {@see \Superscript\Axiom\Types\ErrorType} still needs an
 * evaluation to be a {@see \Superscript\Axiom\CompiledNode} at all, and
 * nothing may ever run it: a Program cannot be minted from a tree carrying
 * one. Reaching this is a defect in that guard, not a program error.
 */
final class UnreachableEvaluation
{
    public static function refuse(mixed ...$operands): never
    {
        throw new LogicException('A node that failed to compile has no evaluation; this program was never certified.');
    }
}
