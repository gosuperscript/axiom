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
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Operators\OverloaderManager;
use Superscript\Axiom\Operators\Signatures\InfixSignature;
use Superscript\Axiom\Operators\Signatures\InfixSignatureBuilder;
use Superscript\Axiom\Operators\Signatures\InfixSignatureWithOperands;
use Superscript\Axiom\Operators\Signatures\InfixSignatureWithReturn;
use Superscript\Axiom\Operators\Signatures\PrefixSignature;
use Superscript\Axiom\Operators\Signatures\PrefixSignatureBuilder;
use Superscript\Axiom\Operators\Signatures\PrefixSignatureWithOperand;
use Superscript\Axiom\Operators\Signatures\PrefixSignatureWithReturn;
use Superscript\Axiom\Operators\UnaryOverloader;
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
#[UsesClass(OverloaderManager::class)]
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
#[UsesClass(\Superscript\Axiom\Exceptions\TransformValueException::class)]
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
        $this->assertInstanceOf(OperatorOverloader::class, self::concat());
        $this->assertInstanceOf(UnaryOverloader::class, self::absolute());
    }

    #[Test]
    public function claiming_is_strict_membership_on_the_declared_operand_types(): void
    {
        $rule = self::concat();

        $this->assertTrue($rule->supportsOverloading('a', 'b', '++'));
        $this->assertFalse($rule->supportsOverloading(5, 'b', '++'));
        $this->assertFalse($rule->supportsOverloading('a', 5, '++'));
        $this->assertFalse($rule->supportsOverloading('a', 'b', '+'));
    }

    #[Test]
    public function plain_return_values_are_wrapped_in_ok(): void
    {
        $this->assertSame('ab', self::concat()->evaluate('a', 'b', '++')->unwrap());
    }

    #[Test]
    public function a_returned_result_passes_through_untouched(): void
    {
        $partial = Ok('ab');

        $rule = Operator::infix('++')
            ->signature(new StringType(), new StringType())
            ->returns(new StringType())
            ->evaluate(fn(string $a, string $b) => $partial);

        $this->assertSame($partial, $rule->evaluate('a', 'b', '++'));
    }

    #[Test]
    public function a_throwing_closure_propagates(): void
    {
        $rule = Operator::infix('++')
            ->signature(new StringType(), new StringType())
            ->returns(new StringType())
            ->evaluate(fn(string $a, string $b) => throw new RuntimeException('a defect of the extension'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a defect of the extension');

        $rule->evaluate('a', 'b', '++');
    }

    #[Test]
    public function handles_only_its_operator(): void
    {
        $this->assertTrue(self::concat()->handles('++'));
        $this->assertFalse(self::concat()->handles('+'));
    }

    #[Test]
    public function certifies_admissible_operand_types_with_the_declared_return_type(): void
    {
        $returns = new StringType();

        $rule = Operator::infix('++')
            ->signature(new StringType(), new StringType())
            ->returns($returns)
            ->evaluate(fn(string $a, string $b) => $a . $b);

        $this->assertSame($returns, $rule->typeOf('++', new StringType(), new StringType())->unwrap());
        // Admissibility, not equality: a subtype fills the slot...
        $this->assertSame($returns, $rule->typeOf('++', new LiteralType('a'), new StringType())->unwrap());
        // ...and Unknown is admitted — gradual typing's sanctioned hole.
        $this->assertSame($returns, $rule->typeOf('++', new UnknownType(), new StringType())->unwrap());
    }

    #[Test]
    public function refuses_inadmissible_operands_naming_both_expectation_and_arrival(): void
    {
        $verdict = self::concat()->typeOf('++', new NumberType(), new StringType());

        $this->assertSame('[++] expects String and String; got Number and String.', $verdict->unwrapErr()->message);
        $this->assertCount(1, $verdict->unwrapErr()->causes);
    }

    #[Test]
    public function each_inadmissible_side_contributes_its_own_cause(): void
    {
        $right = self::concat()->typeOf('++', new StringType(), new NumberType());
        $this->assertCount(1, $right->unwrapErr()->causes);

        $both = self::concat()->typeOf('++', new NumberType(), new BooleanType());
        $this->assertCount(2, $both->unwrapErr()->causes);
        $this->assertSame('[++] expects String and String; got Number and Boolean.', $both->unwrapErr()->message);
    }

    #[Test]
    public function a_foreign_operator_is_refused(): void
    {
        $verdict = self::concat()->typeOf('+', new StringType(), new StringType());

        $this->assertSame('The [++] signature does not handle [+].', $verdict->unwrapErr()->message);
    }

    #[Test]
    public function signature_families_compose_in_the_manager(): void
    {
        $manager = new OverloaderManager([
            self::concat(),
            Operator::infix('++')
                ->signature(new NumberType(), new NumberType())
                ->returns(new NumberType())
                ->evaluate(fn(int|float $a, int|float $b) => $a + $b),
        ]);

        // Each row dispatches its own pairs, runtime and static alike.
        $this->assertSame('ab', $manager->evaluate('a', 'b', '++')->unwrap());
        $this->assertSame(3, $manager->evaluate(1, 2, '++')->unwrap());
        $this->assertInstanceOf(StringType::class, $manager->typeOf('++', new StringType(), new StringType())->unwrap());
        $this->assertInstanceOf(NumberType::class, $manager->typeOf('++', new NumberType(), new NumberType())->unwrap());

        // A pair matching no row gets the manager's honest aggregate.
        $orphan = $manager->typeOf('++', new BooleanType(), new BooleanType());
        $this->assertStringContainsString('No overload of [++] accepts Boolean and Boolean.', $orphan->unwrapErr()->message);
    }

    #[Test]
    public function prefix_claiming_is_strict_membership_on_the_declared_operand_type(): void
    {
        $rule = self::absolute();

        $this->assertTrue($rule->supportsOverloading(-7, 'abs'));
        $this->assertFalse($rule->supportsOverloading('-7', 'abs'));
        $this->assertFalse($rule->supportsOverloading(-7, '-'));
    }

    #[Test]
    public function prefix_wraps_plain_values_and_passes_results_through(): void
    {
        $this->assertSame(7, self::absolute()->evaluate(-7, 'abs')->unwrap());

        $partial = Ok(7);
        $rule = Operator::prefix('abs')
            ->signature(new NumberType())
            ->returns(new NumberType())
            ->evaluate(fn(int|float $n) => $partial);

        $this->assertSame($partial, $rule->evaluate(-7, 'abs'));
    }

    #[Test]
    public function a_throwing_prefix_closure_propagates(): void
    {
        $rule = Operator::prefix('abs')
            ->signature(new NumberType())
            ->returns(new NumberType())
            ->evaluate(fn(int|float $n) => throw new RuntimeException('a defect of the extension'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a defect of the extension');

        $rule->evaluate(-7, 'abs');
    }

    #[Test]
    public function prefix_handles_only_its_operator(): void
    {
        $this->assertTrue(self::absolute()->handles('abs'));
        $this->assertFalse(self::absolute()->handles('-'));
    }

    #[Test]
    public function prefix_certifies_admissible_operand_types(): void
    {
        $returns = new NumberType();

        $rule = Operator::prefix('abs')
            ->signature(new NumberType())
            ->returns($returns)
            ->evaluate(fn(int|float $n) => abs($n));

        $this->assertSame($returns, $rule->typeOf('abs', new NumberType())->unwrap());
        $this->assertSame($returns, $rule->typeOf('abs', new LiteralType(5))->unwrap());
    }

    #[Test]
    public function prefix_refuses_inadmissible_operands_with_a_cause_chain(): void
    {
        $verdict = self::absolute()->typeOf('abs', new StringType());

        $this->assertSame('[abs] expects Number; got String.', $verdict->unwrapErr()->message);
        $this->assertCount(1, $verdict->unwrapErr()->causes);
    }

    #[Test]
    public function a_foreign_prefix_operator_is_refused(): void
    {
        $verdict = self::absolute()->typeOf('-', new NumberType());

        $this->assertSame('The [abs] signature does not handle [-].', $verdict->unwrapErr()->message);
    }

    #[Test]
    public function an_option_operand_on_a_prefix_signature_is_rejected_at_declaration(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('absence never reaches a unary rule');

        Operator::prefix('abs')->signature(new OptionType(new NumberType()));
    }
}
