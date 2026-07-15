<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

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
    /** @var array<string, list<BinaryOperatorRule>> */
    private array $rules = [];

    /** @param list<BinaryOperatorRule> $rules */
    public function __construct(array $rules)
    {
        Assert::allIsInstanceOf($rules, BinaryOperatorRule::class);

        foreach ($rules as $rule) {
            $this->rules[$rule->operator()][] = $rule;
        }
    }

    /** @return Result<ResolvedOperation, TypeMismatch> */
    public function resolve(string $operator, Type $left, Type $right): Result
    {
        $rules = $this->rules[$operator] ?? [];

        if ($rules === []) {
            return Err(new TypeMismatch(sprintf('Operator [%s] is not supported.', $operator)));
        }

        $resolved = [];
        $refused = [];

        foreach ($rules as $rule) {
            $resolution = $rule->resolve($left, $right);

            if ($resolution instanceof ResolvedOperation) {
                $resolved[] = [$rule::class, $resolution];
            } else {
                $refused[] = self::mismatch($resolution);
            }
        }

        if (count($resolved) > 1) {
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

        if ($resolved !== []) {
            return Ok($resolved[0][1]);
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

    private static function mismatch(OperatorResolution $resolution): TypeMismatch
    {
        return match (true) {
            $resolution instanceof UnsupportedOperation => new TypeMismatch($resolution->message, $resolution->causes),
            $resolution instanceof DeadOperation => new TypeMismatch($resolution->message, $resolution->causes, dead: true),
            default => throw new \LogicException(sprintf('Unknown operator resolution [%s].', $resolution::class)),
        };
    }
}
