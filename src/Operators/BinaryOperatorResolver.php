<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use InvalidArgumentException;
use Superscript\Axiom\Analysis\OperatorRuleProvenance;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\PresentType;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;
use Webmozart\Assert\Assert;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/** Compiler-facing collection of binary operator rules, indexed by symbol. */
final class BinaryOperatorResolver
{
    private const array EqualityNegations = [
        '=' => false,
        '==' => false,
        '===' => false,
        '!=' => true,
        '!==' => true,
    ];

    /** @var array<string, list<array{rule: BinaryOperatorRule, extension: ?string}>> */
    private array $rules = [];

    private bool $attributesResolutions;

    /**
     * @param list<BinaryOperatorRule> $rules
     * @param list<?string> $extensions
     */
    public function __construct(array $rules, array $extensions = [])
    {
        Assert::allIsInstanceOf($rules, BinaryOperatorRule::class);
        $this->attributesResolutions = $extensions !== [];

        if ($extensions !== [] && count($extensions) !== count($rules)) {
            throw new InvalidArgumentException('Binary operator extension provenance must align one-for-one with its rules.');
        }

        foreach ($rules as $index => $rule) {
            $this->rules[$rule->operator()][] = [
                'rule' => $rule,
                'extension' => $extensions[$index] ?? null,
            ];
        }
    }

    /**
     * Every operator symbol at least one rule claims.
     *
     * This is the enumerating face of resolve(): resolve() answers "may these
     * operand types use this symbol?", symbols() answers "which symbols are
     * there at all?". A caller that renders a choice of operators asks here
     * rather than proposing a list of its own and filtering it, so an
     * extension's operators appear without that caller being taught about
     * the extension.
     *
     * The answer is sorted, not in registration order. A composed dialect
     * carries rules from several extensions and its list order decides
     * nothing (see {@see \Superscript\Axiom\Dialect}), so an order derived
     * from it would be an accident callers could come to depend on.
     *
     * @return list<string>
     */
    public function symbols(): array
    {
        $symbols = array_keys($this->rules);
        sort($symbols);

        return $symbols;
    }

    /**
     * Which extensions claim each symbol — same keys as {@see symbols()}, in
     * the same order. A symbol maps to several identifiers when extensions
     * contribute rules for it over different operand types, so `-` on a
     * dialect with a date extension reports both owners:
     *
     * ```php
     * ['-' => ['axiom.core', 'axiom.date'], 'xor' => ['axiom.core']]
     * ```
     *
     * Rules registered without provenance report `unattributed`, the word
     * {@see \Superscript\Axiom\Analysis\CompilationAnalysis} uses for the
     * same absence.
     *
     * @return array<string, list<string>>
     */
    public function extensions(): array
    {
        $extensions = [];

        foreach ($this->rules as $operator => $rules) {
            $owners = array_unique(array_map(
                fn(array $rule): string => $rule['extension'] ?? 'unattributed',
                $rules,
            ));
            sort($owners);
            $extensions[$operator] = $owners;
        }

        ksort($extensions);

        return $extensions;
    }

    /**
     * Resolution first settles any core structural reading, such as the
     * universal presence question `x == null`, before extension overloads.
     * Ordinary overload resolution is then two-staged. The rules are
     * consulted with the operand types as given, and a match there — including
     * a rule that deliberately reads optional operand pairs — wins untouched.
     * Only when every rule refuses and an operand is optional are the rules
     * consulted again with the operands' present types; a match there is returned lifted
     * ({@see ResolvedOperation::liftedOverAbsence()}), so `answer > 0.25`
     * types over an optional answer without any rule knowing about absence.
     * Refusals always report the types as given — the lifted attempt adds
     * no diagnostics of its own.
     *
     * @return Result<ResolvedOperation, TypeMismatch>
     */
    public function resolve(string $operator, Type $left, Type $right): Result
    {
        $rules = $this->rules[$operator] ?? [];

        if ($rules === []) {
            return Err(new TypeMismatch(sprintf('Operator [%s] is not supported.', $operator)));
        }

        $intrinsic = self::presenceComparison($operator, $left, $right);

        if ($intrinsic !== null) {
            return Ok($this->attributesResolutions
                ? $intrinsic->attributedTo(new OperatorRuleProvenance(
                    identifier: 'axiom.option.presence-comparison',
                    implementation: self::class,
                    extension: 'axiom.core',
                ))
                : $intrinsic);
        }

        [$resolved, $refused] = $this->attempt($rules, $left, $right);

        if (count($resolved) > 1) {
            return self::ambiguous($operator, $resolved, $left, $right);
        }

        if ($resolved !== []) {
            return Ok($resolved[0][1]);
        }

        $presentLeft = PresentType::of($left);
        $presentRight = PresentType::of($right);

        // A bare null types as Option<Never>: it can never be present, so
        // there is nothing to lift over and the exact refusal stands.
        $liftable = ($presentLeft !== $left || $presentRight !== $right)
            && !$presentLeft->shape() instanceof NeverShape
            && !$presentRight->shape() instanceof NeverShape;

        if ($liftable) {
            [$lifted] = $this->attempt($rules, $presentLeft, $presentRight);

            if (count($lifted) > 1) {
                return self::ambiguous($operator, $lifted, $presentLeft, $presentRight);
            }

            if ($lifted !== []) {
                return Ok($lifted[0][1]->liftedOverAbsence());
            }
        }

        if (count($refused) === 1) {
            return Err($refused[0]);
        }

        return Err(new TypeMismatch(
            sprintf(
                'No overload of [%s] accepts %s and %s.',
                $operator,
                TypeDescriber::describe($left),
                TypeDescriber::describe($right),
            ),
            $refused,
            dead: array_all($refused, fn(TypeMismatch $mismatch) => $mismatch->dead),
        ));
    }

