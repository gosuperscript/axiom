<?php

declare(strict_types=1);

namespace Superscript\Axiom\SourceCompilers;

use RuntimeException;
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\SourceEvaluation;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeReifier;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/** @internal Compiler for the core member-access source. */
final readonly class MemberAccessSourceCompiler
{
    /**
     * Member access resolves to a {@see FieldAccess}: the field's certified
     * type and the reader that fetches it at runtime. Two kinds of member
     * are certified, and each carries its own reader:
     *
     *  - a record field, whose structural projection promises the field on
     *    every value — read reflectively by key or property; and
     *  - an opaque field the type's owner declared through
     *    {@see \Superscript\Axiom\Fields\Field} — read by the host extractor.
     *
     * Everything else stays refused: an opaque with no declared field, a
     * dict, Unknown. Optionality propagates structurally — an optional object
     * yields an optional field and absence short-circuits before the reader.
     */
    public static function compile(MemberAccessSource $source, SourceCompilation $compilation): CompiledSource
    {
        $object = $compilation->child($source->object, 'object');
        $access = self::resolveAccess($object->returns->shape(), $source->property, $compilation);

        if ($access->isErr()) {
            $compilation->reject($access->unwrapErr());
        }

        $field = $access->unwrap();

        return $compilation->custom($field->returns, static function (SourceEvaluation $evaluation) use ($object, $source, $field) {
            try {
                $value = $evaluation->value($object);

                if ($value === null) {
                    return null;
                }

                return ($field->read)($value)
                    ->map(function (Option $option) use ($evaluation) {
                        $option->inspect(fn(mixed $result) => $evaluation->annotate('result', $result));

                        return $option->unwrapOr(null);
                    });
            } finally {
                $evaluation->annotate('label', ".{$source->property}");
            }
        });
    }

    /**
     * Read the field promised by the structural projection: arrays by key,
     * objects by property.
     *
     * @return Result<Option<mixed>, Throwable>
     */
    private static function accessValue(mixed $value, string $property): Result
    {
        if (is_array($value) && array_key_exists($property, $value)) {
            return Ok(Option::from($value[$property]));
        }

        if (is_object($value) && property_exists($value, $property)) {
            return Ok(Option::from($value->{$property}));
        }

        return Err(new RuntimeException(sprintf("Property '%s' does not exist on %s.", $property, get_debug_type($value))));
    }

    /**
     * The typing face of member access: certify `object.property` and pair
     * the certified type with the runtime reader that fetches it.
     *
     * @return Result<FieldAccess, TypeMismatch>
     */
    private static function resolveAccess(Shape $object, string $property, SourceCompilation $compilation): Result
    {
        if ($object instanceof OptionShape) {
            return self::resolveAccess($object->inner, $property, $compilation)
                ->map(fn(FieldAccess $inner) => new FieldAccess(new OptionType($inner->returns), $inner->read));
        }

        if ($object instanceof Shapes\UnknownShape) {
            return Err(new TypeMismatch(
                'Member access on Unknown is not certified: Unknown is inert — claim a record type with an Ascription, or convert with a Coerce, first.',
            ));
        }

        if ($object instanceof Shapes\RecordShape) {
            if (isset($object->fields[$property])) {
                return Ok(new FieldAccess(
                    TypeReifier::reify($object->fields[$property]),
                    static fn(mixed $value): Result => self::accessValue($value, $property),
                ));
            }

            return Err(new TypeMismatch(sprintf("Field '%s' does not exist on %s.", $property, TypeDescriber::describeShape($object))));
        }

        if ($object instanceof Shapes\DictShape) {
            return Err(new TypeMismatch(sprintf(
                'Member access on %s is not certified: dict keys are statically unknown and a missing key is a runtime error. Give the value a record type.',
                TypeDescriber::describeShape($object),
            )));
        }

        if ($object instanceof Shapes\OpaqueShape) {
            $field = $compilation->opaqueField($object->identity, $property);

            if ($field !== null) {
                return Ok(new FieldAccess(
                    $field->returns,
                    static fn(mixed $value): Result => $field->extract($value),
                ));
            }

            return Err(new TypeMismatch(sprintf(
                'Member access on %s is not certified: nominal types make no structural claims.',
                TypeDescriber::describeShape($object),
            )));
        }

        return Err(new TypeMismatch(sprintf("Cannot access field '%s' on %s.", $property, TypeDescriber::describeShape($object))));
    }
}
