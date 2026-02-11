<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Monads\Result\Result;
use UnhandledMatchError;
use function Superscript\Monads\Result\Err;

final readonly class DefaultOverloader implements OperatorOverloader
{
    /**
     * @var list<OperatorOverloader>
     */
    private array $overloaders;

    public function __construct()
    {
        $this->overloaders = [
            new NullOverloader(),
            new BinaryOverloader(),
            new ComparisonOverloader(),
            new HasOverloader(),
            new InOverloader(),
            new LogicalOverloader(),
            new IntersectsOverloader(),
        ];
    }

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        foreach ($this->overloaders as $overloader) {
            if ($overloader->supportsOverloading($left, $right, $operator)) {
                return true;
            }
        }

        return false;
    }

    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        foreach ($this->overloaders as $overloader) {
            if ($overloader->supportsOverloading($left, $right, $operator)) {
                return $overloader->evaluate($left, $right, $operator);
            }
        }

        return Err(new UnhandledMatchError("Operator [$operator] is not supported."));
    }
}
