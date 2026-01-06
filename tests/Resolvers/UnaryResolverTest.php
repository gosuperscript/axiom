<?php

declare(strict_types=1);

namespace Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Resolvers\DelegatingResolver;
use Superscript\Schema\Sources\StaticSource;
use Superscript\Schema\Sources\UnaryExpression;

#[CoversClass(UnaryExpression::class)]
#[UsesClass(DelegatingResolver::class)]
#[UsesClass(StaticSource::class)]
class UnaryResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_a_logical_not_expression(): void
    {
        $source = new UnaryExpression(
            operator: '!',
            operand: new StaticSource(true),
        );

        $resolver = $source->resolver();
        $this->assertEquals(false, $resolver(new DelegatingResolver())->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_resolve_a_unary_minus_expression(): void
    {
        $source = new UnaryExpression(
            operator: '-',
            operand: new StaticSource(42),
        );

        $resolver = $source->resolver();
        $this->assertEquals(-42, $resolver(new DelegatingResolver())->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_err_for_unsupported_operators(): void
    {
        $source = new UnaryExpression(
            operator: '+',
            operand: new StaticSource(42),
        );

        $resolver = $source->resolver();
        $this->assertTrue($resolver(new DelegatingResolver())->isErr());
    }
}
