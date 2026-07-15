<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types\Shapes;

/**
 * Nominal head, structural parameters: related only under the same
 * identity, then parameter-wise by the ordinary relations —
 * Opaque('money', ['currency' => Literal('GBP')]) is assignable to
 * Opaque('money', ['currency' => 'GBP' | 'USD']).
 *
 * The shape for object-valued domain types: no structural claims, no field
 * access, no record interop — and therefore no fictional fields. A
 * parameterless opaque is a plain nominal identity (claim IDs, catalogue
 * keys).
 */
final class OpaqueShape extends Shape
{
    /**
     * @param array<string, Shape> $parameters
     */
    public function __construct(
        public readonly string $identity,
        public readonly array $parameters = [],
    ) {}

    public function equals(Shape $other): bool
    {
        if (!$other instanceof self || $this->identity !== $other->identity || count($this->parameters) !== count($other->parameters)) {
            return false;
        }

        return array_all(
            $this->parameters,
            fn(Shape $parameter, string $name) => isset($other->parameters[$name]) && $parameter->equals($other->parameters[$name]),
        );
    }
}
