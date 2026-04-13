<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\TypeDefinition;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\UnboundSymbols;

#[CoversClass(UnboundSymbols::class)]
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
#[UsesClass(TypeDefinition::class)]
#[UsesClass(NumberType::class)]
final class UnboundSymbolsTest extends TestCase
{
    #[Test]
    public function finds_a_single_symbol(): void
    {
        $symbol = new SymbolSource('radius');

        $this->assertSame([$symbol], UnboundSymbols::in($symbol));
    }

    #[Test]
    public function finds_nothing_in_a_static_source(): void
    {
        $this->assertSame([], UnboundSymbols::in(new StaticSource(42)));
    }

    #[Test]
    public function finds_symbols_in_nested_infix_expressions(): void
    {
        $pi = new SymbolSource('PI');
        $radius = new SymbolSource('radius');

        $source = new InfixExpression(
            left: $pi,
            operator: '*',
            right: new InfixExpression(
                left: $radius,
                operator: '*',
                right: new SymbolSource('radius'),
            ),
        );

        $this->assertSame([$pi, $radius], UnboundSymbols::in($source));
    }

    #[Test]
    public function deduplicates_repeated_symbols(): void
    {
        $first = new SymbolSource('x');

        $source = new InfixExpression(
            left: $first,
            operator: '+',
            right: new SymbolSource('x'),
        );

        $this->assertSame([$first], UnboundSymbols::in($source));
    }

    #[Test]
    public function namespace_and_name_together_form_the_identity(): void
    {
        $bare = new SymbolSource('value');
        $namespaced = new SymbolSource('value', 'ns');

        $source = new InfixExpression(
            left: $bare,
            operator: '+',
            right: $namespaced,
        );

        $this->assertSame([$bare, $namespaced], UnboundSymbols::in($source));
    }

    #[Test]
    public function different_names_within_the_same_namespace_are_distinct(): void
    {
        $pi = new SymbolSource('pi', 'math');
        $e = new SymbolSource('e', 'math');

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
        $n = new SymbolSource('n');
        $source = new UnaryExpression('-', $n);

        $this->assertSame([$n], UnboundSymbols::in($source));
    }

    #[Test]
    public function walks_into_match_expressions_including_arms_and_patterns(): void
    {
        $tier = new SymbolSource('tier');
        $fallbackPattern = new SymbolSource('fallback_pattern');
        $fallbackValue = new SymbolSource('fallback_value');

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
    public function walks_into_member_access_and_type_definition(): void
    {
        $quote = new SymbolSource('quote');

        $source = new TypeDefinition(
            type: new NumberType(),
            source: new MemberAccessSource(
                object: $quote,
                property: 'claims',
            ),
        );

        $this->assertSame([$quote], UnboundSymbols::in($source));
    }
}
