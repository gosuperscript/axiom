<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use InvalidArgumentException;
use Superscript\Axiom\Operators\BinaryOperatorResolver;
use Superscript\Axiom\Operators\BinaryOperatorRule;
use Superscript\Axiom\Operators\Connective;
use Superscript\Axiom\Operators\Equality;
use Superscript\Axiom\Operators\Has;
use Superscript\Axiom\Operators\In;
use Superscript\Axiom\Operators\Intersects;
use Superscript\Axiom\Operators\InfixOperatorRule;
use Superscript\Axiom\Operators\Operator;
use Superscript\Axiom\Operators\PrefixOperatorRule;
use Superscript\Axiom\Operators\UnaryOperatorResolver;
use Superscript\Axiom\Operators\UnaryOperatorRule;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\LiteralTypeRegistry;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeRelations;

use function Superscript\Monads\Result\attempt;

/**
 * The operator rules live in exactly one place. A Dialect composes the
 * binary rules, the unary rules, the literal registry, and exact-class
 * source compilers — the core language's nodes are registered by core()
 * through the same map host extensions contribute to, so an extension that
 * claims a core source class meets the ordinary duplicate-ownership
 * refusal. It is consumed at compile time only: the compiler binds every
 * selected evaluation into the Program, so there is nothing at runtime to
 * miscompose.
 *
 * Most core rules are dispatch-table rows (the operator rule builder's
 * output); equality and the set operators compute their answer from the
 * operand types. Ambiguity is refused at the earliest moment it exists:
 * two rows for the same operator whose slots admit a common operand type
 * are a construction error here, and any remaining multi-resolution is a
 * compile error in the resolver. List order decides nothing.
 *
 * Packages contribute through {@see Extension}; duplicate literal or source
 * registrations are loud errors.
 *
 * A dialect is a value: {@see with()} derives a new instance and never
 * mutates this one. Because the rules can never change after construction,
 * the resolvers they index into are built once, on first request, and
 * handed out again on every later call — a derived dialect indexes its own.
 * Callers may therefore ask a dialect what it supports as often as they
 * like; only the first question pays for the index.
 */
final class Dialect
{
    private ?BinaryOperatorResolver $binaryResolver = null;

    private ?UnaryOperatorResolver $unaryResolver = null;

    private ?LiteralTypeRegistry $literalRegistry = null;

    /**
     * @param list<BinaryOperatorRule> $binaryRules
     * @param list<UnaryOperatorRule> $unaryRules
     * @param array<class-string, callable(object): Type> $literalMappings
     * @param array<class-string<Source>, callable(Source, SourceCompilation): CompiledSource> $sourceCompilers
     * @param list<?string> $binaryExtensions
     * @param list<?string> $unaryExtensions
     * @param array<class-string<Source>, string> $sourceCompilerExtensions
     */
    private function __construct(
        private readonly array $binaryRules,
        private readonly array $unaryRules,
        private readonly array $literalMappings,
        private readonly array $sourceCompilers,
        private readonly array $binaryExtensions,
        private readonly array $unaryExtensions,
        private readonly array $sourceCompilerExtensions,
    ) {
        self::assertUnambiguousRows($this->binaryRules, $this->unaryRules);
    }

