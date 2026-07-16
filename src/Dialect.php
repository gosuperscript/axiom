<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Closure;
use InvalidArgumentException;
use Superscript\Axiom\Operators\BinaryOperatorResolver;
use Superscript\Axiom\Operators\BinaryOperatorRule;
use Superscript\Axiom\Operators\Equality;
use Superscript\Axiom\Operators\Has;
use Superscript\Axiom\Operators\In;
use Superscript\Axiom\Operators\Intersects;
use Superscript\Axiom\Operators\Operator;
use Superscript\Axiom\Operators\Signatures\InfixSignature;
use Superscript\Axiom\Operators\Signatures\PrefixSignature;
use Superscript\Axiom\Operators\UnaryOperatorResolver;
use Superscript\Axiom\Operators\UnaryOperatorRule;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\LiteralTypeRegistry;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\attempt;

/**
 * The operator rules live in exactly one place. A Dialect composes the
 * binary rules, the unary rules, the literal registry, and exact-class host
 * source compilers. It is consumed at compile time only: the compiler binds
 * every selected evaluation into the Program, so there is nothing at
 * runtime to miscompose.
 *
 * Most core rules are dispatch-table rows (the signature builder's
 * output); equality and the set operators compute their answer from the
 * operand types. Ambiguity is refused at the earliest moment it exists:
 * two rows for the same operator whose slots admit a common operand type
 * are a construction error here, and any remaining multi-resolution is a
 * compile error in the resolver. List order decides nothing.
 *
 * Packages contribute through {@see Extension}; duplicate literal or source
 * registrations are loud errors.
 */
