<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Operators\BinaryOverloader;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\NullOverloader;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\InfixResolver;
use Superscript\Axiom\Resolvers\MatchResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\SymbolResolver;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MatchPattern;
use Superscript\Axiom\Sources\RangePattern;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\SymbolRegistry;
use Superscript\Axiom\Tests\Resolvers\Fixtures\SpyInspector;

#[CoversClass(MatchResolver::class)]
#[CoversClass(MatchExpression::class)]
#[CoversClass(MatchArm::class)]
#[CoversClass(LiteralPattern::class)]
#[CoversClass(WildcardPattern::class)]
#[CoversClass(RangePattern::class)]
#[CoversClass(ExpressionPattern::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(DelegatingResolver::class)]
#[UsesClass(InfixResolver::class)]
#[UsesClass(InfixExpression::class)]
#[UsesClass(DefaultOverloader::class)]
#[UsesClass(BinaryOverloader::class)]
#[UsesClass(NullOverloader::class)]
#[UsesClass(SymbolResolver::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(SymbolRegistry::class)]
class MatchResolverTest extends TestCase
{
    #[Test]
    public function it_matches_correct_literal_from_multiple_arms(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource('small'),
            arms: [
                new MatchArm(new LiteralPattern('micro'), new StaticSource(1.3)),
                new MatchArm(new LiteralPattern('small'), new StaticSource(1.1)),
                new MatchArm(new LiteralPattern('medium'), new StaticSource(1.0)),
            ],
        );

