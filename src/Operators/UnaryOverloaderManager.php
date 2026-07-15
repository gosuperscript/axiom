<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;
use Webmozart\Assert\Assert;

/**
 * The composed unary dialect, reconciled by the same composition rule as
 * the binary one ({@see OverloadResolution}): one owner per operand type,
 * ambiguity refused, unhandled refusals kept out of the diagnostics.
 */
class UnaryOverloaderManager implements UnaryOverloader
{
    public function __construct(
        /** @var list<UnaryOverloader> */
        private array $overloaders,
    ) {
        Assert::allIsInstanceOf($this->overloaders, UnaryOverloader::class);
    }

    /** @return Result<ResolvedOperation, TypeMismatch> */
    public function resolve(string $operator, Type $operand): Result
    {
        $described = TypeDescriber::describe($operand);

        return OverloadResolution::across(
            $this->overloaders,
            fn(UnaryOverloader $overloader) => $overloader->resolve($operator, $operand),
            ambiguity: fn(array $owners) => sprintf(
                'Unary operator [%s] over %s is ambiguous: [%s] all resolve it. A composed dialect has exactly one owner for any operand type.',
                $operator,
                $described,
                implode('], [', $owners),
            ),
            unsupported: sprintf('Unary operator [%s] is not supported.', $operator),
            unaccepted: sprintf('No overload of unary [%s] accepts %s.', $operator, $described),
        );
    }
}
