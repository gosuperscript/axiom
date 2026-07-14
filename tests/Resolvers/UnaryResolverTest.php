<?php

declare(strict_types=1);

namespace Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Bindings;
use Superscript\Axiom\Context;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\UnaryResolver;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\UnaryExpression;

#[CoversClass(UnaryExpression::class)]
#[CoversClass(UnaryResolver::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(Context::class)]
#[UsesClass(Bindings::class)]
#[UsesClass(Definitions::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOverloaderManager::class)]
#[UsesClass(\Superscript\Axiom\Operators\NotOverloader::class)]
#[UsesClass(\Superscript\Axiom\Operators\NegateOverloader::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\Superscript\Axiom\Dialect::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\Superscript\Axiom\Extension::class)]
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

        $this->assertEquals(false, $resolver->resolve($source, new Context())->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_resolve_a_unary_minus_expression(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());
        $source = new UnaryExpression(
            operator: '-',
            operand: new StaticSource(42),
        );

        $this->assertEquals(-42, $resolver->resolve($source, new Context())->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_resolve_not_true_to_false(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());
        $source = new UnaryExpression(
            operator: 'not',
            operand: new StaticSource(true),
        );

        $this->assertFalse($resolver->resolve($source, new Context())->unwrap()->unwrap());
    }

    #[Test]
    public function it_can_resolve_not_false_to_true(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());
        $source = new UnaryExpression(
            operator: 'not',
            operand: new StaticSource(false),
        );

        $this->assertTrue($resolver->resolve($source, new Context())->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_err_when_negating_a_non_boolean(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());

        foreach ([1, 0, 'hello'] as $input) {
            foreach (['!', 'not'] as $operator) {
                $source = new UnaryExpression(operator: $operator, operand: new StaticSource($input));

                $this->assertTrue(
                    $resolver->resolve($source, new Context())->isErr(),
                    sprintf('%s should refuse non-boolean input: %s', $operator, var_export($input, true)),
                );
            }
        }
    }

    #[Test]
    public function not_and_bang_produce_identical_results(): void
    {
        $resolver = new UnaryResolver(new StaticResolver());

        foreach ([true, false] as $input) {
            $bang = new UnaryExpression(operator: '!', operand: new StaticSource($input));
            $not = new UnaryExpression(operator: 'not', operand: new StaticSource($input));

            $this->assertEquals(
                $resolver->resolve($bang, new Context())->unwrap()->unwrap(),
                $resolver->resolve($not, new Context())->unwrap()->unwrap(),
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

        $this->assertTrue($resolver->resolve($source, new Context())->isErr());
    }

    #[Test]
    public function a_dialect_can_inject_its_own_unary_overloader(): void
    {
        $doubling = new class implements \Superscript\Axiom\Operators\UnaryOverloader {
            public function supportsOverloading(mixed $operand, string $operator): bool
            {
                return is_int($operand) && $operator === '!';
            }

            public function evaluate(mixed $operand, string $operator): \Superscript\Monads\Result\Result
            {
                return \Superscript\Monads\Result\Ok($operand * 2);
            }

            public function handles(string $operator): bool
            {
                return $operator === '!';
            }

            public function typeOf(string $operator, \Superscript\Axiom\Types\Type $operand): \Superscript\Monads\Result\Result
            {
                return \Superscript\Monads\Result\Ok(new \Superscript\Axiom\Types\NumberType());
            }
        };

        $extension = new class ($doubling) extends \Superscript\Axiom\Extension {
            public function __construct(private readonly \Superscript\Axiom\Operators\UnaryOverloader $rule) {}

            public function unaryOperators(): array
            {
                return [$this->rule];
            }
        };

        // The rules travel with the call: the dialect rides the Context,
        // and the resolver holds no operator state to configure.
        $resolver = new UnaryResolver(new StaticResolver());
        $source = new UnaryExpression(operator: '!', operand: new StaticSource(21));
        $context = new Context(dialect: \Superscript\Axiom\Dialect::core()->with($extension));

        $this->assertSame(42, $resolver->resolve($source, $context)->unwrap()->unwrap());
    }
}
