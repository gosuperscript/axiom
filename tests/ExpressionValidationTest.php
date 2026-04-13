<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Bindings;
use Superscript\Axiom\Context;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Exceptions\TypeCheckException;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\InfixResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\SymbolResolver;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;

#[CoversClass(Expression::class)]
class ExpressionValidationTest extends TestCase
{
    private function resolver(): DelegatingResolver
    {
        $r = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            InfixExpression::class => InfixResolver::class,
            SymbolSource::class => SymbolResolver::class,
        ]);
        $r->instance(OperatorOverloader::class, new DefaultOverloader());

        return $r;
    }

    #[Test]
    public function well_typed_expression_validates_and_runs(): void
    {
        // radius * 2
        $e = new Expression(
            source: new InfixExpression(new SymbolSource('radius'), '*', new StaticSource(2)),
            resolver: $this->resolver(),
            parameters: ['radius' => new NumberType()],
        );

        $this->assertInstanceOf(NumberType::class, $e->validate()->unwrap());
        $this->assertSame(10, $e(['radius' => 5])->unwrap()->unwrap());
    }

    #[Test]
    public function ill_typed_expression_validates_with_error_before_any_call(): void
    {
        // name * 2 where name is declared string
        $e = new Expression(
            source: new InfixExpression(new SymbolSource('name'), '*', new StaticSource(2)),
            resolver: $this->resolver(),
            parameters: ['name' => new StringType()],
        );

        $result = $e->validate();

        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(TypeCheckException::class, $result->unwrapErr());
    }

    #[Test]
    public function definition_types_propagate(): void
    {
        $e = new Expression(
            source: new InfixExpression(new SymbolSource('PI'), '*', new StaticSource(2)),
            resolver: $this->resolver(),
            definitions: new Definitions(['PI' => new StaticSource(3.14)]),
        );

        $this->assertInstanceOf(NumberType::class, $e->validate()->unwrap());
    }

    #[Test]
    public function missing_parameter_fails_validation(): void
    {
        $e = new Expression(
            source: new InfixExpression(new SymbolSource('unknown'), '*', new StaticSource(2)),
            resolver: $this->resolver(),
        );

        $this->assertTrue($e->validate()->isErr());
    }

    #[Test]
    public function namespaced_parameter_schema_works(): void
    {
        $e = new Expression(
            source: new InfixExpression(
                new SymbolSource('claims', 'quote'),
                '>',
                new StaticSource(2),
            ),
            resolver: $this->resolver(),
            parameters: ['quote' => ['claims' => new NumberType()]],
        );

        $this->assertTrue($e->validate()->isOk());
    }
}