final readonly class Dialect
{
    /**
     * @param list<BinaryOperatorRule> $binaryRules
     * @param list<UnaryOperatorRule> $unaryRules
     * @param array<class-string, callable(object): Type> $literalMappings
     * @param array<class-string<Source>, Closure(Source, SourceCompilation): Result<CompiledNode, TypeMismatch>> $sourceCompilers
     */
    private function __construct(
        private array $binaryRules,
        private array $unaryRules,
        private array $literalMappings,
        private array $sourceCompilers,
    ) {
        self::assertUnambiguousRows($this->binaryRules, $this->unaryRules);
    }

    public static function core(): self
    {
        $number = new NumberType();
        $boolean = new BooleanType();

        return new self(
            binaryRules: [
                Operator::infix('+')->signature($number, $number)->returns($number)
                    ->evaluate(fn(int|float $left, int|float $right) => $left + $right),
                Operator::infix('-')->signature($number, $number)->returns($number)
                    ->evaluate(fn(int|float $left, int|float $right) => $left - $right),
                Operator::infix('*')->signature($number, $number)->returns($number)
                    ->evaluate(fn(int|float $left, int|float $right) => $left * $right),
                Operator::infix('/')->signature($number, $number)->returns($number)
                    ->evaluate(fn(int|float $left, int|float $right) => attempt(fn() => $left / $right)),
                Operator::infix('<')->signature($number, $number)->returns($boolean)
                    ->evaluate(fn(int|float $left, int|float $right) => $left < $right),
                Operator::infix('<=')->signature($number, $number)->returns($boolean)
                    ->evaluate(fn(int|float $left, int|float $right) => $left <= $right),
                Operator::infix('>')->signature($number, $number)->returns($boolean)
                    ->evaluate(fn(int|float $left, int|float $right) => $left > $right),
                Operator::infix('>=')->signature($number, $number)->returns($boolean)
                    ->evaluate(fn(int|float $left, int|float $right) => $left >= $right),
                Operator::infix('&&')->signature($boolean, $boolean)->returns($boolean)
                    ->evaluate(fn(bool $left, bool $right) => $left && $right),
                Operator::infix('||')->signature($boolean, $boolean)->returns($boolean)
                    ->evaluate(fn(bool $left, bool $right) => $left || $right),
                Operator::infix('xor')->signature($boolean, $boolean)->returns($boolean)
                    ->evaluate(fn(bool $left, bool $right) => $left xor $right),
                new Equality('=', negated: false),
                new Equality('==', negated: false),
                new Equality('===', negated: false),
                new Equality('!=', negated: true),
                new Equality('!==', negated: true),
                new Has(),
                new In(),
                new Intersects(),
            ],
            unaryRules: [
                Operator::prefix('!')->signature($boolean)->returns($boolean)
                    ->evaluate(fn(bool $operand) => !$operand),
                Operator::prefix('not')->signature($boolean)->returns($boolean)
                    ->evaluate(fn(bool $operand) => !$operand),
                Operator::prefix('-')->signature($number)->returns($number)
                    ->evaluate(fn(int|float $operand) => -$operand),
            ],
            literalMappings: [],
            sourceCompilers: [],
        );
    }

    public function with(Extension ...$extensions): self
    {
        $binary = $this->binaryRules;
        $unary = $this->unaryRules;
        $literals = $this->literalMappings;
        $sourceCompilers = $this->sourceCompilers;

        foreach ($extensions as $extension) {
            $binary = [...$extension->operators(), ...$binary];
            $unary = [...$extension->unaryOperators(), ...$unary];

            foreach ($extension->literals() as $class => $factory) {
                if (isset($literals[$class])) {
                    throw new InvalidArgumentException(sprintf(
                        'Literal class [%s] is registered by two extensions; duplicate literal registrations are a configuration error, never a precedence question.',
                        $class,
                    ));
                }

                $literals[$class] = $factory;
            }

            foreach ($extension->sourceCompilers() as $sourceClass => $compiler) {
                if (array_key_exists($sourceClass, $sourceCompilers)) {
                    throw new InvalidArgumentException(sprintf(
                        'Source class [%s] has two compilers; source compiler ownership is exact and extension order carries no precedence.',
                        $sourceClass,
                    ));
                }

                $sourceCompilers[$sourceClass] = $compiler;
            }
        }

        return new self($binary, $unary, $literals, $sourceCompilers);
    }

    public function operators(): BinaryOperatorResolver
    {
        return new BinaryOperatorResolver($this->binaryRules);
    }

    public function unaryOperators(): UnaryOperatorResolver
    {
        return new UnaryOperatorResolver($this->unaryRules);
    }

    public function literals(): LiteralTypeRegistry
    {
        return new LiteralTypeRegistry($this->literalMappings);
    }

    /** @return array<class-string<Source>, Closure(Source, SourceCompilation): Result<CompiledNode, TypeMismatch>> */
    public function sourceCompilers(): array
    {
        return $this->sourceCompilers;
    }

    /**
     * Rows are statically comparable, so a dialect refuses two rows for the
     * same operator whose slots are jointly admissible — some operand type
     * would resolve both, and with no precedence rule the compiler could
     * never pick. Value overlap is deliberately not the test:
     * dispatch sees operand types, never values, so a List row beside a
     * Dict row is a legal pair even though the empty array inhabits both.
     *
     * @param list<BinaryOperatorRule> $binaryRules
     * @param list<UnaryOperatorRule> $unaryRules
     */
    private static function assertUnambiguousRows(array $binaryRules, array $unaryRules): void
    {
        $infixRows = array_values(array_filter($binaryRules, fn(BinaryOperatorRule $rule) => $rule instanceof InfixSignature));

        foreach ($infixRows as $index => $row) {
            foreach (array_slice($infixRows, $index + 1) as $other) {
                if (
                    $row->operator() === $other->operator()
                    && TypeRelations::jointlyAdmissible($row->left, $other->left)->isOk()
                    && TypeRelations::jointlyAdmissible($row->right, $other->right)->isOk()
                ) {
                    throw new InvalidArgumentException(sprintf(
                        'The dialect is ambiguous: two [%s] rows collide — (%s, %s) and (%s, %s) admit a common operand type, and which evaluation runs must never depend on list order.',
                        $row->operator(),
                        TypeDescriber::describe($row->left),
                        TypeDescriber::describe($row->right),
                        TypeDescriber::describe($other->left),
                        TypeDescriber::describe($other->right),
                    ));
                }
            }
        }

        $prefixRows = array_values(array_filter($unaryRules, fn(UnaryOperatorRule $rule) => $rule instanceof PrefixSignature));

        foreach ($prefixRows as $index => $row) {
            foreach (array_slice($prefixRows, $index + 1) as $other) {
                if (
                    $row->operator() === $other->operator()
                    && TypeRelations::jointlyAdmissible($row->operand, $other->operand)->isOk()
                ) {
                    throw new InvalidArgumentException(sprintf(
                        'The dialect is ambiguous: two unary [%s] rows collide — %s and %s admit a common operand type, and which evaluation runs must never depend on list order.',
                        $row->operator(),
                        TypeDescriber::describe($row->operand),
                        TypeDescriber::describe($other->operand),
                    ));
                }
            }
        }
    }
}