    public static function core(): self
    {
        $number = new NumberType();
        $boolean = new BooleanType();

        $binaryRules = [
            Operator::infix('+')->identifiedBy('axiom.number.add')->takes($number, $number)->returns($number)
                ->evaluatesWith(fn(int|float $left, int|float $right) => $left + $right),
            Operator::infix('-')->identifiedBy('axiom.number.subtract')->takes($number, $number)->returns($number)
                ->evaluatesWith(fn(int|float $left, int|float $right) => $left - $right),
            Operator::infix('*')->identifiedBy('axiom.number.multiply')->takes($number, $number)->returns($number)
                ->evaluatesWith(fn(int|float $left, int|float $right) => $left * $right),
            Operator::infix('/')->identifiedBy('axiom.number.divide')->takes($number, $number)->returns($number)
                ->evaluatesWith(fn(int|float $left, int|float $right) => attempt(fn() => $left / $right)),
            Operator::infix('<')->identifiedBy('axiom.number.less-than')->takes($number, $number)->returns($boolean)
                ->evaluatesWith(fn(int|float $left, int|float $right) => $left < $right),
            Operator::infix('<=')->identifiedBy('axiom.number.less-than-or-equal')->takes($number, $number)->returns($boolean)
                ->evaluatesWith(fn(int|float $left, int|float $right) => $left <= $right),
            Operator::infix('>')->identifiedBy('axiom.number.greater-than')->takes($number, $number)->returns($boolean)
                ->evaluatesWith(fn(int|float $left, int|float $right) => $left > $right),
            Operator::infix('>=')->identifiedBy('axiom.number.greater-than-or-equal')->takes($number, $number)->returns($boolean)
                ->evaluatesWith(fn(int|float $left, int|float $right) => $left >= $right),
            new Connective('&&', conjunction: true, identifier: 'axiom.boolean.and'),
            new Connective('||', conjunction: false, identifier: 'axiom.boolean.or'),
            Operator::infix('xor')->identifiedBy('axiom.boolean.xor')->takes($boolean, $boolean)->returns($boolean)
                ->evaluatesWith(fn(bool $left, bool $right) => $left xor $right),
            new Equality('=', negated: false),
            new Equality('==', negated: false),
            new Equality('===', negated: false),
            new Equality('!=', negated: true),
            new Equality('!==', negated: true),
            new Has(),
            new In(),
            new Intersects(),
        ];
        $unaryRules = [
            Operator::prefix('!')->identifiedBy('axiom.boolean.not')->takes($boolean)->returns($boolean)
                ->evaluatesWith(fn(bool $operand) => !$operand),
            Operator::prefix('not')->identifiedBy('axiom.boolean.not-readable')->takes($boolean)->returns($boolean)
                ->evaluatesWith(fn(bool $operand) => !$operand),
            Operator::prefix('-')->identifiedBy('axiom.number.negate')->takes($number)->returns($number)
                ->evaluatesWith(fn(int|float $operand) => -$operand),
        ];
        $sourceCompilers = CoreSourceCompilers::compilers();

        return new self(
            binaryRules: $binaryRules,
            unaryRules: $unaryRules,
            literalMappings: [],
            sourceCompilers: $sourceCompilers,
            binaryExtensions: array_fill_keys(array_keys($binaryRules), 'axiom.core'),
            unaryExtensions: array_fill_keys(array_keys($unaryRules), 'axiom.core'),
            sourceCompilerExtensions: array_fill_keys(array_keys($sourceCompilers), 'axiom.core'),
        );
    }

    public function with(Extension ...$extensions): self
    {
        $binary = $this->binaryRules;
        $unary = $this->unaryRules;
        $literals = $this->literalMappings;
        $sourceCompilers = $this->sourceCompilers;
        $binaryExtensions = $this->binaryExtensions;
        $unaryExtensions = $this->unaryExtensions;
        $sourceCompilerExtensions = $this->sourceCompilerExtensions;

        foreach ($extensions as $extension) {
            $identifier = $extension->identifier();

            if ($identifier === '') {
                throw new InvalidArgumentException(sprintf('Extension [%s] returned an empty identifier.', $extension::class));
            }

            $extensionBinary = $extension->operators();
            $extensionUnary = $extension->unaryOperators();
            $binary = [...$extensionBinary, ...$binary];
            $unary = [...$extensionUnary, ...$unary];
            $binaryExtensions = [...array_fill_keys(array_keys($extensionBinary), $identifier), ...$binaryExtensions];
            $unaryExtensions = [...array_fill_keys(array_keys($extensionUnary), $identifier), ...$unaryExtensions];

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
                $sourceCompilerExtensions[$sourceClass] = $identifier;
            }
        }

        return new self(
            $binary,
            $unary,
            $literals,
            $sourceCompilers,
            $binaryExtensions,
            $unaryExtensions,
            $sourceCompilerExtensions,
        );
    }

    public function operators(): BinaryOperatorResolver
    {
        return $this->binaryResolver ??= new BinaryOperatorResolver($this->binaryRules, $this->binaryExtensions);
    }

    public function unaryOperators(): UnaryOperatorResolver
    {
        return $this->unaryResolver ??= new UnaryOperatorResolver($this->unaryRules, $this->unaryExtensions);
    }

    public function literals(): LiteralTypeRegistry
    {
        return $this->literalRegistry ??= new LiteralTypeRegistry($this->literalMappings);
    }

    /** @return array<class-string<Source>, callable(Source, SourceCompilation): CompiledSource> */
    public function sourceCompilers(): array
    {
        return $this->sourceCompilers;
    }

    /** @return array<class-string<Source>, string> */
    public function sourceCompilerExtensions(): array
    {
        return $this->sourceCompilerExtensions;
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
        $infixRows = array_values(array_filter($binaryRules, fn(BinaryOperatorRule $rule) => $rule instanceof InfixOperatorRule));

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

        $prefixRows = array_values(array_filter($unaryRules, fn(UnaryOperatorRule $rule) => $rule instanceof PrefixOperatorRule));

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
