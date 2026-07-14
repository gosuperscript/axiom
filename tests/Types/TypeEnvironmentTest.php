<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\TypeEnvironment;
use Superscript\Axiom\Types\TypeInference;

#[CoversClass(TypeEnvironment::class)]
#[UsesClass(TypeInference::class)]
#[UsesClass(Definitions::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(InfixExpression::class)]
#[UsesClass(DefaultOverloader::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(\Superscript\Axiom\Operators\OverloaderManager::class)]
#[UsesClass(\Superscript\Axiom\Operators\BinaryOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\ComparisonOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\HasOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\InOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\IntersectsOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\LogicalOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\NullOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOverloaderManager::class)]
#[UsesClass(\Superscript\Axiom\Operators\NotOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\NegateOverloader::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralType::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\BooleanType::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
final class TypeEnvironmentTest extends TestCase
{
    private static function inference(): TypeInference
    {
        return new TypeInference(new DefaultOverloader());
    }

    #[Test]
    public function a_declared_type_terminates_recursion(): void
    {
        $environment = new TypeEnvironment(declarations: ['turnover' => new NumberType()]);

        $result = $environment->typeOfSymbol('turnover', null, self::inference());

        $this->assertInstanceOf(NumberType::class, $result->unwrap());
    }

    #[Test]
    public function a_namespaced_declaration_uses_the_flattened_key(): void
    {
        $environment = new TypeEnvironment(declarations: ['customer.turnover' => new NumberType()]);

        $result = $environment->typeOfSymbol('turnover', 'customer', self::inference());

        $this->assertInstanceOf(NumberType::class, $result->unwrap());
    }

    #[Test]
    public function a_derived_symbol_is_inferred_through_the_symbol_graph(): void
    {
        $environment = new TypeEnvironment(
            definitions: new Definitions([
                'base' => new StaticSource(2),
                'derived' => new InfixExpression(
                    left: new SymbolSource('base'),
                    operator: '*',
                    right: new StaticSource(3),
                ),
            ]),
        );

        $result = $environment->typeOfSymbol('derived', null, self::inference());

        $this->assertInstanceOf(NumberType::class, $result->unwrap());
    }

    #[Test]
    public function inferred_symbol_types_are_memoized(): void
    {
        $environment = new TypeEnvironment(
            definitions: new Definitions(['base' => new StaticSource(2)]),
        );

        $first = $environment->typeOfSymbol('base', null, self::inference());
        $second = $environment->typeOfSymbol('base', null, self::inference());

        $this->assertSame($first, $second);
    }

    #[Test]
    public function a_cyclic_definition_is_reported_with_its_chain(): void
    {
        $environment = new TypeEnvironment(
            definitions: new Definitions([
                'a' => new InfixExpression(new SymbolSource('b'), '+', new StaticSource(1)),
                'b' => new InfixExpression(new SymbolSource('a'), '+', new StaticSource(1)),
            ]),
        );

        $result = $environment->typeOfSymbol('a', null, self::inference());

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('Cyclic symbol definition: a → b → a.', $result->unwrapErr()->describe());
    }

    #[Test]
    public function completed_inferences_leave_no_trace_in_the_cycle_chain(): void
    {
        $environment = new TypeEnvironment(
            definitions: new Definitions([
                'x' => new StaticSource(1),
                'a' => new SymbolSource('a'),
            ]),
        );

        $this->assertTrue($environment->typeOfSymbol('x', null, self::inference())->isOk());

        $cycle = $environment->typeOfSymbol('a', null, self::inference());

        $this->assertSame('Cyclic symbol definition: a → a.', $cycle->unwrapErr()->message);
    }

    #[Test]
    public function an_unbound_symbol_is_an_error(): void
    {
        $environment = new TypeEnvironment();

        $result = $environment->typeOfSymbol('ghost', 'customer', self::inference());

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('Unbound symbol [customer.ghost]', $result->unwrapErr()->message);
    }
}
