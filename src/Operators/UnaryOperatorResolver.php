<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use InvalidArgumentException;
use Superscript\Axiom\Analysis\OperatorRuleProvenance;
use Superscript\Axiom\Types\PresentType;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;
use Webmozart\Assert\Assert;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/** Compiler-facing collection of unary operator rules, indexed by symbol. */
final class UnaryOperatorResolver
{
    /** @var array<string, list<array{rule: UnaryOperatorRule, extension: ?string}>> */
    private array $rules = [];

    private bool $attributesResolutions;

    /**
     * @param list<UnaryOperatorRule> $rules
     * @param list<?string> $extensions
     */
    public function __construct(array $rules, array $extensions = [])
    {
        Assert::allIsInstanceOf($rules, UnaryOperatorRule::class);
        $this->attributesResolutions = $extensions !== [];

        if ($extensions !== [] && count($extensions) !== count($rules)) {
            throw new InvalidArgumentException('Unary operator extension provenance must align one-for-one with its rules.');
        }

        foreach ($rules as $index => $rule) {
            $this->rules[$rule->operator()][] = [
                'rule' => $rule,
                'extension' => $extensions[$index] ?? null,
            ];
        }
    }

    /**
     * Every operator symbol at least one rule claims, sorted rather than in
     * registration order — see {@see BinaryOperatorResolver::symbols()} for
     * why enumeration belongs beside resolve() and why the order is not the
     * dialect's.
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
     * the same order; `unattributed` where a rule was registered without
     * provenance. See {@see BinaryOperatorResolver::extensions()}.
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
     * Resolution is two-staged, exactly as in
     * {@see BinaryOperatorResolver::resolve()}: the rules see the operand
     * type as given first, and only when every rule refuses an optional
     * operand are they consulted with its present type — a match there is
     * returned lifted ({@see ResolvedOperation::liftedOverAbsence()}).
     * Refusals always report the type as given.
     *
     * @return Result<ResolvedOperation, TypeMismatch>
     */
    public function resolve(string $operator, Type $operand): Result
    {
        $rules = $this->rules[$operator] ?? [];

        if ($rules === []) {
            return Err(new TypeMismatch(sprintf('Unary operator [%s] is not supported.', $operator)));
        }

        [$resolved, $refused] = $this->attempt($rules, $operand);

        if (count($resolved) > 1) {
            return self::ambiguous($operator, $resolved, $operand);
        }

        if ($resolved !== []) {
            return Ok($resolved[0][1]);
        }

        $present = PresentType::of($operand);

        // A bare null types as Option<Never>: it can never be present, so
        // there is nothing to lift over and the exact refusal stands.
        if ($present !== $operand && !$present->shape() instanceof NeverShape) {
            [$lifted] = $this->attempt($rules, $present);

            if (count($lifted) > 1) {
                return self::ambiguous($operator, $lifted, $present);
            }

            if ($lifted !== []) {
                return Ok($lifted[0][1]->liftedOverAbsence());
            }
        }

        if (count($refused) === 1) {
            return Err($refused[0]);
        }

        return Err(new TypeMismatch(
            sprintf('No overload of unary [%s] accepts %s.', $operator, TypeDescriber::describe($operand)),
            $refused,
            dead: array_all($refused, fn(TypeMismatch $mismatch) => $mismatch->dead),
        ));
    }

    /**
     * Consult every rule once over one operand type.
     *
     * @param list<array{rule: UnaryOperatorRule, extension: ?string}> $rules
     * @return array{list<array{class-string, ResolvedOperation}>, list<TypeMismatch>}
     */
    private function attempt(array $rules, Type $operand): array
    {
        $resolved = [];
        $refused = [];

        foreach ($rules as ['rule' => $rule, 'extension' => $extension]) {
            $resolution = $rule->resolve($operand);

            if ($resolution instanceof ResolvedOperation) {
                $resolved[] = [$rule::class, $this->attributesResolutions
                    ? $resolution->attributedTo(OperatorRuleProvenance::of($rule, $extension))
                    : $resolution];
            } else {
                $refused[] = match (true) {
                    $resolution instanceof DeadOperation => new TypeMismatch($resolution->message, $resolution->causes, dead: true),
                    $resolution instanceof UnsupportedOperation => new TypeMismatch($resolution->message, $resolution->causes),
                    default => throw new \LogicException(sprintf('Unknown operator resolution [%s].', $resolution::class)),
                };
            }
        }

        return [$resolved, $refused];
    }

    /**
     * @param list<array{class-string, ResolvedOperation}> $resolved
     * @return Result<ResolvedOperation, TypeMismatch>
     */
    private static function ambiguous(string $operator, array $resolved, Type $operand): Result
    {
        $owners = array_column($resolved, 0);
        sort($owners);

        return Err(new TypeMismatch(sprintf(
            'Unary operator [%s] over %s is ambiguous: [%s] all resolve it. A composed dialect has exactly one owner for any operand type.',
            $operator,
            TypeDescriber::describe($operand),
            implode('], [', $owners),
        )));
    }
}
