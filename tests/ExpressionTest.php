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
use Superscript\Axiom\Expression;
use Superscript\Axiom\Operators\BinaryOverloader;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\NullOverloader;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\InfixResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\SymbolResolver;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Tests\Resolvers\Fixtures\SpyInspector;
use Superscript\Axiom\UnboundSymbols;
use Superscript\Monads\Result\Result;

#[CoversClass(Expression::class)]
#[UsesClass(UnboundSymbols::class)]
#[UsesClass(Context::class)]
#[UsesClass(Bindings::class)]
#[UsesClass(Definitions::class)]
#[UsesClass(DelegatingResolver::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(SymbolResolver::class)]
#[UsesClass(InfixResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(InfixExpression::class)]
#[UsesClass(DefaultOverloader::class)]
#[UsesClass(BinaryOverloader::class)]
#[UsesClass(NullOverloader::class)]
final class ExpressionTest extends TestCase
{
    private function fullResolver(): DelegatingResolver
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            SymbolSource::class => SymbolResolver::class,
            InfixExpression::class => InfixResolver::class,
        ]);

        $resolver->instance(OperatorOverloader::class, new DefaultOverloader());

        return $resolver;
    }

    #[Test]
    public function invoke_returns_a_result(): void
    {
        $expression = new Expression(
            source: new InfixExpression(
                left: new SymbolSource('a'),
                operator: '+',
                right: new SymbolSource('b'),
            ),
            resolver: $this->fullResolver(),
        );

        $result = $expression(['a' => 2, 'b' => 3]);

        $this->assertInstanceOf(Result::class, $result);
        $this->assertSame(5, $result->unwrap()->unwrap());
    }

    #[Test]
    public function invoke_returns_ok_none_when_a_symbol_is_unbound(): void
    {
        $expression = new Expression(
            source: new SymbolSource('unknown'),
            resolver: $this->fullResolver(),
        );

        $result = $expression();

        $this->assertTrue($result->unwrap()->isNone());
    }

    #[Test]
    public function call_returns_the_same_result_as_invoke(): void
    {
        $expression = new Expression(
            source: new StaticSource(42),
            resolver: $this->fullResolver(),
        );

        $invoked = $expression();
        $called = $expression->call();

        $this->assertInstanceOf(Result::class, $called);
        $this->assertSame($invoked->unwrap()->unwrap(), $called->unwrap()->unwrap());
    }

    #[Test]
    public function parameters_lists_free_variables_not_covered_by_definitions(): void
    {
        $expression = new Expression(
            source: new InfixExpression(
                left: new SymbolSource('PI'),
                operator: '*',
                right: new SymbolSource('radius'),
            ),
            resolver: $this->fullResolver(),
            definitions: new Definitions(['PI' => new StaticSource(3.14)]),
        );

        $this->assertSame(['radius'], $expression->parameters());
    }

    #[Test]
    public function parameters_lists_every_uncovered_free_variable(): void
    {
        $expression = new Expression(
            source: new InfixExpression(
                left: new SymbolSource('height'),
                operator: '*',
                right: new SymbolSource('width'),
            ),
            resolver: $this->fullResolver(),
        );

        $this->assertSame(['height', 'width'], $expression->parameters());
    }

    #[Test]
    public function parameters_renders_namespaced_symbols_with_dot(): void
    {
        $expression = new Expression(
            source: new InfixExpression(
                left: new SymbolSource('claims', 'quote'),
                operator: '>',
                right: new StaticSource(2),
            ),
            resolver: $this->fullResolver(),
        );

        $this->assertSame(['quote.claims'], $expression->parameters());
    }

    #[Test]
    public function bindings_override_definitions(): void
    {
        $expression = new Expression(
            source: new SymbolSource('x'),
            resolver: $this->fullResolver(),
            definitions: new Definitions(['x' => new StaticSource(1)]),
        );

        $this->assertSame(1, $expression()->unwrap()->unwrap());
        $this->assertSame(99, $expression(['x' => 99])->unwrap()->unwrap());
    }

    #[Test]
    public function with_definitions_swaps_the_definitions(): void
    {
        $expression = new Expression(
            source: new SymbolSource('x'),
            resolver: $this->fullResolver(),
        );

        $this->assertTrue($expression()->unwrap()->isNone());

        $swapped = $expression->withDefinitions(new Definitions(['x' => new StaticSource(7)]));

        $this->assertSame(7, $swapped()->unwrap()->unwrap());
        $this->assertTrue($expression()->unwrap()->isNone(), 'original is unchanged');
    }

    #[Test]
    public function with_inspector_attaches_an_inspector_to_the_context(): void
    {
        $expression = new Expression(
            source: new StaticSource(42),
            resolver: $this->fullResolver(),
        );

        $inspector = new SpyInspector();
        $inspected = $expression->withInspector($inspector);

        $inspected();

        $this->assertSame('static(int)', $inspector->annotations['label']);
        $this->assertNull($expression->inspector, 'original is unchanged');
    }
}
