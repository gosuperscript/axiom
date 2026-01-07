<?php

namespace Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Operators\BinaryOverloader;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\OverloaderManager;
use Superscript\Axiom\Resolvers\InfixResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\UnaryResolver;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\UnaryExpression;

#[CoversClass(UnaryExpression::class)]
#[CoversClass(UnaryResolver::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(StaticSource::class)]
class UnaryResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_a_logical_not_expression(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());
        $source = new UnaryExpression(
            operator: '!',
            operand: new StaticSource(true)
        );

        $this->assertEquals(false, $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_resolve_a_unary_minus_expression(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());
        $source = new UnaryExpression(
            operator: '-',
            operand: new StaticSource(42)
        );

        $this->assertEquals(-42, $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_err_for_unsupported_operators(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());
        $source = new UnaryExpression(
            operator: '+',
            operand: new StaticSource(42)
        );

        $this->assertTrue($resolver->resolve($source)->isErr());
    }
}
