<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Operators\DeadOperation;
use Superscript\Axiom\Operators\OperatorResolution;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Operators\UnaryOperatorResolver;
use Superscript\Axiom\Operators\UnaryOperatorRule;
use Superscript\Axiom\Operators\UnsupportedOperation;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\UnknownType;

#[CoversClass(UnaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\OptionLayers::class)]
#[UsesClass(\Superscript\Axiom\CoreSourceCompilers::class)]
#[UsesClass(Dialect::class)]
#[UsesClass(ResolvedOperation::class)]
#[UsesClass(UnsupportedOperation::class)]
#[UsesClass(DeadOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\BinaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\Coalesce::class)]
#[UsesClass(\Superscript\Axiom\Operators\Equality::class)]
#[UsesClass(\Superscript\Axiom\Operators\Operator::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithOperand::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithReturn::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(UnknownType::class)]
#[UsesClass(TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Types\NeverType::class)]
#[UsesClass(\Superscript\Axiom\Types\PresentType::class)]
#[UsesClass(\Superscript\Axiom\Fields\OpaqueFieldRegistry::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NeverShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnknownShape::class)]
#[\PHPUnit\Framework\Attributes\UsesNamespace('Superscript\\Axiom\\Analysis')]
#[UsesClass(\Superscript\Axiom\Operators\Connective::class)]
final class UnaryOperatorResolverTest extends TestCase
{
    private static function rule(string $operator, OperatorResolution $resolution, ?int &$calls = null): UnaryOperatorRule
    {
        return new class ($operator, $resolution, $calls) implements UnaryOperatorRule {
            public function __construct(
                private readonly string $symbol,
                private readonly OperatorResolution $resolution,
                private ?int &$calls,
            ) {}

            public function operator(): string
            {
                return $this->symbol;
            }

            public function resolve(Type $operand): OperatorResolution
            {
                ++$this->calls;

                return $this->resolution;
            }
        };
    }

    /** A second concrete rule class, so owner ordering is observable. */
    private static function alternativeRule(string $operator, OperatorResolution $resolution): UnaryOperatorRule
    {
        return new class ($operator, $resolution) implements UnaryOperatorRule {
            public function __construct(
                private readonly string $symbol,
                private readonly OperatorResolution $resolution,
            ) {}

            public function operator(): string
            {
                return $this->symbol;
            }

            public function resolve(Type $operand): OperatorResolution
            {
                return $this->resolution;
            }
        };
    }

    #[Test]
    public function it_requires_unary_rules(): void
    {
        $this->expectException(InvalidArgumentException::class);

        /** @phpstan-ignore argument.type */
        new UnaryOperatorResolver(['not a rule']);
    }

    #[Test]
    public function core_rows_resolve_and_refuse_as_before(): void
    {
        $resolver = Dialect::core()->unaryOperators();

        $this->assertInstanceOf(BooleanType::class, $resolver->resolve('!', new BooleanType())->unwrap()->returns);
        $this->assertInstanceOf(NumberType::class, $resolver->resolve('-', new NumberType())->unwrap()->returns);
        $this->assertStringContainsString('[!] expects Boolean; got Number.', $resolver->resolve('!', new NumberType())->unwrapErr()->describe());
        $this->assertStringContainsString('An Unknown operand is inert', $resolver->resolve('!', new UnknownType())->unwrapErr()->describe());
    }

    #[Test]
    public function an_optional_operand_lifts_the_matching_row(): void
    {
        $resolver = Dialect::core()->unaryOperators();

        $lifted = $resolver->resolve('-', new OptionType(new NumberType()))->unwrap();

        $this->assertInstanceOf(OptionType::class, $lifted->returns);
        $this->assertSame(-5, $lifted->evaluate(5)->unwrap());
        $this->assertNull($lifted->evaluate(null)->unwrap(), 'absence answers absence without the rule running');
    }

    #[Test]
    public function a_constant_absent_operand_does_not_lift(): void
    {
        // A bare null types as Option<Never>: nothing can ever be present,
        // so the exact refusal stands.
        $resolver = Dialect::core()->unaryOperators();

        $verdict = $resolver->resolve('-', new OptionType(new \Superscript\Axiom\Types\NeverType()));

        $this->assertStringContainsString('[-] expects Number', $verdict->unwrapErr()->describe());
    }

