<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Operators;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\Operators\Operator;
use Superscript\Axiom\Operators\BinaryOperatorRule;
use Superscript\Axiom\Operators\BinaryOperatorResolver;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Operators\Signatures\InfixSignature;
use Superscript\Axiom\Operators\Signatures\InfixSignatureBuilder;
use Superscript\Axiom\Operators\Signatures\InfixSignatureWithOperands;
use Superscript\Axiom\Operators\Signatures\InfixSignatureWithReturn;
use Superscript\Axiom\Operators\Signatures\PrefixSignature;
use Superscript\Axiom\Operators\Signatures\PrefixSignatureBuilder;
use Superscript\Axiom\Operators\Signatures\PrefixSignatureWithOperand;
use Superscript\Axiom\Operators\Signatures\PrefixSignatureWithReturn;
use Superscript\Axiom\Operators\UnaryOperatorRule;
use Superscript\Axiom\Operators\UnsupportedOperation;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\UnknownType;

use function Superscript\Monads\Result\Ok;

#[CoversClass(Operator::class)]
#[CoversClass(InfixSignatureBuilder::class)]
#[CoversClass(InfixSignatureWithOperands::class)]
#[CoversClass(InfixSignatureWithReturn::class)]
#[CoversClass(InfixSignature::class)]
#[CoversClass(PrefixSignatureBuilder::class)]
#[CoversClass(PrefixSignatureWithOperand::class)]
#[CoversClass(PrefixSignatureWithReturn::class)]
#[CoversClass(PrefixSignature::class)]
#[CoversClass(ResolvedOperation::class)]
#[UsesClass(UnsupportedOperation::class)]
#[UsesClass(BinaryOperatorResolver::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(UnknownType::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnknownShape::class)]
final class SignatureTest extends TestCase
{
    private static function concat(): InfixSignature
    {
        return Operator::infix('++')
            ->signature(new StringType(), new StringType())
            ->returns(new StringType())
            ->evaluate(fn(string $a, string $b) => $a . $b);
    }

    private static function absolute(): PrefixSignature
    {
        return Operator::prefix('abs')
            ->signature(new NumberType())
            ->returns(new NumberType())
            ->evaluate(fn(int|float $n) => abs($n));
    }

    #[Test]
    public function the_staged_chain_compiles_to_an_operator_rule(): void
    {
        $this->assertInstanceOf(BinaryOperatorRule::class, self::concat());
        $this->assertInstanceOf(UnaryOperatorRule::class, self::absolute());
    }

    #[Test]
    public function resolution_certifies_admissible_operand_types_with_the_declared_return_type(): void
    {
        $returns = new StringType();

        $rule = Operator::infix('++')
            ->signature(new StringType(), new StringType())
            ->returns($returns)
            ->evaluate(fn(string $a, string $b) => $a . $b);

        $resolved = $rule->resolve(new StringType(), new StringType());
        $this->assertInstanceOf(ResolvedOperation::class, $resolved);
        $this->assertSame($returns, $resolved->returns);
        // Admissibility, not equality: a subtype fills the slot.
        $literal = $rule->resolve(new LiteralType('a'), new StringType());
        $this->assertInstanceOf(ResolvedOperation::class, $literal);
        $this->assertSame($returns, $literal->returns);
    }

    #[Test]
    public function the_resolved_evaluation_is_the_declared_closure(): void
    {
        $operation = self::concat()->resolve(new StringType(), new StringType());

        $this->assertInstanceOf(ResolvedOperation::class, $operation);
        $this->assertSame('ab', $operation->evaluate('a', 'b')->unwrap());
    }

    #[Test]
    public function an_inert_unknown_operand_is_refused_with_the_fix(): void
    {
        $verdict = self::concat()->resolve(new UnknownType(), new StringType());

        $this->assertInstanceOf(UnsupportedOperation::class, $verdict);
        $this->assertStringContainsString('An Unknown operand is inert', $verdict->causes[0]->describe());
        $this->assertStringContainsString('Ascription', $verdict->causes[0]->describe());
    }

    #[Test]
    public function plain_return_values_are_wrapped_in_ok(): void
    {
        $operation = new ResolvedOperation(new StringType(), fn(string $a, string $b) => $a . $b);

        $this->assertSame('ab', $operation->evaluate('a', 'b')->unwrap());
    }

    #[Test]
    public function a_returned_result_passes_through_untouched(): void
    {
        $partial = Ok('ab');
        $operation = new ResolvedOperation(new StringType(), fn(string $a, string $b) => $partial);

        $this->assertSame($partial, $operation->evaluate('a', 'b'));
    }

