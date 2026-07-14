<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Operators\UnaryOverloader;
use Superscript\Axiom\Operators\UnaryOverloaderManager;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\Ascription;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\TypedSource;
use Superscript\Axiom\Types\Shapes;
use Superscript\Axiom\Types\Shapes\BooleanShape;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * Syntax-directed inference over the runtime AST, one rule per node,
 * consuming the evaluator's own overloader stacks — the same composed
 * dialect that will run the program, so static and runtime semantics
 * cannot drift by miscomposition.
 */
final readonly class TypeInference
{
    private UnaryOverloader $unaryOperators;

    public function __construct(
        private OperatorOverloader $operators,
        ?UnaryOverloader $unaryOperators = null,
        private LiteralTypeRegistry $literals = new LiteralTypeRegistry(),
    ) {
        $this->unaryOperators = $unaryOperators ?? UnaryOverloaderManager::default();
    }

    /**
     * @return Result<Type, TypeMismatch>
     */
    public function infer(Source $source, TypeEnvironment $environment): Result
    {
        return match (true) {
            $source instanceof TypedSource => $source->returnType($environment, $this),
            $source instanceof StaticSource => $this->inferValue($source->value),
            $source instanceof SymbolSource => $environment->typeOfSymbol($source->name, $source->namespace, $this),
            $source instanceof Coerce => Ok($source->type),
            $source instanceof Ascription => $this->inferAscription($source, $environment),
            $source instanceof UnaryExpression => $this->inferUnary($source, $environment),
            $source instanceof InfixExpression => $this->inferInfix($source, $environment),
            $source instanceof MatchExpression => $this->inferMatch($source, $environment),
            $source instanceof MemberAccessSource => $this->inferMemberAccess($source, $environment),
            default => Err(new TypeMismatch(sprintf(
                'Cannot infer a type for [%s]; implement TypedSource to declare one (Unknown is an honest answer).',
                get_class($source),
            ))),
        };
    }

    /**
     * check() is infer() plus assignability — literal-first inference and
     * value-set Option semantics dissolve bidirectional special cases into
     * assignability theorems. The API stays separate because lambda
     * inference will genuinely need expected-type propagation later.
     *
     * @return Result<Type, TypeMismatch>
     */
    public function check(Source $source, Type $expected, TypeEnvironment $environment): Result
    {
        return $this->infer($source, $environment)
            ->andThen(fn(Type $actual) => TypeRelations::isTypeAssignableTo($actual, $expected)->map(fn() => $actual));
    }

    /**
     * @return Result<Type, TypeMismatch>
     */
    private function inferValue(mixed $value): Result
    {
        if ($value === null) {
            return Ok(new OptionType(new NeverType()));
        }

        if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return Ok(new LiteralType($value));
        }

        if (is_array($value)) {
            return array_is_list($value) ? $this->inferList($value) : $this->inferRecord($value);
        }

        if (is_object($value)) {
            return $this->literals->resolve($value);
        }

        return Err(new TypeMismatch(sprintf('No literal type exists for a value of type [%s].', get_debug_type($value))));
    }

    /**
     * A list literal infers with union element unification and exact
     * bounds: ['shop', 'office'] is List<'shop' | 'office', 2>.
     *
     * @param list<mixed> $values
     * @return Result<Type, TypeMismatch>
     */
    private function inferList(array $values): Result
    {
        $elements = [];

        foreach ($values as $index => $value) {
            $element = $this->inferValue($value);

            if ($element->isErr()) {
                return Err(new TypeMismatch(sprintf('List element %d cannot be typed.', $index), [$element->unwrapErr()]));
            }

            $elements[] = $element->unwrap();
        }

        $count = count($values);

        return Ok(new ListType($this->join($elements), $count, $count));
    }

    /**
     * @param array<array-key, mixed> $values
     * @return Result<Type, TypeMismatch>
     */
    private function inferRecord(array $values): Result
    {
        $fields = [];

        foreach ($values as $key => $value) {
            if (is_int($key)) {
                return Err(new TypeMismatch(sprintf(
                    'A record literal requires string field names; got [%d]. A value that is neither a list nor a record has no type.',
                    $key,
                )));
            }

            $field = $this->inferValue($value);

            if ($field->isErr()) {
                return Err(new TypeMismatch(sprintf('Record field [%s] cannot be typed.', $key), [$field->unwrapErr()]));
            }

            $fields[$key] = $field->unwrap();
        }

        return Ok(new RecordType($fields));
    }

    /**
     * An ascription is a checked claim: the inner type must be Unknown (the
     * refinement case — the whole point of the node) or overlap the claimed
     * type. A disjoint claim is simply false — assert-world, where overlap
     * is the correct relation. Coerce, by contrast, types verbatim: the
     * boundary is statically opaque by design.
     *
     * @return Result<Type, TypeMismatch>
     */
    private function inferAscription(Ascription $source, TypeEnvironment $environment): Result
    {
        // No separate Unknown branch: overlaps(Unknown, T) always holds,
        // which is exactly the gradual admission an ascription wants.
        return $this->infer($source->source, $environment)->andThen(
            fn(Type $inner) => TypeRelations::overlaps($inner, $source->type)
                ->map(fn() => $source->type)
                ->mapErr(fn(TypeMismatch $cause) => new TypeMismatch(
                    sprintf(
                        'The claim that this is %s is false: the value is %s, and no value inhabits both.',
                        TypeDescriber::describe($source->type),
                        TypeDescriber::describe($inner),
                    ),
                    [$cause],
                )),
        );
    }

    /**
     * Optionality propagates through unary operators: the resolver
     * short-circuits an absent operand before any rule runs, so rules type
     * the present operand and the option wraps the result.
     *
     * @return Result<Type, TypeMismatch>
     */
    private function inferUnary(UnaryExpression $source, TypeEnvironment $environment): Result
    {
        return $this->infer($source->operand, $environment)->andThen(function (Type $operand) use ($source) {
            $present = $operand instanceof OptionType ? $operand->inner : $operand;

            return $this->unaryOperators->typeOf($source->operator, $present)
                ->map(fn(Type $result) => $operand instanceof OptionType ? new OptionType($result) : $result);
        });
    }

    /**
     * @return Result<Type, TypeMismatch>
     */
    private function inferInfix(InfixExpression $source, TypeEnvironment $environment): Result
    {
        return $this->infer($source->left, $environment)->andThen(
            fn(Type $left) => $this->infer($source->right, $environment)->andThen(
                fn(Type $right) => $this->operators->typeOf($source->operator, $left, $right),
            ),
        );
    }

    /**
     * The type of a match is the union of its arm types. Exhaustiveness is
     * mandatory: a fall-through is a runtime error, so unprovable coverage
     * is a compile error — add a wildcard arm. Provable coverage: a wildcard
     * arm, or full literal coverage of a Boolean / literal-union scrutinee
     * (null counting for the Option member). Expression patterns match at
     * runtime but never count toward coverage.
     *
     * @return Result<Type, TypeMismatch>
     */
    private function inferMatch(MatchExpression $source, TypeEnvironment $environment): Result
    {
        $subject = $this->infer($source->subject, $environment);

        if ($subject->isErr()) {
            return Err(new TypeMismatch('The match subject cannot be typed.', [$subject->unwrapErr()]));
        }

        $arms = [];
        $literals = [];
        $wildcard = false;

        foreach ($source->arms as $index => $arm) {
            if ($arm->pattern instanceof WildcardPattern) {
                $wildcard = true;
            }

            if ($arm->pattern instanceof LiteralPattern) {
                $literals[] = $arm->pattern->value;
            }

            $result = $this->infer($arm->expression, $environment);

            if ($result->isErr()) {
                return Err(new TypeMismatch(sprintf('Match arm %d cannot be typed.', $index), [$result->unwrapErr()]));
            }

            $arms[] = $result->unwrap();
        }

        if (!$wildcard && !$this->covers($subject->unwrap()->shape(), $literals)) {
            return Err(new TypeMismatch(sprintf(
                'This match over %s may not be exhaustive, and an unmatched subject is a runtime error; add a wildcard arm.',
                TypeDescriber::describe($subject->unwrap()),
            )));
        }

        // Exhaustiveness implies at least one arm: a wildcard is an arm, and
        // zero literals cover no shape.
        return Ok($this->join($arms));
    }

    /**
     * @param list<mixed> $literals
     */
    private function covers(Shape $subject, array $literals): bool
    {
        if ($subject instanceof OptionShape) {
            return in_array(null, $literals, strict: true) && $this->covers($subject->inner, $literals);
        }

        if ($subject instanceof BooleanShape) {
            return in_array(true, $literals, strict: true) && in_array(false, $literals, strict: true);
        }

        if ($subject instanceof LiteralShape) {
            return array_any(
                $literals,
                fn(mixed $value) => (is_bool($value) || is_int($value) || is_float($value) || is_string($value))
                    && (new LiteralShape($value))->equals($subject),
            );
        }

        if ($subject instanceof UnionShape) {
            return array_all($subject->members, fn(Shape $member) => $this->covers($member, $literals));
        }

        return false;
    }

    /**
     * Member access is shape-driven: it dispatches on the operand's
     * projection, not its concrete Type class, so any type whose
     * (census-verified, therefore true) projection is record-like gets
     * field access — extension types included. Field shapes reify back to
     * types. Sound only because of the shape-truth law: trusting a fictional
     * projection here would certify crashes.
     *
     * @return Result<Type, TypeMismatch>
     */
    private function inferMemberAccess(MemberAccessSource $source, TypeEnvironment $environment): Result
    {
        return $this->infer($source->object, $environment)
            ->andThen(fn(Type $object) => $this->accessField($object->shape(), $source->property));
    }

    /**
     * The field judgment, public for the environment's namespace descent:
     * a namespaced symbol whose namespace is declared record-typed resolves
     * to that record's field type — a namespace is the record view of a
     * binding, statically and dynamically.
     *
     * @return Result<Type, TypeMismatch>
     */
    public function fieldTypeOf(Type $object, string $property): Result
    {
        return $this->accessField($object->shape(), $property);
    }

    /**
     * @return Result<Type, TypeMismatch>
     */
    private function accessField(Shape $object, string $property): Result
    {
        if ($object instanceof OptionShape) {
            return $this->accessField($object->inner, $property)->map(fn(Type $field) => new OptionType($field));
        }

        if ($object instanceof Shapes\UnknownShape) {
            return Ok(new UnknownType());
        }

        if ($object instanceof Shapes\RecordShape) {
            if (isset($object->fields[$property])) {
                return Ok(TypeReifier::reify($object->fields[$property]));
            }

            return Err(new TypeMismatch($object->open
                ? sprintf("Field '%s' is not declared by the open record %s: openness certifies assignability width, never the presence of a particular field.", $property, TypeDescriber::describeShape($object))
                : sprintf("Field '%s' does not exist on %s.", $property, TypeDescriber::describeShape($object))));
        }

        if ($object instanceof Shapes\DictShape) {
            return Err(new TypeMismatch(sprintf(
                "Member access on %s is not certified: dict keys are statically unknown and a missing key is a runtime error. Give the value a record type.",
                TypeDescriber::describeShape($object),
            )));
        }

        if ($object instanceof Shapes\OpaqueShape) {
            return Err(new TypeMismatch(sprintf(
                "Member access on %s is not certified: nominal types make no structural claims.",
                TypeDescriber::describeShape($object),
            )));
        }

        return Err(new TypeMismatch(sprintf("Cannot access field '%s' on %s.", $property, TypeDescriber::describeShape($object))));
    }

    /**
     * The union join, deduplicated by equivalence: agreeing arms collapse
     * to the single type; literal arms keep their precision. The join of
     * nothing is Never, the union identity.
     *
     * @param list<Type> $types
     */
    private function join(array $types): Type
    {
        $unique = [];

        foreach ($types as $type) {
            if (!array_any($unique, fn(Type $existing) => TypeRelations::areEquivalent($existing, $type)->isOk())) {
                $unique[] = $type;
            }
        }

        return match (count($unique)) {
            0 => new NeverType(),
            1 => $unique[0],
            default => new UnionType(...$unique),
        };
    }
}
