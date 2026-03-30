<?php

declare(strict_types=1);

namespace Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\UnaryResolver;
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
            operand: new StaticSource(true),
        );

        $this->assertEquals(false, $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_resolve_a_unary_minus_expression(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());
        $source = new UnaryExpression(
            operator: '-',
            operand: new StaticSource(42),
        );

        $this->assertEquals(-42, $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_resolve_not_true_to_false(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());
        $source = new UnaryExpression(
            operator: 'not',
            operand: new StaticSource(true),
        );

        $this->assertFalse($resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_resolve_not_false_to_true(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());
        $source = new UnaryExpression(
            operator: 'not',
            operand: new StaticSource(false),
        );

        $this->assertTrue($resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_resolve_not_on_truthy_value(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());
        $source = new UnaryExpression(
            operator: 'not',
            operand: new StaticSource(1),
        );

        $this->assertFalse($resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_resolve_not_on_falsy_value(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());
        $source = new UnaryExpression(
            operator: 'not',
            operand: new StaticSource(0),
        );

        $this->assertTrue($resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function not_and_bang_produce_identical_results(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());

        foreach ([true, false, 1, 0, 'hello', ''] as $input) {
            $bang = new UnaryExpression(operator: '!', operand: new StaticSource($input));
            $not = new UnaryExpression(operator: 'not', operand: new StaticSource($input));

            $this->assertEquals(
                $resolver->resolve($bang)->unwrap()->unwrap(),
                $resolver->resolve($not)->unwrap()->unwrap(),
                sprintf('not and ! should produce identical results for input: %s', var_export($input, true)),
            );
        }
    }

    #[Test]
    public function it_returns_err_for_unsupported_operators(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());
        $source = new UnaryExpression(
            operator: '+',
            operand: new StaticSource(42),
        );

        $this->assertTrue($resolver->resolve($source)->isErr());
    }
}
