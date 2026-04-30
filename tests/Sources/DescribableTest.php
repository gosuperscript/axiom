<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Sources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\TypeDefinition;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Tests\Sources\Fixtures\UndescribablePattern;
use Superscript\Axiom\Tests\Sources\Fixtures\UndescribableSource;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;

#[CoversClass(StaticSource::class)]
#[CoversClass(SymbolSource::class)]
#[CoversClass(TypeDefinition::class)]
#[CoversClass(InfixExpression::class)]
#[CoversClass(UnaryExpression::class)]
#[CoversClass(MemberAccessSource::class)]
#[CoversClass(MatchExpression::class)]
#[CoversClass(MatchArm::class)]
#[CoversClass(LiteralPattern::class)]
#[CoversClass(WildcardPattern::class)]
#[CoversClass(ExpressionPattern::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(ListType::class)]
class DescribableTest extends TestCase
{
    #[Test]
    public function static_source_describes_string_value(): void
    {
        $source = new StaticSource('hello');
        $this->assertSame("'hello'", $source->describe());
    }

    #[Test]
    public function static_source_describes_integer_value(): void
    {
        $source = new StaticSource(42);
        $this->assertSame('42', $source->describe());
    }

    #[Test]
    public function static_source_describes_null_value(): void
    {
        $source = new StaticSource(null);
        $this->assertSame('null', $source->describe());
    }

    #[Test]
    public function static_source_describes_boolean_value(): void
    {
        $source = new StaticSource(true);
        $this->assertSame('true', $source->describe());
    }

    #[Test]
    public function symbol_source_describes_name(): void
    {
        $source = new SymbolSource('price');
        $this->assertSame('price', $source->describe());
    }

    #[Test]
    public function symbol_source_describes_namespaced_name(): void
    {
        $source = new SymbolSource('pi', 'math');
        $this->assertSame('math.pi', $source->describe());
    }

    #[Test]
    public function type_definition_describes_as_type(): void
    {
        $source = new TypeDefinition(new NumberType(), new SymbolSource('price'));
        $this->assertSame('price (as number)', $source->describe());
    }

    #[Test]
    public function type_definition_describes_string_type(): void
    {
        $source = new TypeDefinition(new StringType(), new StaticSource('hello'));
        $this->assertSame("'hello' (as string)", $source->describe());
    }

    #[Test]
    public function type_definition_describes_boolean_type(): void
    {
        $source = new TypeDefinition(new BooleanType(), new SymbolSource('active'));
        $this->assertSame('active (as boolean)', $source->describe());
    }

    #[Test]
    public function type_definition_describes_list_type(): void
    {
        $source = new TypeDefinition(new ListType(new NumberType()), new SymbolSource('values'));
        $this->assertSame('values (as list)', $source->describe());
    }

    #[Test]
    public function type_definition_with_non_describable_source_falls_back_to_class_name(): void
    {
        $source = new TypeDefinition(new NumberType(), new UndescribableSource());

        $this->assertSame('UndescribableSource (as number)', $source->describe());
    }

    #[Test]
    public function infix_expression_describes_operation(): void
    {
        $source = new InfixExpression(
            new StaticSource(1),
            '+',
            new StaticSource(2),
        );
        $this->assertSame('1 + 2', $source->describe());
    }

    #[Test]
    public function infix_expression_describes_comparison(): void
    {
        $source = new InfixExpression(
            new SymbolSource('age'),
            '>=',
            new StaticSource(18),
        );
        $this->assertSame('age >= 18', $source->describe());
    }

    #[Test]
    public function infix_expression_describes_custom_operator(): void
    {
        $source = new InfixExpression(
            new SymbolSource('tags'),
            'has',
            new StaticSource('featured'),
        );
        $this->assertSame("tags has 'featured'", $source->describe());
    }

    #[Test]
    public function infix_expression_wraps_nested_infix_in_parentheses(): void
    {
        $source = new InfixExpression(
            new SymbolSource('price'),
            '*',
            new InfixExpression(
                new StaticSource(1),
                '-',
                new SymbolSource('discount'),
            ),
        );
        $this->assertSame(
            'price * (1 - discount)',
            $source->describe(),
        );
    }

    #[Test]
    public function infix_with_non_describable_left_falls_back_to_class_name(): void
    {
        $source = new InfixExpression(
            new UndescribableSource(),
            '+',
            new StaticSource(1),
        );

        $this->assertSame('UndescribableSource + 1', $source->describe());
    }

    #[Test]
    public function infix_with_non_describable_right_falls_back_to_class_name(): void
    {
        $source = new InfixExpression(
            new StaticSource(1),
            '+',
            new UndescribableSource(),
        );

        $this->assertSame('1 + UndescribableSource', $source->describe());
    }

