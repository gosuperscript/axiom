<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Sources;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
        $this->assertSame("the value 'hello'", $source->describe());
    }

    #[Test]
    public function static_source_describes_integer_value(): void
    {
        $source = new StaticSource(42);
        $this->assertSame('the value 42', $source->describe());
    }

    #[Test]
    public function static_source_describes_null_value(): void
    {
        $source = new StaticSource(null);
        $this->assertSame('the value null', $source->describe());
    }

    #[Test]
    public function static_source_describes_boolean_value(): void
    {
        $source = new StaticSource(true);
        $this->assertSame('the value true', $source->describe());
    }

    #[Test]
    public function symbol_source_describes_name(): void
    {
        $source = new SymbolSource('price');
        $this->assertSame("the symbol 'price'", $source->describe());
    }

    #[Test]
    public function symbol_source_describes_namespaced_name(): void
    {
        $source = new SymbolSource('pi', 'math');
        $this->assertSame("the symbol 'math.pi'", $source->describe());
    }

    #[Test]
    public function type_definition_describes_as_type(): void
    {
        $source = new TypeDefinition(new NumberType(), new SymbolSource('price'));
        $this->assertSame("the symbol 'price' as a number", $source->describe());
    }

    #[Test]
    public function type_definition_describes_string_type(): void
    {
        $source = new TypeDefinition(new StringType(), new StaticSource('hello'));
        $this->assertSame("the value 'hello' as a string", $source->describe());
    }

    #[Test]
    public function type_definition_describes_boolean_type(): void
    {
        $source = new TypeDefinition(new BooleanType(), new SymbolSource('active'));
        $this->assertSame("the symbol 'active' as a boolean", $source->describe());
    }

    #[Test]
    public function type_definition_describes_list_type(): void
    {
        $source = new TypeDefinition(new ListType(new NumberType()), new SymbolSource('values'));
        $this->assertSame("the symbol 'values' as a list", $source->describe());
    }

    #[Test]
    public function type_definition_with_non_describable_source_falls_back_to_class_name(): void
    {
        $anonymous = new class implements Source {};

        $source = new TypeDefinition(new NumberType(), $anonymous);

        $description = $source->describe();
        $this->assertStringEndsWith('as a number', $description);
    }

    #[Test]
    public function infix_expression_describes_addition(): void
    {
        $source = new InfixExpression(
            new StaticSource(1),
            '+',
            new StaticSource(2),
        );
        $this->assertSame('the value 1 plus the value 2', $source->describe());
    }

    #[Test]
    public function infix_expression_describes_multiplication(): void
    {
        $source = new InfixExpression(
            new SymbolSource('price'),
            '*',
            new SymbolSource('quantity'),
        );
        $this->assertSame("the symbol 'price' multiplied by the symbol 'quantity'", $source->describe());
    }

    #[Test]
    public function infix_expression_describes_comparison(): void
    {
        $source = new InfixExpression(
            new SymbolSource('age'),
            '>=',
            new StaticSource(18),
        );
        $this->assertSame("the symbol 'age' greater than or equal to the value 18", $source->describe());
    }

    #[DataProvider('operatorProvider')]
    #[Test]
    public function infix_expression_describes_all_operators(string $operator, string $expectedWord): void
    {
        $source = new InfixExpression(
            new StaticSource(1),
            $operator,
            new StaticSource(2),
        );
        $this->assertSame(
            sprintf('the value 1 %s the value 2', $expectedWord),
            $source->describe(),
        );
    }

    public static function operatorProvider(): array
    {
        return [
            'addition' => ['+', 'plus'],
            'subtraction' => ['-', 'minus'],
            'multiplication' => ['*', 'multiplied by'],
            'division' => ['/', 'divided by'],
            'equality' => ['==', 'equal to'],
            'identity' => ['===', 'identical to'],
            'inequality' => ['!=', 'not equal to'],
            'non-identity' => ['!==', 'not identical to'],
            'less than' => ['<', 'less than'],
            'less than or equal' => ['<=', 'less than or equal to'],
            'greater than' => ['>', 'greater than'],
            'greater than or equal' => ['>=', 'greater than or equal to'],
            'logical and' => ['&&', 'and'],
            'logical or' => ['||', 'or'],
            'custom operator' => ['intersects', 'intersects'],
        ];
    }

    #[Test]
    public function infix_expression_describes_custom_operator(): void
    {
        $source = new InfixExpression(
            new SymbolSource('tags'),
            'has',
            new StaticSource('featured'),
        );
        $this->assertSame("the symbol 'tags' has the value 'featured'", $source->describe());
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
            "the symbol 'price' multiplied by (the value 1 minus the symbol 'discount')",
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
        $this->assertStringEndsWith('plus the value 1', $description);
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
        $this->assertStringStartsWith('the value 1 plus ', $description);
    }

    #[Test]
    public function unary_expression_describes_negation(): void
    {
        $source = new UnaryExpression('!', new SymbolSource('active'));
        $this->assertSame("the negation of the symbol 'active'", $source->describe());
    }

    #[Test]
    public function unary_expression_describes_negative(): void
    {
        $source = new UnaryExpression('-', new StaticSource(5));
        $this->assertSame('the negative of the value 5', $source->describe());
    }

    #[Test]
    public function unary_expression_describes_unknown_operator(): void
    {
        $source = new UnaryExpression('~', new StaticSource(5));
        $this->assertSame('~ the value 5', $source->describe());
    }

    #[Test]
    public function unary_with_non_describable_source_falls_back_to_class_name(): void
    {
        $anonymous = new class implements Source {};

        $source = new UnaryExpression('!', $anonymous);

        $description = $source->describe();
        $this->assertStringStartsWith('the negation of ', $description);
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
            "the symbol 'price' as a number multiplied by (the value 1 minus the symbol 'rates.discount' as a number)",
            $source->describe(),
        );
    }
}
