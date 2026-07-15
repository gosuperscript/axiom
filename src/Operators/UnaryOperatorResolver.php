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

/** Compiler-facing collection of unary operator rules, indexed by symbol. */
final class UnaryOperatorResolver
{
    /** @var array<string, list<UnaryOperatorRule>> */
    private array $rules = [];

    /** @param list<UnaryOperatorRule> $rules */
    public function __construct(array $rules)
    {
        Assert::allIsInstanceOf($rules, UnaryOperatorRule::class);

        foreach ($rules as $rule) {
            $this->rules[$rule->operator()][] = $rule;
        }
    }

    /** @return Result<ResolvedOperation, TypeMismatch> */
    public function resolve(string $operator, Type $operand): Result
    {
        $rules = $this->rules[$operator] ?? [];

        if ($rules === []) {
            return Err(new TypeMismatch(sprintf('Unary operator [%s] is not supported.', $operator)));
        }

        $resolved = [];
        $refused = [];

        foreach ($rules as $rule) {
            $resolution = $rule->resolve($operand);

            if ($resolution instanceof ResolvedOperation) {
                $resolved[] = [$rule::class, $resolution];
            } else {
                $refused[] = match (true) {
                    $resolution instanceof DeadOperation => new TypeMismatch($resolution->message, $resolution->causes, dead: true),
                    $resolution instanceof UnsupportedOperation => new TypeMismatch($resolution->message, $resolution->causes),
                    default => throw new \LogicException(sprintf('Unknown operator resolution [%s].', $resolution::class)),
                };
            }
        }

        if (count($resolved) > 1) {
            $owners = array_column($resolved, 0);
            sort($owners);

            return Err(new TypeMismatch(sprintf(
                'Unary operator [%s] over %s is ambiguous: [%s] all resolve it. A composed dialect has exactly one owner for any operand type.',
                $operator,
                TypeDescriber::describe($operand),
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
            sprintf('No overload of unary [%s] accepts %s.', $operator, TypeDescriber::describe($operand)),
            $refused,
            dead: array_all($refused, fn(TypeMismatch $mismatch) => $mismatch->dead),
        ));
    }
}
