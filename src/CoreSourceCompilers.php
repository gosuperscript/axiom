<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
use RuntimeException;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Operators\ValueEquality;
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
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes;
use Superscript\Axiom\Types\Shapes\BooleanShape;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeReifier;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Axiom\Types\UnionType;
use Superscript\Axiom\Types\UnknownType;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * The language's own source compilers: one rule per core node, registered
 * by {@see Dialect::core()} in the same exact-class map host extensions
 * use. Each rule computes the node's type and emits its evaluation
 * together, as one {@see CompiledNode} — inference and evaluation are one
 * walk, so a certified type and the code that runs cannot belong to
 * different programs.
 *
 * Every rule reaches the compiler only through the {@see SourceCompilation}
 * seam: the core language holds no capability a host source compiler
 * lacks, and an extension that tries to claim a core source class meets
 * the dialect's ordinary duplicate-ownership refusal.
 */
final readonly class CoreSourceCompilers
{
    /**
     * @return array<class-string<Source>, Closure(Source, SourceCompilation): Result<CompiledNode, TypeMismatch>>
     */
    public static function compilers(): array
    {
        /** @var array<class-string<Source>, Closure(Source, SourceCompilation): Result<CompiledNode, TypeMismatch>> */
        return [
            StaticSource::class => self::compileStatic(...),
            SymbolSource::class => self::compileSymbol(...),
            Coerce::class => self::compileCoerce(...),
            Ascription::class => self::compileAscription(...),
            UnaryExpression::class => self::compileUnary(...),
            InfixExpression::class => self::compileInfix(...),
            MatchExpression::class => self::compileMatch(...),
            MemberAccessSource::class => self::compileMemberAccess(...),
        ];
    }

    /**
     * @return Result<CompiledNode, TypeMismatch>
     */
    private static function compileStatic(StaticSource $source, SourceCompilation $compilation): Result
    {
        return $compilation->typeOfValue($source->value)->map(fn(Type $type) => self::constant($source->value, $type));
    }

    /**
     * A static value's evaluation: the value itself, absence for null.
     */
    private static function constant(mixed $value, ?Type $type = null): CompiledNode
    {
        return new CompiledNode($type ?? new UnknownType(), static function (Runtime $runtime) use ($value) {
            $runtime->annotate('label', 'static(' . get_debug_type($value) . ')');

            return Ok(is_null($value) ? None() : Some($value));
        });
    }

    /**
     * @return Result<CompiledNode, TypeMismatch>
     */
    private static function compileSymbol(SymbolSource $source, SourceCompilation $compilation): Result
    {
        return $compilation->symbol($source);
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
    private static function compileCoerce(Coerce $source, SourceCompilation $compilation): Result
    {
        $inner = $compilation->compile($source->source);

        // The documented escape hatch: a static value the literal registry
        // cannot type still has a total evaluation — itself — and Coerce
        // discards the inner type anyway. Everything else that fails to
        // compile fails here too: it runs, so it compiles.
        if ($inner->isErr() && $source->source instanceof StaticSource) {
            $inner = Ok(self::constant($source->source->value));
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
    private static function compileAscription(Ascription $source, SourceCompilation $compilation): Result
    {
        // No separate Unknown branch: overlaps(Unknown, T) always holds,
        // which is exactly the admission this bridge exists to provide.
        return $compilation->compile($source->source)->andThen(
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
    private static function compileUnary(UnaryExpression $source, SourceCompilation $compilation): Result
    {
        return $compilation->compile($source->operand)->andThen(function (CompiledNode $operand) use ($source, $compilation) {
            $shape = $operand->returns->shape();
            $present = $shape instanceof OptionShape ? TypeReifier::reify($shape->inner) : $operand->returns;

            return $compilation->prefix($source->operator, $present)
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
    private static function compileInfix(InfixExpression $source, SourceCompilation $compilation): Result
    {
        return $compilation->compile($source->left)->andThen(
            fn(CompiledNode $left) => $compilation->compile($source->right)->andThen(
                fn(CompiledNode $right) => $compilation->infix($left->returns, $source->operator, $right->returns)
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
    private static function compileMatch(MatchExpression $source, SourceCompilation $compilation): Result
    {
        $subject = $compilation->compile($source->subject);

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

            $pattern = self::compilePattern($arm->pattern, $compilation);

            if ($pattern->isErr()) {
                return Err(new TypeMismatch(sprintf('The pattern of match arm %d cannot be compiled.', $index), [$pattern->unwrapErr()]));
            }

            $body = $compilation->compile($arm->expression);

            if ($body->isErr()) {
                return Err(new TypeMismatch(sprintf('Match arm %d cannot be typed.', $index), [$body->unwrapErr()]));
            }

            $armTypes[] = $body->unwrap()->returns;
            $arms[] = [$pattern->unwrap(), $body->unwrap()];
        }

        $subjectNode = $subject->unwrap();

        if (!$wildcard && !self::covers($subjectNode->returns->shape(), $literals)) {
            return Err(new TypeMismatch(sprintf(
                'This match over %s may not be exhaustive, and an unmatched subject is a runtime error; add a wildcard arm.',
                TypeDescriber::describe($subjectNode->returns),
            )));
        }

        // Exhaustiveness implies at least one arm: a wildcard is an arm, and
        // zero literals cover no shape.
        return Ok(new CompiledNode(UnionType::join(...$armTypes), static function (Runtime $runtime) use ($subjectNode, $arms) {
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
    private static function compilePattern(MatchPattern $pattern, SourceCompilation $compilation): Result
    {
        if ($pattern instanceof WildcardPattern) {
            return Ok(static fn(mixed $subject, Runtime $runtime) => Ok(true));
        }

        if ($pattern instanceof LiteralPattern) {
            return Ok(static fn(mixed $subject, Runtime $runtime) => Ok(ValueEquality::equals($pattern->value, $subject)));
        }

        if ($pattern instanceof ExpressionPattern) {
            return $compilation->compile($pattern->source)->map(
                fn(CompiledNode $node) => static fn(mixed $subject, Runtime $runtime) => $node->evaluate($runtime)
                    ->map(fn(Option $option) => $option->unwrapOr(null) === $subject),
            );
        }

        return Err(new TypeMismatch(sprintf('No pattern rule exists for [%s].', get_class($pattern))));
    }

    /**
     * @param list<mixed> $literals
     */
    private static function covers(Shape $subject, array $literals): bool
    {
        // Never has no inhabitants, so any set of patterns covers it —
        // this is what makes `match null { null => ... }` (scrutinee
        // Option<Never>) exhaustive with the null pattern alone.
        if ($subject instanceof Shapes\NeverShape) {
            return true;
        }

        if ($subject instanceof OptionShape) {
            return in_array(null, $literals, strict: true) && self::covers($subject->inner, $literals);
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
            return array_all($subject->members, fn(Shape $member) => self::covers($member, $literals));
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
    private static function compileMemberAccess(MemberAccessSource $source, SourceCompilation $compilation): Result
    {
        return $compilation->compile($source->object)->andThen(
            fn(CompiledNode $object) => self::accessField($object->returns->shape(), $source->property)
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
    private static function accessField(Shape $object, string $property): Result
    {
        if ($object instanceof OptionShape) {
            return self::accessField($object->inner, $property)->map(fn(Type $field) => new OptionType($field));
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
}
