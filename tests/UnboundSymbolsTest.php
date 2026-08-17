<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\ReferencePath;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\UnboundSymbols;

#[CoversClass(UnboundSymbols::class)]
#[UsesClass(ReferencePath::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(InfixExpression::class)]
#[UsesClass(UnaryExpression::class)]
#[UsesClass(MatchExpression::class)]
#[UsesClass(MatchArm::class)]
#[UsesClass(LiteralPattern::class)]
#[UsesClass(WildcardPattern::class)]
#[UsesClass(ExpressionPattern::class)]
#[UsesClass(MemberAccessSource::class)]
#[UsesClass(Coerce::class)]
#[UsesClass(NumberType::class)]
final class UnboundSymbolsTest extends TestCase
{
    #[Test]
    public function finds_a_single_reference(): void
    {
        $reference = new ReferencePath('radius');

        $this->assertSame([$reference], UnboundSymbols::in($reference));
    }

    #[Test]
    public function finds_nothing_in_a_static_source(): void
    {
        $this->assertSame([], UnboundSymbols::in(new StaticSource(42)));
    }

    #[Test]
    public function finds_symbols_in_nested_infix_expressions(): void
    {
        $pi = new ReferencePath('PI');
        $radius = new ReferencePath('radius');

        $source = new InfixExpression(
            left: $pi,
            operator: '*',
            right: new InfixExpression(
                left: $radius,
                operator: '*',
                right: new ReferencePath('radius'),
            ),
        );

        $this->assertSame([$pi, $radius], UnboundSymbols::in($source));
    }

    #[Test]
    public function deduplicates_repeated_symbols(): void
    {
        $first = new ReferencePath('x');

        $source = new InfixExpression(
            left: $first,
            operator: '+',
            right: new ReferencePath('x'),
        );

        $this->assertSame([$first], UnboundSymbols::in($source));
    }

    #[Test]
    public function different_root_names_have_distinct_identity(): void
    {
        $bare = new ReferencePath('value');
        $namespaced = new ReferencePath('other_value');

        $source = new InfixExpression(
            left: $bare,
            operator: '+',
            right: $namespaced,
        );

        $this->assertSame([$bare, $namespaced], UnboundSymbols::in($source));
    }

    #[Test]
    public function different_names_are_distinct(): void
    {
        $pi = new ReferencePath('pi');
        $e = new ReferencePath('e');

        $source = new InfixExpression(
            left: $pi,
            operator: '+',
            right: $e,
        );

        $this->assertSame([$pi, $e], UnboundSymbols::in($source));
    }

    #[Test]
    public function walks_into_unary_expressions(): void
    {
        $n = new ReferencePath('n');
        $source = new UnaryExpression('-', $n);

        $this->assertSame([$n], UnboundSymbols::in($source));
    }

    #[Test]
    public function walks_into_match_expressions_including_arms_and_patterns(): void
    {
        $tier = new ReferencePath('tier');
        $fallbackPattern = new ReferencePath('fallback_pattern');
        $fallbackValue = new ReferencePath('fallback_value');

        $source = new MatchExpression(
            subject: $tier,
            arms: [
                new MatchArm(new LiteralPattern('micro'), new StaticSource(1.3)),
                new MatchArm(
                    new ExpressionPattern($fallbackPattern),
                    $fallbackValue,
                ),
                new MatchArm(new WildcardPattern(), new StaticSource(1.0)),
            ],
        );

        $this->assertSame([$tier, $fallbackPattern, $fallbackValue], UnboundSymbols::in($source));
    }

    #[Test]
    public function finds_a_structural_reference_in_a_type_definition(): void
    {
        $quoteClaims = new ReferencePath('quote', 'claims');

        $source = new Coerce(
            type: new NumberType(),
            source: $quoteClaims,
        );

        $this->assertSame([$quoteClaims], UnboundSymbols::in($source));
    }

    #[Test]
    public function normalizes_a_deprecated_symbol_member_chain(): void
    {
        $legacy = new MemberAccessSource(new SymbolSource('quote'), 'claims');

        $this->assertEquals([new ReferencePath('quote', 'claims')], UnboundSymbols::in($legacy));
    }

    #[Test]
    public function normalizes_a_deprecated_symbol(): void
    {
        $this->assertEquals([new ReferencePath('quote')], UnboundSymbols::in(new SymbolSource('quote')));
    }

    #[Test]
    public function arbitrary_member_access_is_not_a_rooted_reference(): void
    {
        $source = new MemberAccessSource(new StaticSource(['claims' => 3]), 'claims');

        $this->assertSame([], UnboundSymbols::in($source));
    }
}
