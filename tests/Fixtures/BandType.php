<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Fixtures;

use InvalidArgumentException;
use Superscript\Axiom\Types\HasValueIdentity;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Type;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * Bands keyed by their numeric bounds, so 1..5 and 1.0..5 share one
 * identity. The identity name is a parameter so tests can build two
 * nominally distinct band types whose values share keys, and the unit
 * gives the base a literal parameter.
 *
 * @implements Type<Band>
 */
final readonly class BandType implements Type, HasValueIdentity
{
    public function __construct(
        private string $identity = 'band',
        private ?string $unit = null,
    ) {}

    public function assert(mixed $value): Result
    {
        return $value instanceof Band
            ? Ok(Some($value))
            : Err(new InvalidArgumentException('Expected a band.'));
    }

    public function coerce(mixed $value): Result
    {
        return $value === null ? Ok(None()) : $this->assert($value);
    }

    public function format(mixed $value): string
    {
        assert($value instanceof Band);

        return sprintf('%s..%s', $value->lower, $value->upper);
    }

    public function shape(): Shape
    {
        return new OpaqueShape($this->identity, $this->unit === null ? [] : ['unit' => new LiteralShape($this->unit)]);
    }

    public function identityKey(mixed $value): string
    {
        assert($value instanceof Band);

        return var_export((float) $value->lower, true) . '..' . var_export((float) $value->upper, true);
    }
}
