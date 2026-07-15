<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use InvalidArgumentException;
use SebastianBergmann\Exporter\Exporter;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;

/**
 * A nominal head core cannot inspect: statically it relates only under its
 * identity (parameter-wise); dynamically it is fail-closed — core cannot
 * verify membership of a host-owned identity, so assert and coerce reject
 * every value and name the host as the owner. A fail-open placeholder
 * would duplicate Unknown's job while wearing a nominal certificate, and
 * would claim every non-null value for any signature declared over it.
 *
 * @internal Reification artifact: exists only so opaque field and parameter
 * shapes can reify back to a Type. Never a host-facing declaration type —
 * hosts with real domain classes ship their own Shaped types with a real
 * membership check.
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
        return Err(new InvalidArgumentException(sprintf(
            'Core cannot verify membership of opaque identity [%s]; the host that owns the identity owns the membership check — declare a host-owned type instead.',
            $this->identity,
        )));
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