    #[Test]
    public function a_throwing_closure_propagates(): void
    {
        $operation = new ResolvedOperation(new StringType(), fn(string $a, string $b) => throw new RuntimeException('a defect of the extension'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a defect of the extension');

        $operation->evaluate('a', 'b');
    }

    #[Test]
    public function refuses_inadmissible_operands_naming_both_expectation_and_arrival(): void
    {
        $verdict = self::concat()->resolve(new NumberType(), new StringType());

        $this->assertInstanceOf(UnsupportedOperation::class, $verdict);
        $this->assertSame('[++] expects String and String; got Number and String.', $verdict->message);
        $this->assertCount(1, $verdict->causes);
    }

    #[Test]
    public function each_inadmissible_side_contributes_its_own_cause(): void
    {
        $right = self::concat()->resolve(new StringType(), new NumberType());
        $this->assertInstanceOf(UnsupportedOperation::class, $right);
        $this->assertCount(1, $right->causes);

        $both = self::concat()->resolve(new NumberType(), new BooleanType());
        $this->assertInstanceOf(UnsupportedOperation::class, $both);
        $this->assertCount(2, $both->causes);
        $this->assertSame('[++] expects String and String; got Number and Boolean.', $both->message);
    }

    #[Test]
    public function an_infix_signature_advertises_its_operator(): void
    {
        $this->assertSame('++', self::concat()->operator());
    }

    #[Test]
    public function signature_families_compose_in_the_manager(): void
    {
        $manager = new BinaryOperatorResolver([
            self::concat(),
            Operator::infix('++')
                ->signature(new NumberType(), new NumberType())
                ->returns(new NumberType())
                ->evaluate(fn(int|float $a, int|float $b) => $a + $b),
        ]);

        // Each row resolves its own operand types; the rows are disjoint,
        // so no pair has two owners.
        $strings = $manager->resolve('++', new StringType(), new StringType())->unwrap();
        $numbers = $manager->resolve('++', new NumberType(), new NumberType())->unwrap();

        $this->assertInstanceOf(StringType::class, $strings->returns);
        $this->assertInstanceOf(NumberType::class, $numbers->returns);
        $this->assertSame('ab', $strings->evaluate('a', 'b')->unwrap());
        $this->assertSame(3, $numbers->evaluate(1, 2)->unwrap());

        // A pair matching no row gets the manager's honest aggregate.
        $orphan = $manager->resolve('++', new BooleanType(), new BooleanType());
        $this->assertStringContainsString('No overload of [++] accepts Boolean and Boolean.', $orphan->unwrapErr()->message);
    }

    #[Test]
    public function prefix_resolution_certifies_admissible_operand_types(): void
    {
        $returns = new NumberType();

        $rule = Operator::prefix('abs')
            ->signature(new NumberType())
            ->returns($returns)
            ->evaluate(fn(int|float $n) => abs($n));

        $number = $rule->resolve(new NumberType());
        $literal = $rule->resolve(new LiteralType(5));
        $this->assertInstanceOf(ResolvedOperation::class, $number);
        $this->assertInstanceOf(ResolvedOperation::class, $literal);
        $this->assertSame($returns, $number->returns);
        $this->assertSame($returns, $literal->returns);
        $this->assertSame(7, $number->evaluate(-7)->unwrap());
    }

    #[Test]
    public function prefix_wraps_plain_values_and_passes_results_through(): void
    {
        $partial = Ok(7);
        $rule = Operator::prefix('abs')
            ->signature(new NumberType())
            ->returns(new NumberType())
            ->evaluate(fn(int|float $n) => $partial);

        $operation = $rule->resolve(new NumberType());
        $this->assertInstanceOf(ResolvedOperation::class, $operation);
        $this->assertSame($partial, $operation->evaluate(-7));
    }

    #[Test]
    public function prefix_refuses_inadmissible_operands_with_a_cause_chain(): void
    {
        $verdict = self::absolute()->resolve(new StringType());

        $this->assertInstanceOf(UnsupportedOperation::class, $verdict);
        $this->assertSame('[abs] expects Number; got String.', $verdict->message);
        $this->assertCount(1, $verdict->causes);
    }

    #[Test]
    public function prefix_refuses_an_inert_unknown(): void
    {
        $verdict = self::absolute()->resolve(new UnknownType());

        $this->assertInstanceOf(UnsupportedOperation::class, $verdict);
        $this->assertStringContainsString('An Unknown operand is inert', $verdict->causes[0]->describe());
    }

    #[Test]
    public function a_prefix_signature_advertises_its_operator(): void
    {
        $this->assertSame('abs', self::absolute()->operator());
    }

    #[Test]
    public function an_option_operand_on_a_prefix_signature_is_rejected_at_declaration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('absence never reaches a unary rule');

        Operator::prefix('abs')->signature(new OptionType(new NumberType()));
    }
}
