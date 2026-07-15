<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Fixtures;

use InvalidArgumentException;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/** @implements Type<Money> */
final readonly class MoneyType implements Type
{
    public function __construct(public string $currency) {}

    public function assert(mixed $value): Result
    {
        return $value instanceof Money && $value->currency === $this->currency
            ? Ok(Some($value))
            : Err(new InvalidArgumentException(sprintf('Expected money in %s.', $this->currency)));
    }

    public function coerce(mixed $value): Result
    {
        return $this->assert($value);
    }

    public function format(mixed $value): string
    {
        assert($value instanceof Money);

        return sprintf('%s %d', $value->currency, $value->minor);
    }

    public function shape(): Shape
    {
        return new OpaqueShape('money', [
            'currency' => new LiteralShape($this->currency),
        ]);
    }
}
