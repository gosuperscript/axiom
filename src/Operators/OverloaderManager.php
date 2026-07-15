<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;
use Webmozart\Assert\Assert;

/**
 * The composed binary dialect: one list of rules, resolved once per
 * operator node at compile time, reconciled by the shared composition
 * rule ({@see OverloadResolution}) — one owner per operand types,
 * ambiguity refused, unhandled refusals kept out of the diagnostics.
 */
class OverloaderManager implements OperatorOverloader
{
    public function __construct(
        /** @var list<OperatorOverloader> */
        private array $overloaders,
    ) {
        Assert::allIsInstanceOf($this->overloaders, OperatorOverloader::class);
    }

    /** @return Result<ResolvedOperation, TypeMismatch> */
    public function resolve(string $operator, Type $left, Type $right): Result
    {
        $operands = sprintf('%s and %s', TypeDescriber::describe($left), TypeDescriber::describe($right));

        return OverloadResolution::across(
            $this->overloaders,
            fn(OperatorOverloader $overloader) => $overloader->resolve($operator, $left, $right),
            ambiguity: fn(array $owners) => sprintf(
                'Operator [%s] over %s is ambiguous: [%s] all resolve it. A composed dialect has exactly one owner for any operand types.',
                $operator,
                $operands,
                implode('], [', $owners),
            ),
            unsupported: sprintf('Operator [%s] is not supported.', $operator),
            unaccepted: sprintf('No overload of [%s] accepts %s.', $operator, $operands),
        );
    }
}