    #[Test]
    public function unary_expression_describes_negation(): void
    {
        $source = new UnaryExpression('!', new SymbolSource('active'));
        $this->assertSame('!active', $source->describe());
    }

    #[Test]
    public function unary_expression_describes_negative(): void
    {
        $source = new UnaryExpression('-', new StaticSource(5));
        $this->assertSame('-5', $source->describe());
    }

    #[Test]
    public function unary_with_non_describable_source_falls_back_to_class_name(): void
    {
        $source = new UnaryExpression('!', new UndescribableSource());

        $this->assertSame('!UndescribableSource', $source->describe());
    }

    #[Test]
    public function unary_wraps_infix_operand_in_parentheses(): void
    {
        $source = new UnaryExpression(
            '!',
            new InfixExpression(
                new SymbolSource('a'),
                '||',
                new SymbolSource('b'),
            ),
        );

        $this->assertSame('!(a || b)', $source->describe());
    }

    #[Test]
    public function member_access_describes_property(): void
    {
        $source = new MemberAccessSource(new SymbolSource('user'), 'name');
        $this->assertSame('user.name', $source->describe());
    }

    #[Test]
    public function member_access_describes_chained_property(): void
    {
        $source = new MemberAccessSource(
            new MemberAccessSource(new SymbolSource('user'), 'address'),
            'city',
        );
        $this->assertSame('user.address.city', $source->describe());
    }

    #[Test]
    public function member_access_with_non_describable_object_falls_back_to_class_name(): void
    {
        $source = new MemberAccessSource(new UndescribableSource(), 'name');

        $this->assertSame('UndescribableSource.name', $source->describe());
    }

    #[Test]
    public function literal_pattern_describes_value(): void
    {
        $pattern = new LiteralPattern(42);
        $this->assertSame('42', $pattern->describe());
    }

    #[Test]
    public function literal_pattern_describes_string_value(): void
    {
        $pattern = new LiteralPattern('hello');
        $this->assertSame("'hello'", $pattern->describe());
    }

    #[Test]
    public function wildcard_pattern_describes_as_underscore(): void
    {
        $pattern = new WildcardPattern();
        $this->assertSame('_', $pattern->describe());
    }

    #[Test]
    public function expression_pattern_describes_inner_source(): void
    {
        $pattern = new ExpressionPattern(new SymbolSource('x'));
        $this->assertSame('x', $pattern->describe());
    }

    #[Test]
    public function expression_pattern_with_non_describable_source_falls_back_to_class_name(): void
    {
        $pattern = new ExpressionPattern(new UndescribableSource());

        $this->assertSame('UndescribableSource', $pattern->describe());
    }

    #[Test]
    public function match_arm_describes_pattern_and_expression(): void
    {
        $arm = new MatchArm(
            new LiteralPattern(1),
            new StaticSource('one'),
        );
        $this->assertSame("1 => 'one'", $arm->describe());
    }

    #[Test]
    public function match_arm_with_non_describable_pattern_falls_back_to_class_name(): void
    {
        $arm = new MatchArm(new UndescribablePattern(), new StaticSource('result'));

        $this->assertSame("UndescribablePattern => 'result'", $arm->describe());
    }

    #[Test]
    public function match_arm_with_non_describable_expression_falls_back_to_class_name(): void
    {
        $arm = new MatchArm(new WildcardPattern(), new UndescribableSource());

        $this->assertSame('_ => UndescribableSource', $arm->describe());
    }

    #[Test]
    public function match_expression_describes_subject_and_arms(): void
    {
        $source = new MatchExpression(
            new SymbolSource('status'),
            [
                new MatchArm(new LiteralPattern('on'), new StaticSource(true)),
                new MatchArm(new WildcardPattern(), new StaticSource(false)),
            ],
        );
        $this->assertSame(
            "match status { 'on' => true, _ => false }",
            $source->describe(),
        );
    }

    #[Test]
    public function match_expression_with_non_describable_subject_falls_back_to_class_name(): void
    {
        $source = new MatchExpression(
            new UndescribableSource(),
            [new MatchArm(new WildcardPattern(), new StaticSource(0))],
        );

        $this->assertSame('match UndescribableSource { _ => 0 }', $source->describe());
    }

    #[Test]
    public function complex_nested_expression(): void
    {
        $source = new InfixExpression(
            new TypeDefinition(
                new NumberType(),
                new SymbolSource('price'),
            ),
            '*',
            new InfixExpression(
                new StaticSource(1),
                '-',
                new TypeDefinition(
                    new NumberType(),
                    new SymbolSource('discount', 'rates'),
                ),
            ),
        );

        $this->assertSame(
            'price (as number) * (1 - rates.discount (as number))',
            $source->describe(),
        );
    }
}
