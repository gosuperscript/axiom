<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use SebastianBergmann\Exporter\Exporter;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * A nominal type core cannot inspect: statically it relates only under its
 * identity (parameter-wise), dynamically it is unverifiable here — the
 * host that owns the identity owns the membership check. Unknown's runtime
 * posture with a nominal shape. Produced by reification of opaque field
 * and parameter shapes; hosts with real domain classes ship their own
 * Shaped types instead.
 *
 * @implements Type<mixed>
 */
final readonly class OpaqueType implements Type
{
    /**
     * @param array<string, Type> $parameters
     */
    public function __construct(
        public string $identity,
        public array $parameters = [],
    ) {}

    public function assert(mixed $value): Result
    {
        return Ok($value === null ? None() : Some($value));
    }

    public function coerce(mixed $value): Result
    {
        return $this->assert($value);
    }

    public function compare(mixed $a, mixed $b): bool
    {
        return $a === $b;
    }

    public function format(mixed $value): string
    {
        return (new Exporter())->export($value);
    }

    public function shape(): Shape
    {
        $parameters = [];

        foreach ($this->parameters as $name => $parameter) {
            $parameters[$name] = $parameter->shape();
        }

        return new OpaqueShape($this->identity, $parameters);
    }
}
