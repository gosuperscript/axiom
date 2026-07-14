<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;
use UnhandledMatchError;

use function Superscript\Monads\Result\Err;

final readonly class DefaultOverloader implements OperatorOverloader
{
    private OverloaderManager $manager;

    public function __construct()
    {
        $this->manager = new OverloaderManager([
            new NullOverloader(),
            new BinaryOverloader(),
            new ComparisonOverloader(),
            new HasOverloader(),
            new InOverloader(),
            new LogicalOverloader(),
            new IntersectsOverloader(),
        ]);
    }

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return $this->manager->supportsOverloading($left, $right, $operator);
    }

    /** @return Result<mixed, \Throwable> */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        if (!$this->manager->supportsOverloading($left, $right, $operator)) {
            return Err(new UnhandledMatchError("Operator [$operator] is not supported."));
        }

        return $this->manager->evaluate($left, $right, $operator);
    }

    public function handles(string $operator): bool
    {
        return $this->manager->handles($operator);
    }

    /**
     * @return Result<Type, TypeMismatch>
     */
    public function typeOf(string $operator, Type $left, Type $right): Result
    {
        return $this->manager->typeOf($operator, $left, $right);
    }
}
