<?php

declare(strict_types=1);

namespace Superscript\Schema\Sources;

use Closure;
use InvalidArgumentException;
use Superscript\Monads\Option\Option;
use Superscript\Schema\Resolvers\Resolver;
use Superscript\Schema\Source;

use function Psl\Type\num;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

final readonly class UnaryExpression implements Source
{
    public function __construct(
        public string $operator,
        public Source $operand,
    ) {}

    public function resolver(): Closure
    {
        return fn(Resolver $resolver) => $resolver->resolve($this->operand)
            ->andThen(fn(Option $option) => $option
                ->map(fn(mixed $value) => match ($this->operator) {
                    '!' => Ok(!$value),
                    '-' => num()->matches($value) ? Ok(-$value) : Err(new InvalidArgumentException("not numeric")),
                    default => Err(new InvalidArgumentException("Unsupported operator: {$this->operator}")),
                })
                ->transpose());
    }
}
