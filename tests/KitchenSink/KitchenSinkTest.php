<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\KitchenSink;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Bindings;
use Superscript\Axiom\Context;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Patterns\ExpressionMatcher;
use Superscript\Axiom\Patterns\LiteralMatcher;
use Superscript\Axiom\Patterns\WildcardMatcher;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\InfixResolver;
use Superscript\Axiom\Resolvers\MatchResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\SymbolResolver;
use Superscript\Axiom\Resolvers\ValueResolver;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\TypeDefinition;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Types\NumberType;

#[CoversNothing]
class KitchenSinkTest extends TestCase
{
    private function fullResolver(): DelegatingResolver
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            InfixExpression::class => InfixResolver::class,
            TypeDefinition::class => ValueResolver::class,
            SymbolSource::class => SymbolResolver::class,
            MatchExpression::class => MatchResolver::class,
        ]);

        $resolver->instance(OperatorOverloader::class, new DefaultOverloader());
        $resolver->instance(MatchResolver::class, new MatchResolver($resolver, [
            new WildcardMatcher(),
            new LiteralMatcher(),
            new ExpressionMatcher($resolver),
        ]));

        return $resolver;
    }

    #[Test]
    public function an_expression_is_a_callable_you_invoke_with_inputs(): void
    {
        // area = PI * radius * radius
        $source = new InfixExpression(
            left: new SymbolSource('PI'),
            operator: '*',
            right: new InfixExpression(
                left: new SymbolSource('radius'),
                operator: '*',
                right: new SymbolSource('radius'),
            ),
        );

        $area = new Expression(
            source: $source,
            resolver: $this->fullResolver(),
            definitions: new Definitions(['PI' => new StaticSource(3.14159)]),
        );

        $this->assertEqualsWithDelta(78.54, $area(['radius' => 5]), 0.01);
        $this->assertEqualsWithDelta(314.16, $area(['radius' => 10]), 0.01);
    }

    #[Test]
    public function expression_reports_its_free_variable_parameters(): void
    {
        $source = new InfixExpression(
            left: new SymbolSource('PI'),
            operator: '*',
            right: new InfixExpression(
                left: new SymbolSource('radius'),
                operator: '*',
                right: new SymbolSource('radius'),
            ),
        );

        $area = new Expression(
            source: $source,
            resolver: $this->fullResolver(),
            definitions: new Definitions(['PI' => new StaticSource(3.14159)]),
        );

        // PI is covered by definitions; only radius remains a parameter.
        $this->assertSame(['radius'], $area->parameters());
    }

    #[Test]
    public function transforming_a_value(): void
    {
        $source = new TypeDefinition(
            type: new NumberType(),
            source: new StaticSource('5'),
        );

        $expression = new Expression($source, $this->fullResolver());

        $this->assertSame(5, $expression());
    }

    #[Test]
    public function if_then_else(): void
    {
        // if claims > 2 then 100 * 0.25 else 0
        $source = new MatchExpression(
            subject: new StaticSource(true),
            arms: [
                new MatchArm(
                    new ExpressionPattern(
                        new InfixExpression(
                            left: new SymbolSource('claims', 'quote'),
                            operator: '>',
                            right: new StaticSource(2),
                        ),
                    ),
                    new InfixExpression(
                        left: new StaticSource(100),
                        operator: '*',
                        right: new StaticSource(0.25),
                    ),
                ),
                new MatchArm(new WildcardPattern(), new StaticSource(0)),
            ],
        );

        $expression = new Expression($source, $this->fullResolver());

        $this->assertEquals(25.0, $expression(['quote' => ['claims' => 3]]));
        $this->assertEquals(0, $expression(['quote' => ['claims' => 1]]));
    }

    #[Test]
    public function match_dispatch_table(): void
    {
        // match tier { "micro" => 1.3, "small" => 1.1, _ => 1.0 }
        $source = new MatchExpression(
            subject: new SymbolSource('tier'),
            arms: [
                new MatchArm(new LiteralPattern('micro'), new StaticSource(1.3)),
                new MatchArm(new LiteralPattern('small'), new StaticSource(1.1)),
                new MatchArm(new WildcardPattern(), new StaticSource(1.0)),
            ],
        );

        $multiplier = new Expression($source, $this->fullResolver());

        $this->assertEquals(1.3, $multiplier(['tier' => 'micro']));
        $this->assertEquals(1.1, $multiplier(['tier' => 'small']));
        $this->assertEquals(1.0, $multiplier(['tier' => 'enormous']));
    }

    #[Test]
    public function cond_style_rating(): void
    {
        // match {
        //   quote.claims > 3 => 0.5,
        //   quote.turnover > 500000 => 0.35,
        //   _ => 0.1,
        // }
        $source = new MatchExpression(
            subject: new StaticSource(true),
            arms: [
                new MatchArm(
                    new ExpressionPattern(
                        new InfixExpression(
                            left: new SymbolSource('claims', 'quote'),
                            operator: '>',
                            right: new StaticSource(3),
                        ),
                    ),
                    new StaticSource(0.5),
                ),
                new MatchArm(
                    new ExpressionPattern(
                        new InfixExpression(
                            left: new SymbolSource('turnover', 'quote'),
                            operator: '>',
                            right: new StaticSource(500000),
                        ),
                    ),
                    new StaticSource(0.35),
                ),
                new MatchArm(new WildcardPattern(), new StaticSource(0.1)),
            ],
        );

        $rate = new Expression($source, $this->fullResolver());

        $this->assertEquals(0.5, $rate(['quote' => ['claims' => 5, 'turnover' => 100]]));
        $this->assertEquals(0.35, $rate(['quote' => ['claims' => 1, 'turnover' => 600000]]));
        $this->assertEquals(0.1, $rate(['quote' => ['claims' => 0, 'turnover' => 100]]));
    }

    #[Test]
    public function something_complex_with_bindings(): void
    {
        // 1 + A * (number)3, where A is a binding
        $source = new InfixExpression(
            left: new StaticSource(1),
            operator: '+',
            right: new InfixExpression(
                left: new SymbolSource('A'),
                operator: '*',
                right: new TypeDefinition(
                    type: new NumberType(),
                    source: new StaticSource('3'),
                ),
            ),
        );

        $expression = new Expression($source, $this->fullResolver());

        $this->assertEquals(7, $expression(['A' => 2]));
        $this->assertEquals(16, $expression(['A' => 5]));
    }

    #[Test]
    public function resolver_can_be_used_directly_with_an_explicit_context(): void
    {
        $resolver = $this->fullResolver();

        $source = new InfixExpression(
            left: new SymbolSource('a'),
            operator: '+',
            right: new SymbolSource('b'),
        );

        $context = new Context(bindings: new Bindings(['a' => 2, 'b' => 3]));

        $this->assertEquals(5, $resolver->resolve($source, $context)->unwrap()->unwrap());
    }
}
