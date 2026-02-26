<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Sources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\TypeDefinition;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;

#[CoversClass(StaticSource::class)]
#[CoversClass(SymbolSource::class)]
#[CoversClass(TypeDefinition::class)]
#[CoversClass(InfixExpression::class)]
#[CoversClass(UnaryExpression::class)]
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
        $anonymous = new class implements Source {};

        $source = new TypeDefinition(new NumberType(), $anonymous);

        $description = $source->describe();
        $this->assertStringEndsWith('(as number)', $description);
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
        $anonymous = new class implements Source {};

        $source = new InfixExpression(
            $anonymous,
            '+',
            new StaticSource(1),
        );

        $description = $source->describe();
        $this->assertStringEndsWith('+ 1', $description);
    }

    #[Test]
    public function infix_with_non_describable_right_falls_back_to_class_name(): void
    {
        $anonymous = new class implements Source {};

        $source = new InfixExpression(
            new StaticSource(1),
            '+',
            $anonymous,
        );

        $description = $source->describe();
        $this->assertStringStartsWith('1 + ', $description);
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
        $anonymous = new class implements Source {};

        $source = new UnaryExpression('!', $anonymous);

        $description = $source->describe();
        $this->assertStringStartsWith('!', $description);
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
