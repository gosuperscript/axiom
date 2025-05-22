<?php

namespace Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Abacus\Operators\BinaryOverloader;
use Superscript\Abacus\Operators\DefaultOverloader;
use Superscript\Abacus\Operators\OverloaderManager;
use Superscript\Abacus\Resolvers\InfixResolver;
use Superscript\Abacus\Resolvers\StaticResolver;
use Superscript\Abacus\Resolvers\UnaryResolver;
use Superscript\Abacus\Sources\InfixExpression;
use Superscript\Abacus\Sources\StaticSource;
use Superscript\Abacus\Sources\UnaryExpression;

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

        $this->assertTrue($resolver::supports($source));
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

        $this->assertTrue($resolver::supports($source));
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

        $this->assertTrue($resolver::supports($source));
        $this->assertTrue($resolver->resolve($source)->isErr());
    }

    #[Test]
    public function it_does_not_support_other_sources(): void
    {
        $source = new StaticSource(true);

        $this->assertFalse(UnaryResolver::supports($source));
    }
}
