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
 * A named type whose membership only the owning host can check. Statically
 * it relates to other opaques of the same identity, parameter-wise;
 * dynamically assert and coerce reject every value, with an error naming
 * the host as the owner — this library has no way to verify membership of
 * a host-owned type, and accepting values it cannot verify would let any
 * value pass as any opaque type.
 *
 * @internal Exists only so an opaque field or parameter shape can be
 * turned back into a Type (see TypeReifier). Never declare inputs with it
 * — a host with a real domain class ships its own Shaped type with a real
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