    #[Test]
    public function unknown_symbols_do_not_invoke_unrelated_rules(): void
    {
        $calls = 0;
        $resolver = new UnaryOperatorResolver([self::rule('!', new UnsupportedOperation('No.'), $calls)]);

        $verdict = $resolver->resolve('~', new BooleanType());

        $this->assertSame(0, $calls);
        $this->assertSame('Unary operator [~] is not supported.', $verdict->unwrapErr()->message);
    }

    #[Test]
    public function multiple_refusals_aggregate_and_preserve_dead_metadata(): void
    {
        $result = (new UnaryOperatorResolver([
            self::rule('!', new DeadOperation('first refusal')),
            self::rule('!', new DeadOperation('second refusal')),
        ]))->resolve('!', new StringType())->unwrapErr();

        $this->assertSame('No overload of unary [!] accepts String.', $result->message);
        $this->assertCount(2, $result->causes);
        $this->assertTrue($result->dead);

        $mixed = (new UnaryOperatorResolver([
            self::rule('!', new DeadOperation('first refusal')),
            self::rule('!', new UnsupportedOperation('second refusal')),
        ]))->resolve('!', new StringType())->unwrapErr();

        $this->assertFalse($mixed->dead);
    }

    #[Test]
    public function one_resolution_wins_and_two_are_stably_ambiguous(): void
    {
        $resolved = new ResolvedOperation(new BooleanType(), fn(mixed $value) => $value);
        $refused = self::rule('!', new UnsupportedOperation('No.'));
        $first = self::rule('!', $resolved);
        $second = self::alternativeRule('!', new ResolvedOperation(new NumberType(), fn(mixed $value) => $value));

        $this->assertSame($resolved, (new UnaryOperatorResolver([$refused, $first]))->resolve('!', new BooleanType())->unwrap());

        $one = (new UnaryOperatorResolver([$first, $second]))->resolve('!', new BooleanType())->unwrapErr()->message;
        $two = (new UnaryOperatorResolver([$second, $first]))->resolve('!', new BooleanType())->unwrapErr()->message;

        $this->assertSame($one, $two);
        $this->assertStringContainsString('Unary operator [!] over Boolean is ambiguous:', $one);
        $this->assertStringContainsString($first::class, $one);
        $this->assertStringContainsString($second::class, $one);
    }

    #[Test]
    public function an_attributed_unary_resolution_names_its_rule_and_extension(): void
    {
        $operation = new ResolvedOperation(new BooleanType(), fn(bool $value) => !$value);
        $rule = self::rule('!', $operation);
        $resolved = (new UnaryOperatorResolver([$rule], ['catalogue.compatibility']))
            ->resolve('!', new BooleanType())
            ->unwrap();

        $this->assertNotSame($operation, $resolved);
        $this->assertSame($rule::class, $resolved->provenance?->identifier);
        $this->assertSame($rule::class, $resolved->provenance?->implementation);
        $this->assertSame('catalogue.compatibility', $resolved->provenance?->extension);
    }

    #[Test]
    public function extension_provenance_must_align_with_unary_rules(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('align one-for-one');

        new UnaryOperatorResolver([self::rule('!', new UnsupportedOperation('No.'))], ['one', 'extra']);
    }

    #[Test]
    public function every_claimed_symbol_is_enumerable_once_and_sorted(): void
    {
        $calls = 0;
        $resolver = new UnaryOperatorResolver([
            self::rule('not', new UnsupportedOperation('No.'), $calls),
            self::rule('!', new UnsupportedOperation('No.')),
            self::rule('!', new UnsupportedOperation('No.')),
        ]);

        $this->assertSame(['!', 'not'], $resolver->symbols());
        $this->assertSame(0, $calls, 'enumeration reads the index; it never asks a rule to resolve');
    }

    #[Test]
    public function each_symbol_names_the_extensions_that_claim_it(): void
    {
        $attributed = new UnaryOperatorResolver(
            [
                // Two rows one extension owns, over different operand types.
                self::rule('not', new UnsupportedOperation('No.')),
                self::rule('not', new UnsupportedOperation('No.')),
                self::rule('-', new UnsupportedOperation('No.')),
                self::rule('-', new UnsupportedOperation('No.')),
            ],
            ['axiom.core', 'axiom.core', 'axiom.date', 'axiom.core'],
        );

        $this->assertSame([
            '-' => ['axiom.core', 'axiom.date'],
            'not' => ['axiom.core'],
        ], $attributed->extensions(), 'an owner of several rows for one symbol is named once');
        $this->assertSame($attributed->symbols(), array_keys($attributed->extensions()));

        $unattributed = new UnaryOperatorResolver([self::rule('!', new UnsupportedOperation('No.'))]);
        $this->assertSame(['!' => ['unattributed']], $unattributed->extensions());
    }

