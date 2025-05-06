<?php

namespace Superscript\Abacus\Operators;

use UnhandledMatchError;
use function Psl\Type\mixed_dict;
use function Psl\Type\mixed_vec;
use function Psl\Type\scalar;
use function Psl\Type\union;

final readonly class DefaultOverloader implements OperatorOverloader
{
    /**
     * @var list<OperatorOverloader>
     */
    private array $overloaders;

    public function __construct()
    {
        $this->overloaders = [
            new BinaryOverloader(),
            new ComparisonOverloader(),
            new HasOverloader(),
            new InOverloader(),
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

    public function evaluate(mixed $left, mixed $right, string $operator): mixed
    {
        foreach ($this->overloaders as $overloader) {
            if ($overloader->supportsOverloading($left, $right, $operator)) {
                return $overloader->evaluate($left, $right, $operator);
            }
        }

        throw new UnhandledMatchError("Operator [$operator] is not supported.");
    }
}