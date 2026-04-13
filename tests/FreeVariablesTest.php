<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\FreeVariables;
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

#[CoversClass(FreeVariables::class)]
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
final class FreeVariablesTest extends TestCase
{
    #[Test]
    public function finds_a_single_symbol(): void
    {
        $source = new SymbolSource('radius');

        $this->assertSame(
            [['name' => 'radius', 'namespace' => null]],
            FreeVariables::of($source),
        );
    }

    #[Test]
    public function finds_nothing_in_a_static_source(): void
    {
        $this->assertSame([], FreeVariables::of(new StaticSource(42)));
    }

    #[Test]
    public function finds_symbols_in_nested_infix_expressions(): void
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

        $this->assertSame(
            [
                ['name' => 'PI', 'namespace' => null],
                ['name' => 'radius', 'namespace' => null],
            ],
            FreeVariables::of($source),
        );
    }

    #[Test]
    public function deduplicates_repeated_symbols(): void
    {
        $source = new InfixExpression(
            left: new SymbolSource('x'),
            operator: '+',
            right: new SymbolSource('x'),
        );

        $this->assertSame(
            [['name' => 'x', 'namespace' => null]],
            FreeVariables::of($source),
        );
    }

    #[Test]
    public function namespace_and_name_together_form_the_identity(): void
    {
        $source = new InfixExpression(
            left: new SymbolSource('value'),
            operator: '+',
            right: new SymbolSource('value', 'ns'),
        );

        $this->assertSame(
            [
                ['name' => 'value', 'namespace' => null],
                ['name' => 'value', 'namespace' => 'ns'],
            ],
            FreeVariables::of($source),
        );
    }

    #[Test]
    public function walks_into_unary_expressions(): void
    {
        $source = new UnaryExpression('-', new SymbolSource('n'));

        $this->assertSame(
            [['name' => 'n', 'namespace' => null]],
            FreeVariables::of($source),
        );
    }

    #[Test]
    public function walks_into_match_expressions_including_arms_and_patterns(): void
    {
        $source = new MatchExpression(
            subject: new SymbolSource('tier'),
            arms: [
                new MatchArm(new LiteralPattern('micro'), new StaticSource(1.3)),
                new MatchArm(
                    new ExpressionPattern(new SymbolSource('fallback_pattern')),
                    new SymbolSource('fallback_value'),
                ),
                new MatchArm(new WildcardPattern(), new StaticSource(1.0)),
            ],
        );

        $this->assertSame(
            [
                ['name' => 'tier', 'namespace' => null],
                ['name' => 'fallback_pattern', 'namespace' => null],
                ['name' => 'fallback_value', 'namespace' => null],
            ],
            FreeVariables::of($source),
        );
    }

    #[Test]
    public function walks_into_member_access_and_type_definition(): void
    {
        $source = new TypeDefinition(
            type: new NumberType(),
            source: new MemberAccessSource(
                object: new SymbolSource('quote'),
                property: 'claims',
            ),
        );

        $this->assertSame(
            [['name' => 'quote', 'namespace' => null]],
            FreeVariables::of($source),
        );
    }
}