        $this->assertEquals(1.1, $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_falls_through_to_wildcard_when_no_literal_matches(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource('large'),
            arms: [
                new MatchArm(new LiteralPattern('micro'), new StaticSource(1.3)),
                new MatchArm(new LiteralPattern('small'), new StaticSource(1.1)),
                new MatchArm(new WildcardPattern(), new StaticSource(1.0)),
            ],
        );

        $this->assertEquals(1.0, $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_returns_none_when_no_arm_matches_and_no_wildcard(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource('large'),
            arms: [
                new MatchArm(new LiteralPattern('micro'), new StaticSource(1.3)),
                new MatchArm(new LiteralPattern('small'), new StaticSource(1.1)),
            ],
        );

        $this->assertTrue($resolver->resolve($source)->unwrap()->isNone());
    }

    #[Test]
    public function first_matching_arm_wins_when_duplicates_exist(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource('micro'),
            arms: [
                new MatchArm(new LiteralPattern('micro'), new StaticSource(1.3)),
                new MatchArm(new LiteralPattern('micro'), new StaticSource(9.9)),
            ],
        );

        $this->assertEquals(1.3, $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_matches_boolean_literal_true(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource(true),
            arms: [
                new MatchArm(new LiteralPattern(false), new StaticSource('no')),
                new MatchArm(new LiteralPattern(true), new StaticSource('yes')),
            ],
        );

        $this->assertEquals('yes', $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_matches_boolean_literal_false(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource(false),
            arms: [
                new MatchArm(new LiteralPattern(true), new StaticSource('yes')),
                new MatchArm(new LiteralPattern(false), new StaticSource('no')),
            ],
        );

        $this->assertEquals('no', $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function it_matches_value_within_range(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource(50000),
            arms: [
                new MatchArm(new RangePattern(0, 100000), new StaticSource('micro')),
                new MatchArm(new RangePattern(100001, 500000), new StaticSource('small')),
            ],
        );

        $this->assertEquals('micro', $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function range_is_inclusive_on_lower_bound(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource(0),
            arms: [
                new MatchArm(new RangePattern(0, 100), new StaticSource('matched')),
                new MatchArm(new WildcardPattern(), new StaticSource('not matched')),
            ],
        );

        $this->assertEquals('matched', $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function range_is_inclusive_on_upper_bound(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource(100),
            arms: [
                new MatchArm(new RangePattern(0, 100), new StaticSource('matched')),
                new MatchArm(new WildcardPattern(), new StaticSource('not matched')),
            ],
        );

        $this->assertEquals('matched', $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function range_falls_through_when_out_of_range(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource(200),
            arms: [
                new MatchArm(new RangePattern(0, 100), new StaticSource('in range')),
                new MatchArm(new WildcardPattern(), new StaticSource('out of range')),
            ],
        );

        $this->assertEquals('out of range', $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function range_rejects_non_numeric_subject(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource('not a number'),
            arms: [
                new MatchArm(new RangePattern(0, 100), new StaticSource('in range')),
                new MatchArm(new WildcardPattern(), new StaticSource('fallback')),
            ],
        );

        $this->assertEquals('fallback', $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function range_matches_float_values(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource(3.14),
            arms: [
                new MatchArm(new RangePattern(0.0, 5.0), new StaticSource('matched')),
                new MatchArm(new WildcardPattern(), new StaticSource('not matched')),
            ],
        );

        $this->assertEquals('matched', $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function expression_pattern_matches_when_resolves_to_subject(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource(true),
            arms: [
                new MatchArm(
                    new ExpressionPattern(new StaticSource(true)),
                    new StaticSource('matched'),
                ),
                new MatchArm(new WildcardPattern(), new StaticSource('fallback')),
            ],
        );

        $this->assertEquals('matched', $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function expression_pattern_skips_when_resolves_to_false(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource(true),
            arms: [
                new MatchArm(
                    new ExpressionPattern(new StaticSource(false)),
                    new StaticSource('should not match'),
                ),
                new MatchArm(new WildcardPattern(), new StaticSource('fallback')),
            ],
        );

        $this->assertEquals('fallback', $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function chained_else_if_with_multiple_expression_arms(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource(true),
            arms: [
                new MatchArm(new ExpressionPattern(new StaticSource(false)), new StaticSource('A')),
                new MatchArm(new ExpressionPattern(new StaticSource(false)), new StaticSource('B')),
                new MatchArm(new ExpressionPattern(new StaticSource(true)), new StaticSource('C')),
                new MatchArm(new WildcardPattern(), new StaticSource('D')),
            ],
        );

        $this->assertEquals('C', $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function subjectless_cond_with_first_truthy_arm_winning(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource(true),
            arms: [
                new MatchArm(new ExpressionPattern(new StaticSource(true)), new StaticSource(0.5)),
                new MatchArm(new ExpressionPattern(new StaticSource(true)), new StaticSource(0.35)),
                new MatchArm(new WildcardPattern(), new StaticSource(0.1)),
            ],
        );

        $this->assertEquals(0.5, $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function infix_expression_as_pattern_source(): void
    {
        $delegating = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            InfixExpression::class => InfixResolver::class,
            MatchExpression::class => MatchResolver::class,
        ]);
        $delegating->instance(OperatorOverloader::class, new DefaultOverloader());

        $source = new MatchExpression(
            subject: new StaticSource(true),
            arms: [
                new MatchArm(
                    new ExpressionPattern(
                        new InfixExpression(
                            left: new StaticSource(3),
                            operator: '>',
                            right: new StaticSource(2),
                        ),
                    ),
                    new StaticSource('condition met'),
                ),
                new MatchArm(new WildcardPattern(), new StaticSource('fallback')),
            ],
        );

        $this->assertEquals('condition met', $delegating->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function infix_expression_as_arm_result(): void
    {
        $delegating = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            InfixExpression::class => InfixResolver::class,
            MatchExpression::class => MatchResolver::class,
        ]);
        $delegating->instance(OperatorOverloader::class, new DefaultOverloader());

        $source = new MatchExpression(
            subject: new StaticSource(true),
            arms: [
                new MatchArm(
                    new ExpressionPattern(new StaticSource(true)),
                    new InfixExpression(
                        left: new StaticSource(100),
                        operator: '*',
                        right: new StaticSource(0.25),
                    ),
                ),
            ],
        );

        $this->assertEquals(25.0, $delegating->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function symbol_source_as_subject(): void
    {
        $delegating = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            SymbolSource::class => SymbolResolver::class,
            MatchExpression::class => MatchResolver::class,
        ]);
        $delegating->instance(SymbolRegistry::class, new SymbolRegistry([
            'tier' => new StaticSource('micro'),
        ]));

        $source = new MatchExpression(
            subject: new SymbolSource('tier'),
            arms: [
                new MatchArm(new LiteralPattern('micro'), new StaticSource(1.3)),
                new MatchArm(new LiteralPattern('small'), new StaticSource(1.1)),
                new MatchArm(new WildcardPattern(), new StaticSource(1.0)),
            ],
        );

        $this->assertEquals(1.3, $delegating->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function symbol_source_in_expression_patterns(): void
    {
        $delegating = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            InfixExpression::class => InfixResolver::class,
            SymbolSource::class => SymbolResolver::class,
            MatchExpression::class => MatchResolver::class,
        ]);
        $delegating->instance(OperatorOverloader::class, new DefaultOverloader());
        $delegating->instance(SymbolRegistry::class, new SymbolRegistry([
            'claims' => new StaticSource(4),
        ]));

        $source = new MatchExpression(
            subject: new StaticSource(true),
            arms: [
                new MatchArm(
                    new ExpressionPattern(
                        new InfixExpression(
                            left: new SymbolSource('claims'),
                            operator: '>',
                            right: new StaticSource(3),
                        ),
                    ),
                    new StaticSource(0.5),
                ),
                new MatchArm(new WildcardPattern(), new StaticSource(0.1)),
            ],
        );

        $this->assertEquals(0.5, $delegating->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function full_cond_style_match_with_symbols_and_infix(): void
    {
        $delegating = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            InfixExpression::class => InfixResolver::class,
            SymbolSource::class => SymbolResolver::class,
            MatchExpression::class => MatchResolver::class,
        ]);
        $delegating->instance(OperatorOverloader::class, new DefaultOverloader());
        $delegating->instance(SymbolRegistry::class, new SymbolRegistry([
            'claims' => new StaticSource(1),
            'turnover' => new StaticSource(600000),
        ]));

        $source = new MatchExpression(
            subject: new StaticSource(true),
            arms: [
                new MatchArm(
                    new ExpressionPattern(
                        new InfixExpression(
                            left: new SymbolSource('claims'),
                            operator: '>',
                            right: new StaticSource(3),
                        ),
                    ),
                    new StaticSource(0.5),
                ),
                new MatchArm(
                    new ExpressionPattern(
                        new InfixExpression(
                            left: new SymbolSource('turnover'),
                            operator: '>',
                            right: new StaticSource(500000),
                        ),
                    ),
                    new StaticSource(0.35),
                ),
                new MatchArm(new WildcardPattern(), new StaticSource(0.1)),
            ],
        );

        $this->assertEquals(0.35, $delegating->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function nested_match_expression(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $innerMatch = new MatchExpression(
            subject: new StaticSource('a'),
            arms: [
                new MatchArm(new LiteralPattern('a'), new StaticSource(10)),
                new MatchArm(new WildcardPattern(), new StaticSource(0)),
            ],
        );

        $delegating = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            MatchExpression::class => MatchResolver::class,
        ]);

        $source = new MatchExpression(
            subject: new StaticSource('x'),
            arms: [
                new MatchArm(new LiteralPattern('x'), $innerMatch),
                new MatchArm(new WildcardPattern(), new StaticSource(-1)),
            ],
        );

        $this->assertEquals(10, $delegating->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function mixed_pattern_types_in_single_match(): void
    {
        $delegating = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            MatchExpression::class => MatchResolver::class,
        ]);

        $source = new MatchExpression(
            subject: new StaticSource(50),
            arms: [
                new MatchArm(new LiteralPattern(99), new StaticSource('literal')),
                new MatchArm(new ExpressionPattern(new StaticSource(50)), new StaticSource('expression')),
                new MatchArm(new RangePattern(0, 100), new StaticSource('range')),
                new MatchArm(new WildcardPattern(), new StaticSource('wildcard')),
            ],
        );

        $this->assertEquals('expression', $delegating->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function empty_arms_list_returns_none(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource('anything'),
            arms: [],
        );

        $this->assertTrue($resolver->resolve($source)->unwrap()->isNone());
    }

    #[Test]
    public function null_subject(): void
    {
        $resolver = new MatchResolver(new StaticResolver());

        $source = new MatchExpression(
            subject: new StaticSource(null),
            arms: [
                new MatchArm(new LiteralPattern(null), new StaticSource('matched null')),
                new MatchArm(new WildcardPattern(), new StaticSource('wildcard')),
            ],
        );

        $this->assertEquals('matched null', $resolver->resolve($source)->unwrap()->unwrap());
    }

    #[Test]
    public function inspector_receives_annotations(): void
    {
        $inspector = new SpyInspector();
        $resolver = new MatchResolver(new StaticResolver(), $inspector);

        $source = new MatchExpression(
            subject: new StaticSource('micro'),
            arms: [
                new MatchArm(new LiteralPattern('micro'), new StaticSource(1.3)),
                new MatchArm(new WildcardPattern(), new StaticSource(1.0)),
            ],
        );

        $resolver->resolve($source);

        $this->assertEquals('match', $inspector->annotations['label']);
        $this->assertEquals('micro', $inspector->annotations['subject']);
        $this->assertEquals(0, $inspector->annotations['matched_arm']);
        $this->assertEquals(1.3, $inspector->annotations['result']);
    }

    #[Test]
    public function inspector_annotations_for_second_arm_match(): void
    {
        $inspector = new SpyInspector();
        $resolver = new MatchResolver(new StaticResolver(), $inspector);

        $source = new MatchExpression(
            subject: new StaticSource('small'),
            arms: [
                new MatchArm(new LiteralPattern('micro'), new StaticSource(1.3)),
                new MatchArm(new LiteralPattern('small'), new StaticSource(1.1)),
            ],
        );

        $resolver->resolve($source);

        $this->assertEquals(1, $inspector->annotations['matched_arm']);
        $this->assertEquals(1.1, $inspector->annotations['result']);
    }
}
