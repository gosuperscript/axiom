<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

use Closure;
use RuntimeException;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Operators\BinaryOperatorResolver;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Operators\UnaryOperatorResolver;
use Superscript\Axiom\Operators\ValueEquality;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Sources\Ascription;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MatchPattern;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Types\Shapes\BooleanShape;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * The compiler: syntax-directed inference over the runtime AST that also
 * builds the program. One rule per node computes the node's type and emits
 * its evaluation, as one {@see CompiledNode} — inference and evaluation
 * are one walk, so a certified type and the code that runs cannot belong
 * to different programs.
 *
 * Operators resolve through the dialect's composed stacks at compile time;
 * the resolutions are bound into the nodes and the compiled program never
 * dispatches on a value again.
 */
final readonly class TypeInference
{
    /**
     * @param array<class-string<Source>, Closure(Source, SourceCompilation): Result<CompiledNode, TypeMismatch>> $sourceCompilers
     */
    public function __construct(
        private BinaryOperatorResolver $operators,
        private UnaryOperatorResolver $unaryOperators,
        private LiteralTypeRegistry $literals = new LiteralTypeRegistry(),
        private array $sourceCompilers = [],
    ) {}

    /**
     * @return Result<CompiledNode, TypeMismatch>
     */
    public function compile(Source $source, TypeEnvironment $environment): Result
    {
        $compiled = match (true) {
            $source instanceof StaticSource => $this->compileStatic($source),
            $source instanceof SymbolSource => $environment->nodeOfSymbol($source->name, $source->namespace, $this),
            $source instanceof Coerce => $this->compileCoerce($source, $environment),
            $source instanceof Ascription => $this->compileAscription($source, $environment),
            $source instanceof UnaryExpression => $this->compileUnary($source, $environment),
            $source instanceof InfixExpression => $this->compileInfix($source, $environment),
            $source instanceof MatchExpression => $this->compileMatch($source, $environment),
            $source instanceof MemberAccessSource => $this->compileMemberAccess($source, $environment),
            default => $this->compileHostSource($source, $environment),
        };

        return $compiled->map(fn(CompiledNode $node) => $node->forSource($source));
    }

    /** @return Result<CompiledNode, TypeMismatch> */
    private function compileHostSource(Source $source, TypeEnvironment $environment): Result
    {
        $sourceClass = $source::class;
        $compiler = $this->sourceCompilers[$sourceClass] ?? null;

        if ($compiler === null) {
            return Err(new TypeMismatch(sprintf(
                'Cannot compile [%s]; register its exact class through Extension::sourceCompilers().',
                $sourceClass,
            )));
        }

        return $compiler(
            $source,
            new SourceCompilation(
                fn(Source $child): Result => $this->compile($child, $environment),
                fn(Type $left, string $operator, Type $right): Result => $this->operators->resolve($operator, $left, $right),
            ),
        );
    }

    /**
     * What does this source return? The typing face of compile().
     *
     * @return Result<Type, TypeMismatch>
     */
    public function infer(Source $source, TypeEnvironment $environment): Result
    {
        return $this->compile($source, $environment)->map(fn(CompiledNode $node) => $node->returns);
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
     * @return Result<CompiledNode, TypeMismatch>
     */
    private function compileStatic(StaticSource $source): Result
    {
        return $this->inferValue($source->value)->map(fn(Type $type) => $this->constant($source->value, $type));
    }

    /**
     * A static value's evaluation: the value itself, absence for null.
     */
    private function constant(mixed $value, ?Type $type = null): CompiledNode
    {
        return new CompiledNode($type ?? new UnknownType(), static function (Runtime $runtime) use ($value) {
            $runtime->annotate('label', 'static(' . get_debug_type($value) . ')');

            return Ok(is_null($value) ? None() : Some($value));
        });
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
     * The Coerce bridge types verbatim — the boundary is statically opaque
     * by design (coercion is admission policy, not membership) — and its
     * evaluation converts through the declared type's coerce() face.
     * Absence cannot cross a non-optional Coerce: the node certifies its
     * declared type, and absence only inhabits an Option.
     *
     * @return Result<CompiledNode, TypeMismatch>
     */
    private function compileCoerce(Coerce $source, TypeEnvironment $environment): Result
    {
        $inner = $this->compile($source->source, $environment);

        // The documented escape hatch: a static value the literal registry
        // cannot type still has a total evaluation — itself — and Coerce
        // discards the inner type anyway. Everything else that fails to
        // compile fails here too: it runs, so it compiles.
        if ($inner->isErr() && $source->source instanceof StaticSource) {
            $inner = Ok($this->constant($source->source->value));
        }

        return $inner->map(fn(CompiledNode $inner) => self::admission(
            $inner,
            $source->type,
            convert: static fn(mixed $value, Runtime $runtime) => $source->type->coerce($value)
                ->inspect(fn(Option $coerced) => $coerced->inspect(function (mixed $coercedValue) use ($value, $runtime) {
                    if ($coercedValue !== $value) {
                        $runtime->annotate('coercion', get_debug_type($value) . ' -> ' . get_debug_type($coercedValue));
                    }
                })),
            missing: 'The coerced value reads as missing, but %s is required; coerce to %s instead if absence is legal here.',
            label: TypeDescriber::describe($source->type),
        ));
    }

    /**
     * An ascription is a checked claim: the inner type must be Unknown
     * (this is how an Unknown value re-enters the typed world) or overlap
     * the claimed type — a disjoint claim is simply false, and refused
     * here. Its evaluation verifies membership via assert(), so a false
     * claim errs loudly at this node instead of passing a wrong value
     * downstream; absence cannot cross a non-optional claim.
     *
     * @return Result<CompiledNode, TypeMismatch>
     */
    private function compileAscription(Ascription $source, TypeEnvironment $environment): Result
    {
        // No separate Unknown branch: overlaps(Unknown, T) always holds,
        // which is exactly the admission this bridge exists to provide.
        return $this->compile($source->source, $environment)->andThen(
            fn(CompiledNode $inner) => TypeRelations::overlaps($inner->returns, $source->type)
                ->mapErr(fn(TypeMismatch $cause) => new TypeMismatch(
                    sprintf(
                        'The claim that this is %s is false: the value is %s, and no value inhabits both.',
                        TypeDescriber::describe($source->type),
                        TypeDescriber::describe($inner->returns),
                    ),
                    [$cause],
                ))
                ->map(fn() => self::admission(
                    $inner,
                    $source->type,
                    convert: static fn(mixed $value) => $source->type->assert($value),
                    missing: 'The ascribed value reads as missing, but the claim %s is required; claim %s instead if absence is legal here.',
                    label: 'is ' . TypeDescriber::describe($source->type),
                )),
        );
    }

    /**
     * The runtime half the two admission bridges share: evaluate the inner
     * node, convert any present value through the bridge's face (coerce()
     * or assert()), guard absence, and normalize the admitted value.
     *
     * The absence guard: when the declared type is not Option-shaped and
     * the value reads as missing, evaluation errs by name — silently
     * passing None through would deliver null into an expression certified
     * to receive the declared type. Optionality and the diagnostic are
     * fixed by the declared type, so both are computed here, once, at
     * compile time.
     *
     * @param Closure(mixed, Runtime): Result<Option<mixed>, Throwable> $convert
     */
    private static function admission(CompiledNode $inner, Type $type, Closure $convert, string $missing, string $label): CompiledNode
    {
        $optional = $type->shape() instanceof OptionShape;
        $missing = sprintf($missing, TypeDescriber::describe($type), TypeDescriber::describe(new OptionType($type)));

        return new CompiledNode($type, static function (Runtime $runtime) use ($inner, $convert, $optional, $missing, $label) {
            $result = $inner->evaluate($runtime)
                ->andThen(fn(Option $option) => $option
                    ->andThen(fn(mixed $value) => $convert($value, $runtime)->transpose())
                    ->transpose())
                ->andThen(static fn(Option $option) => $option->isNone() && !$optional
                    ? Err(new RuntimeException($missing))
                    : Ok($option))
                // One representation of null in the resolution channel: an
                // Option-typed admission emits Some(null), the boundary
                // protocol; downstream it travels as None.
                ->map(fn(Option $option) => $option->andThen(fn(mixed $value) => Option::from($value)));

            $runtime->annotate('label', $label);

            return $result;
        });
    }

    /**
     * Optionality propagates through unary operators: the rule resolves
     * against the present operand type, and the compiled node short-circuits
     * an absent operand before the evaluation runs — a unary rule never
     * sees null. Dispatches on the operand's projection, not its concrete
     * class — a canonicalized union like Union(Option<Number>, Number) has
     * shape Number? and must propagate identically.
     *
     * @return Result<CompiledNode, TypeMismatch>
     */
    private function compileUnary(UnaryExpression $source, TypeEnvironment $environment): Result
    {
        return $this->compile($source->operand, $environment)->andThen(function (CompiledNode $operand) use ($source) {
            $shape = $operand->returns->shape();
            $present = $shape instanceof OptionShape ? TypeReifier::reify($shape->inner) : $operand->returns;

            return $this->unaryOperators->resolve($source->operator, $present)
                ->map(function (ResolvedOperation $operation) use ($operand, $source, $shape) {
                    $returns = $shape instanceof OptionShape ? new OptionType($operation->returns) : $operation->returns;

                    return new CompiledNode($returns, static function (Runtime $runtime) use ($operand, $operation, $source) {
                        $result = $operand->evaluate($runtime)
                            ->andThen(fn(Option $option) => $option
                                ->map(fn(mixed $value) => $operation->evaluate($value))
                                ->transpose())
                            ->inspect(fn(Option $option) => $option->inspect(fn(mixed $value) => $runtime->annotate('result', $value)));

                        $runtime->annotate('label', $source->operator);

                        return $result;
                    });
                });
        });
    }

    /**
     * @return Result<CompiledNode, TypeMismatch>
     */
    private function compileInfix(InfixExpression $source, TypeEnvironment $environment): Result
    {
        return $this->compile($source->left, $environment)->andThen(
            fn(CompiledNode $left) => $this->compile($source->right, $environment)->andThen(
                fn(CompiledNode $right) => $this->operators->resolve($source->operator, $left->returns, $right->returns)
                    ->map(fn(ResolvedOperation $operation) => new CompiledNode(
                        $operation->returns,
                        static function (Runtime $runtime) use ($left, $right, $operation, $source) {
                            $result = $left->evaluate($runtime)
                                ->andThen(fn(Option $l) => $right->evaluate($runtime)->map(fn(Option $r) => [$l, $r]))
                                ->andThen(/** @param array{Option<mixed>, Option<mixed>} $operands */ function (array $operands) use ($operation, $runtime) {
                                    [$l, $r] = $operands;

                                    $runtime->annotate('left', $l->unwrapOr(null));
                                    $runtime->annotate('right', $r->unwrapOr(null));

                                    return $operation->evaluate($l->unwrapOr(null), $r->unwrapOr(null))
                                        ->inspect(fn(mixed $value) => $runtime->annotate('result', $value))
                                        ->map(fn(mixed $value) => Option::from($value));
                                });

                            $runtime->annotate('label', $source->operator);

                            return $result;
                        },
                    )),
            ),
        );
    }

    /**
     * The type of a match is the union of its arm types. Exhaustiveness is
     * mandatory: a fall-through is a runtime error, so unprovable coverage
     * is a compile error — add a wildcard arm. Provable coverage: a wildcard
     * arm, or full literal coverage of a Boolean / literal-union scrutinee
     * (null counting for the Option member). Expression patterns match at
     * runtime but never count toward coverage — and they are programs, so
     * they compile like everything else.
     *
     * @return Result<CompiledNode, TypeMismatch>
     */
    private function compileMatch(MatchExpression $source, TypeEnvironment $environment): Result
    {
        $subject = $this->compile($source->subject, $environment);

        if ($subject->isErr()) {
            return Err(new TypeMismatch('The match subject cannot be typed.', [$subject->unwrapErr()]));
        }

        $armTypes = [];
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

            $pattern = $this->compilePattern($arm->pattern, $environment);

            if ($pattern->isErr()) {
                return Err(new TypeMismatch(sprintf('The pattern of match arm %d cannot be compiled.', $index), [$pattern->unwrapErr()]));
            }

            $body = $this->compile($arm->expression, $environment);

            if ($body->isErr()) {
                return Err(new TypeMismatch(sprintf('Match arm %d cannot be typed.', $index), [$body->unwrapErr()]));
            }

            $armTypes[] = $body->unwrap()->returns;
            $arms[] = [$pattern->unwrap(), $body->unwrap()];
        }

        $subjectNode = $subject->unwrap();

        if (!$wildcard && !$this->covers($subjectNode->returns->shape(), $literals)) {
            return Err(new TypeMismatch(sprintf(
                'This match over %s may not be exhaustive, and an unmatched subject is a runtime error; add a wildcard arm.',
                TypeDescriber::describe($subjectNode->returns),
            )));
        }

        // Exhaustiveness implies at least one arm: a wildcard is an arm, and
        // zero literals cover no shape.
        return Ok(new CompiledNode($this->join($armTypes), static function (Runtime $runtime) use ($subjectNode, $arms) {
            $result = $subjectNode->evaluate($runtime)->andThen(function (Option $subjectOption) use ($runtime, $arms) {
                $subjectValue = $subjectOption->unwrapOr(null);

                $runtime->annotate('subject', $subjectValue);

                foreach ($arms as $index => [$matches, $body]) {
                    $matched = $matches($subjectValue, $runtime);

                    if ($matched->isErr()) {
                        return $matched;
                    }

                    if (!$matched->unwrap()) {
                        continue;
                    }

                    $runtime->annotate('matched_arm', $index);

                    return $body->evaluate($runtime)
                        ->inspect(fn(Option $option) => $option->inspect(fn(mixed $value) => $runtime->annotate('result', $value)));
                }

                return Err(new RuntimeException('No match arm matched the subject; add a wildcard arm to handle unmatched values.'));
            });

            $runtime->annotate('label', 'match');

            return $result;
        }));
    }

    /**
     * A pattern compiles to a predicate over the subject value. The
     * matcher and the coverage analysis consume one equality definition
     * (value equality) — a matcher stricter than the coverage rule would
     * certify exhaustiveness the runtime then fails.
     *
     * @return Result<Closure(mixed, Runtime): Result<bool, Throwable>, TypeMismatch>
     */
    private function compilePattern(MatchPattern $pattern, TypeEnvironment $environment): Result
    {
        if ($pattern instanceof WildcardPattern) {
            return Ok(static fn(mixed $subject, Runtime $runtime) => Ok(true));
        }

        if ($pattern instanceof LiteralPattern) {
            return Ok(static fn(mixed $subject, Runtime $runtime) => Ok(ValueEquality::equals($pattern->value, $subject)));
        }

        if ($pattern instanceof ExpressionPattern) {
            return $this->compile($pattern->source, $environment)->map(
                fn(CompiledNode $node) => static fn(mixed $subject, Runtime $runtime) => $node->evaluate($runtime)
                    ->map(fn(Option $option) => $option->unwrapOr(null) === $subject),
            );
        }

        return Err(new TypeMismatch(sprintf('No pattern rule exists for [%s].', get_class($pattern))));
    }

    /**
     * @param list<mixed> $literals
     */
    private function covers(Shape $subject, array $literals): bool
    {
        // Never has no inhabitants, so any set of patterns covers it —
        // this is what makes `match null { null => ... }` (scrutinee
        // Option<Never>) exhaustive with the null pattern alone.
        if ($subject instanceof Shapes\NeverShape) {
            return true;
        }

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
     * @return Result<CompiledNode, TypeMismatch>
     */
    private function compileMemberAccess(MemberAccessSource $source, TypeEnvironment $environment): Result
    {
        return $this->compile($source->object, $environment)->andThen(
            fn(CompiledNode $object) => $this->accessField($object->returns->shape(), $source->property)
                ->map(fn(Type $field) => new CompiledNode($field, static function (Runtime $runtime) use ($object, $source) {
                    $result = $object->evaluate($runtime)
                        ->andThen(fn(Option $option) => $option
                            ->mapOr(Ok(None()), fn(mixed $value) => self::accessValue($value, $source->property)))
                        ->inspect(fn(Option $option) => $option->inspect(fn(mixed $value) => $runtime->annotate('result', $value)));

                    $runtime->annotate('label', ".{$source->property}");

                    return $result;
                })),
        );
    }

    /**
     * The one structural path into a value: read the field the (true)
     * projection declared. Arrays by key, objects by property — these are
     * structure reads, not type dispatch.
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
     * @return Result<Type, TypeMismatch>
     */
    private function accessField(Shape $object, string $property): Result
    {
        if ($object instanceof OptionShape) {
            return $this->accessField($object->inner, $property)->map(fn(Type $field) => new OptionType($field));
        }

        if ($object instanceof Shapes\UnknownShape) {
            return Err(new TypeMismatch(
                "Member access on Unknown is not certified: Unknown is inert — claim a record type with an Ascription, or convert with a Coerce, first.",
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