    #[Test]
    public function an_unknown_resolution_variant_is_rejected(): void
    {
        $unknown = new class implements OperatorResolution {};
        $resolver = new UnaryOperatorResolver([self::rule('!', $unknown)]);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Unknown operator resolution');

        $resolver->resolve('!', new BooleanType());
    }

    /** A rule that resolves present operand types only, like every dispatch-table row. */
    private static function presentOnlyRule(string $operator, ResolvedOperation $operation, ?int &$calls = null): UnaryOperatorRule
    {
        return new class ($operator, $operation, $calls) implements UnaryOperatorRule {
            public function __construct(
                private readonly string $symbol,
                private readonly ResolvedOperation $operation,
                private ?int &$calls,
            ) {}

            public function operator(): string
            {
                return $this->symbol;
            }

            public function resolve(Type $operand): OperatorResolution
            {
                ++$this->calls;

                if ($operand instanceof OptionType) {
                    return new UnsupportedOperation('Absent operands refused.');
                }

                return $this->operation;
            }
        };
    }

    /** The distinct-class twin of presentOnlyRule, so ambiguity is observable. */
    private static function alternativePresentOnlyRule(string $operator, ResolvedOperation $operation): UnaryOperatorRule
    {
        return new class ($operator, $operation) implements UnaryOperatorRule {
            public function __construct(
                private readonly string $symbol,
                private readonly ResolvedOperation $operation,
            ) {}

            public function operator(): string
            {
                return $this->symbol;
            }

            public function resolve(Type $operand): OperatorResolution
            {
                if ($operand instanceof OptionType) {
                    return new UnsupportedOperation('Absent operands refused.');
                }

                return $this->operation;
            }
        };
    }

    #[Test]
    public function a_present_operand_never_attempts_a_lift(): void
    {
        $calls = 0;
        $operation = new ResolvedOperation(new BooleanType(), fn() => true);
        $resolver = new UnaryOperatorResolver([self::presentOnlyRule('!', $operation, $calls)]);

        $this->assertSame($operation, $resolver->resolve('!', new BooleanType())->unwrap());
        $this->assertSame(1, $calls);
    }

    #[Test]
    public function a_rule_reading_an_optional_operand_wins_untouched(): void
    {
        $calls = 0;
        $operation = new ResolvedOperation(new BooleanType(), fn() => true);
        $resolver = new UnaryOperatorResolver([self::rule('!', $operation, $calls)]);

        $this->assertSame($operation, $resolver->resolve('!', new OptionType(new BooleanType()))->unwrap());
        $this->assertSame(1, $calls);
    }

    #[Test]
    public function lifting_preserves_provenance(): void
    {
        $rule = self::presentOnlyRule('!', new ResolvedOperation(new BooleanType(), fn(bool $operand) => !$operand));
        $lifted = (new UnaryOperatorResolver([$rule], ['axiom.core']))
            ->resolve('!', new OptionType(new BooleanType()))
            ->unwrap();

        $this->assertSame('axiom.core', $lifted->provenance?->extension);
        $this->assertFalse($lifted->evaluate(true)->unwrap(), 'a present operand still runs the rule');
    }

    #[Test]
    public function lifted_ambiguity_names_the_present_type(): void
    {
        $resolver = new UnaryOperatorResolver([
            self::presentOnlyRule('!', new ResolvedOperation(new BooleanType(), fn() => true)),
            self::alternativePresentOnlyRule('!', new ResolvedOperation(new BooleanType(), fn() => false)),
        ]);

        $message = $resolver->resolve('!', new OptionType(new BooleanType()))->unwrapErr()->message;

        $this->assertStringContainsString('Unary operator [!] over Boolean is ambiguous:', $message);
    }

    #[Test]
    public function a_failed_lift_reports_the_exact_refusal(): void
    {
        $resolver = new UnaryOperatorResolver([self::rule('!', new UnsupportedOperation('Not for this.'))]);

        $verdict = $resolver->resolve('!', new OptionType(new NumberType()))->unwrapErr();

        $this->assertSame('Not for this.', $verdict->message, 'stage-one diagnostics stand when the lift resolves nothing');
    }
}
