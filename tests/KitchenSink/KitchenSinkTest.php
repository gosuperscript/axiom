<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\KitchenSink;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
use Superscript\Axiom\SymbolRegistry;
use Superscript\Axiom\Types\NumberType;

#[CoversNothing]
class KitchenSinkTest extends TestCase
{
    #[Test]
    public function something_complex(): void
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            InfixExpression::class => InfixResolver::class,
            TypeDefinition::class => ValueResolver::class,
            SymbolSource::class => SymbolResolver::class,
        ]);

        $resolver->instance(OperatorOverloader::class, new DefaultOverloader());
        $resolver->instance(SymbolRegistry::class, new SymbolRegistry([
            'A' => new StaticSource(2),
        ]));

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

        $result = $resolver->resolve($source);
        $this->assertEquals(7, $result->unwrap()->unwrap());
    }

    #[Test]
    public function transforming_a_value(): void
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            TypeDefinition::class => ValueResolver::class,
        ]);

        $source = new TypeDefinition(
            type: new NumberType(),
            source: new StaticSource('5'),
        );

        $result = $resolver->resolve($source);
        $this->assertEquals(5, $result->unwrap()->unwrap());
    }

    #[Test]
    public function if_then_else(): void
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            InfixExpression::class => InfixResolver::class,
            SymbolSource::class => SymbolResolver::class,
            MatchExpression::class => MatchResolver::class,
        ]);

        $resolver->instance(OperatorOverloader::class, new DefaultOverloader());
        $resolver->instance(SymbolRegistry::class, new SymbolRegistry([
            'quote' => [
                'claims' => new StaticSource(3),
            ],
        ]));

        $matchers = [
            new WildcardMatcher(),
            new LiteralMatcher(),
            new ExpressionMatcher($resolver),
        ];
        $resolver->instance(MatchResolver::class, new MatchResolver($resolver, $matchers));

        // if quote.claims > 2 then base * 0.25 else 0
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

        $result = $resolver->resolve($source);
        $this->assertEquals(25.0, $result->unwrap()->unwrap());
    }

    #[Test]
    public function match_dispatch_table(): void
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            SymbolSource::class => SymbolResolver::class,
            MatchExpression::class => MatchResolver::class,
        ]);

        $resolver->instance(SymbolRegistry::class, new SymbolRegistry([
            'tier' => new StaticSource('small'),
        ]));

        $matchers = [
            new WildcardMatcher(),
            new LiteralMatcher(),
            new ExpressionMatcher($resolver),
        ];
        $resolver->instance(MatchResolver::class, new MatchResolver($resolver, $matchers));

        // match tier { "micro" => 1.3, "small" => 1.1, _ => 1.0 }
        $source = new MatchExpression(
            subject: new SymbolSource('tier'),
            arms: [
                new MatchArm(new LiteralPattern('micro'), new StaticSource(1.3)),
                new MatchArm(new LiteralPattern('small'), new StaticSource(1.1)),
                new MatchArm(new WildcardPattern(), new StaticSource(1.0)),
            ],
        );

        $result = $resolver->resolve($source);
        $this->assertEquals(1.1, $result->unwrap()->unwrap());
    }

    #[Test]
    public function cond_style_rating(): void
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            InfixExpression::class => InfixResolver::class,
            SymbolSource::class => SymbolResolver::class,
            MatchExpression::class => MatchResolver::class,
        ]);

        $resolver->instance(OperatorOverloader::class, new DefaultOverloader());
        $resolver->instance(SymbolRegistry::class, new SymbolRegistry([
            'quote' => [
                'claims' => new StaticSource(1),
                'turnover' => new StaticSource(600000),
            ],
        ]));

        $matchers = [
            new WildcardMatcher(),
            new LiteralMatcher(),
            new ExpressionMatcher($resolver),
        ];
        $resolver->instance(MatchResolver::class, new MatchResolver($resolver, $matchers));

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

        $result = $resolver->resolve($source);
        $this->assertEquals(0.35, $result->unwrap()->unwrap());
    }
}