    /**
     * Consult every rule once over one pair of operand types.
     *
     * @param list<array{rule: BinaryOperatorRule, extension: ?string}> $rules
     * @return array{list<array{class-string, ResolvedOperation}>, list<TypeMismatch>}
     */
    private function attempt(array $rules, Type $left, Type $right): array
    {
        $resolved = [];
        $refused = [];

        foreach ($rules as ['rule' => $rule, 'extension' => $extension]) {
            $resolution = $rule->resolve($left, $right);

            if ($resolution instanceof ResolvedOperation) {
                $resolved[] = [$rule::class, $this->attributesResolutions
                    ? $resolution->attributedTo(OperatorRuleProvenance::of($rule, $extension))
                    : $resolution];
            } else {
                $refused[] = self::mismatch($resolution);
            }
        }

        return [$resolved, $refused];
    }

    /**
     * The core structural reading of an equality alias against absence.
     *
     * Option<T> is the sum 1 + T, while the absence-only type Option<Never>
     * is 1 + 0. Their comparison therefore observes only the sum constructor:
     * the present-present branch is uninhabited and needs no equality for T.
     * A total type has no option constructor to eliminate and reaches ordinary
     * overload resolution, where a dialect may reject the constant comparison
     * or deliberately define it. A bare Unknown reaches ordinary resolution for
     * the separate reason that it has no statically observable outer constructor.
     */
    private static function presenceComparison(string $operator, Type $left, Type $right): ?ResolvedOperation
    {
        if (!array_key_exists($operator, self::EqualityNegations)) {
            return null;
        }

        $counterpart = match (true) {
            self::isAbsenceOnly($left) => $right,
            self::isAbsenceOnly($right) => $left,
            default => null,
        };

        if (!$counterpart?->shape() instanceof OptionShape) {
            return null;
        }

        $negated = self::EqualityNegations[$operator];

        return new ResolvedOperation(
            new BooleanType(),
            static fn(mixed $left, mixed $right): bool => $negated
                ? ($left === null) !== ($right === null)
                : ($left === null) === ($right === null),
        );
    }

    private static function isAbsenceOnly(Type $type): bool
    {
        $shape = $type->shape();

        return $shape instanceof OptionShape && $shape->inner instanceof NeverShape;
    }

    /**
     * @param list<array{class-string, ResolvedOperation}> $resolved
     * @return Result<ResolvedOperation, TypeMismatch>
     */
    private static function ambiguous(string $operator, array $resolved, Type $left, Type $right): Result
    {
        $owners = array_column($resolved, 0);
        sort($owners);

        return Err(new TypeMismatch(sprintf(
            'Operator [%s] over %s and %s is ambiguous: [%s] all resolve it. A composed dialect has exactly one owner for any operand types.',
            $operator,
            TypeDescriber::describe($left),
            TypeDescriber::describe($right),
            implode('], [', $owners),
        )));
    }

    private static function mismatch(OperatorResolution $resolution): TypeMismatch
    {
        return match (true) {
            $resolution instanceof UnsupportedOperation => new TypeMismatch($resolution->message, $resolution->causes),
            $resolution instanceof DeadOperation => new TypeMismatch($resolution->message, $resolution->causes, dead: true),
            default => throw new \LogicException(sprintf('Unknown operator resolution [%s].', $resolution::class)),
        };
    }
}
