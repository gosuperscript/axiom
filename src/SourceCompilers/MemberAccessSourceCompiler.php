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
use Superscript\Axiom\Types\Type;
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
     * Member access is shape-driven, so extension types whose verified
     * projection is record-like receive the same structural field access.
     *
     */
    public static function compile(MemberAccessSource $source, SourceCompilation $compilation): CompiledSource
    {
        $object = $compilation->child($source->object, 'object');
        $field = self::accessField($object->returns->shape(), $source->property);

        if ($field->isErr()) {
            $compilation->reject($field->unwrapErr());
        }

        return $compilation->custom($field->unwrap(), static function (SourceEvaluation $evaluation) use ($object, $source) {
            try {
                $value = $evaluation->value($object);

                if ($value === null) {
                    return null;
                }

                return self::accessValue($value, $source->property)
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

    /** @return Result<Type, TypeMismatch> */
    private static function accessField(Shape $object, string $property): Result
    {
        if ($object instanceof OptionShape) {
            return self::accessField($object->inner, $property)->map(fn(Type $field) => new OptionType($field));
        }

        if ($object instanceof Shapes\UnknownShape) {
            return Err(new TypeMismatch(
                'Member access on Unknown is not certified: Unknown is inert — claim a record type with an Ascription, or convert with a Coerce, first.',
            ));
        }

        if ($object instanceof Shapes\RecordShape) {
            if (isset($object->fields[$property])) {
                return Ok(TypeReifier::reify($object->fields[$property]));
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
            return Err(new TypeMismatch(sprintf(
                'Member access on %s is not certified: nominal types make no structural claims.',
                TypeDescriber::describeShape($object),
            )));
        }

        return Err(new TypeMismatch(sprintf("Cannot access field '%s' on %s.", $property, TypeDescriber::describeShape($object))));
    }
}
