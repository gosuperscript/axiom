<?php

declare(strict_types=1);

namespace Superscript\Axiom\Fields;

use Closure;
use LogicException;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * A computed field on an opaque type, with its static and runtime semantics
 * in one value — like an operator rule. The (identity, name) pair selects it,
 * {@see self::$returns} is what `opaque.name` certifies, and the extractor
 * reads the value off the concrete runtime object.
 *
 * The extractor must be total over every value of the opaque type and return
 * an inhabitant of the declared return type — the raw value, never an Option.
 * Null is absence, and only an Option-typed field can be absent: null from an
 * extractor on a non-optional field is refused as a defect of the declaration
 * ({@see self::extract()}), not passed downstream as a value the certificate
 * never promised. Value-dependent partiality is still allowed: an extractor
 * may return an Err; a throw is a defect of the declaration, not a property
 * of the input, and propagates.
 */
final readonly class OpaqueField
{
    public function __construct(
        public string $identity,
        public string $name,
        public Type $returns,
        private Closure $extractor,
    ) {}

    /**
     * A plain return value is wrapped in Ok(Some); a returned Result passes
     * through (value-dependent partiality) with the same normalization on its
     * Ok value; a throw propagates. Null becomes None on an Option-typed
     * field and an Err on any other — the certified type promised a value.
     *
     * @return Result<Option<mixed>, Throwable>
     */
    public function extract(mixed $value): Result
    {
        $extracted = ($this->extractor)($value);

        if ($extracted instanceof Result) {
            /** @var Result<mixed, Throwable> $extracted */
            return $extracted->andThen(fn(mixed $inner): Result => $this->normalize($inner));
        }

        return $this->normalize($extracted);
    }

    /** @return Result<Option<mixed>, Throwable> */
    private function normalize(mixed $extracted): Result
    {
        if ($extracted !== null) {
            return Ok(Some($extracted));
        }

        if ($this->returns->shape() instanceof OptionShape) {
            return Ok(None());
        }

        return Err(new LogicException(sprintf(
            'Field [%s.%s] is declared %s but its extractor returned null; declare an Option return type when the field can be absent.',
            $this->identity,
            $this->name,
            TypeDescriber::describe($this->returns),
        )));
    }
}
