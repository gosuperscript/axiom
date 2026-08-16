<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\KitchenSink;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\RecordType;

#[CoversNothing]
class KitchenSinkTest extends TestCase
{
    #[Test]
    public function an_expression_compiles_once_and_the_program_is_invoked_per_call(): void
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

        $area = (new Expression(
            source: $source,
            definitions: new Definitions(['PI' => new StaticSource(3.14159)]),
            declarations: ['radius' => new NumberType()],
        ))->compile()->unwrap();

        $this->assertEqualsWithDelta(78.54, $area(['radius' => 5])->unwrap()->unwrap(), 0.01);
        $this->assertEqualsWithDelta(314.16, $area(['radius' => 10])->unwrap()->unwrap(), 0.01);
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
            definitions: new Definitions(['PI' => new StaticSource(3.14159)]),
        );

        // PI is covered by definitions; only radius remains a parameter.
        $this->assertSame(['radius'], $area->parameters());
    }

    #[Test]
    public function transforming_a_value(): void
    {
        $source = new Coerce(
            type: new NumberType(),
            source: new StaticSource('5'),
        );

        $program = (new Expression($source))->compile()->unwrap();

        $this->assertSame(5, $program()->unwrap()->unwrap());
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
                            left: new MemberAccessSource(new SymbolSource('quote'), 'claims'),
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

        $program = (new Expression(
            $source,
            declarations: ['quote' => new RecordType(['claims' => new NumberType()])],
        ))->compile()->unwrap();

        $this->assertEquals(25.0, $program(['quote' => ['claims' => 3]])->unwrap()->unwrap());
        $this->assertEquals(0, $program(['quote' => ['claims' => 1]])->unwrap()->unwrap());
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

        $multiplier = (new Expression(
            $source,
            declarations: ['tier' => new \Superscript\Axiom\Types\StringType()],
        ))->compile()->unwrap();

        $this->assertEquals(1.3, $multiplier(['tier' => 'micro'])->unwrap()->unwrap());
        $this->assertEquals(1.1, $multiplier(['tier' => 'small'])->unwrap()->unwrap());
        $this->assertEquals(1.0, $multiplier(['tier' => 'enormous'])->unwrap()->unwrap());
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
                            left: new MemberAccessSource(new SymbolSource('quote'), 'claims'),
                            operator: '>',
                            right: new StaticSource(3),
                        ),
                    ),
                    new StaticSource(0.5),
                ),
                new MatchArm(
                    new ExpressionPattern(
                        new InfixExpression(
                            left: new MemberAccessSource(new SymbolSource('quote'), 'turnover'),
                            operator: '>',
                            right: new StaticSource(500000),
                        ),
                    ),
                    new StaticSource(0.35),
                ),
                new MatchArm(new WildcardPattern(), new StaticSource(0.1)),
            ],
        );

        $rate = (new Expression(
            $source,
            declarations: [
                'quote' => new RecordType([
                    'claims' => new NumberType(),
                    'turnover' => new NumberType(),
                ]),
            ],
        ))->compile()->unwrap();

        $this->assertEquals(0.5, $rate(['quote' => ['claims' => 5, 'turnover' => 100]])->unwrap()->unwrap());
        $this->assertEquals(0.35, $rate(['quote' => ['claims' => 1, 'turnover' => 600000]])->unwrap()->unwrap());
        $this->assertEquals(0.1, $rate(['quote' => ['claims' => 0, 'turnover' => 100]])->unwrap()->unwrap());
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
                right: new Coerce(
                    type: new NumberType(),
                    source: new StaticSource('3'),
                ),
            ),
        );

        $program = (new Expression(
            $source,
            declarations: ['A' => new NumberType()],
        ))->compile()->unwrap();

        $this->assertEquals(7, $program(['A' => 2])->unwrap()->unwrap());
        $this->assertEquals(16, $program(['A' => 5])->unwrap()->unwrap());
    }

    #[Test]
    public function certify_a_gate_before_evaluating_anything(): void
    {
        // The corpus-sweep workflow: a host holds a stored gate expression
        // and asks, before any evaluation, "is this condition boolean, and
        // is it meaningful for the declared inputs?" — and the artifact that
        // answers yes is the same artifact it will invoke per request.
        $gate = new Expression(
            source: new InfixExpression(
                left: new InfixExpression(new MemberAccessSource(new SymbolSource('quote'), 'turnover'), '*', new StaticSource(1.2)),
                operator: '>',
                right: new StaticSource(500_000),
            ),
            declarations: ['quote' => new RecordType(['turnover' => new NumberType()])],
        );

        $this->assertTrue($gate->check(new \Superscript\Axiom\Types\BooleanType())->isOk());

        $program = $gate->compile()->unwrap();

        // The same declarations then guard every call: the boundary coerces
        // a stringly CSV cell before evaluation, so the certified program
        // never sees raw input.
        $this->assertTrue($program(['quote' => ['turnover' => '600000']])->unwrap()->unwrap());
        $this->assertFalse($program(['quote' => ['turnover' => '100000']])->unwrap()->unwrap());
    }

    #[Test]
    public function the_compiler_flags_dead_comparisons_and_unprovable_matches(): void
    {
        $tier = new \Superscript\Axiom\Types\UnionType(
            new \Superscript\Axiom\Types\LiteralType('micro'),
            new \Superscript\Axiom\Types\LiteralType('small'),
        );

        // 'enormous' is not a member of tier's enum: the comparison can
        // never hold, and the program does not compile — no quote will
        // ever run it.
        $dead = new Expression(
            source: new InfixExpression(new SymbolSource('tier'), '==', new StaticSource('enormous')),
            declarations: ['tier' => $tier],
        );

        $verdict = $dead->compile();

        $this->assertTrue($verdict->isErr());
        $this->assertTrue($verdict->unwrapErr()->dead);

        // A match that covers only one of the two tiers is unprovable —
        // an unmatched subject is a runtime error, so the compiler demands
        // the missing arm (or a wildcard) instead of letting it ship.
        $partial = new Expression(
            source: new MatchExpression(
                subject: new SymbolSource('tier'),
                arms: [new MatchArm(new LiteralPattern('micro'), new StaticSource(1.3))],
            ),
            declarations: ['tier' => $tier],
        );

        $this->assertStringContainsString('may not be exhaustive', $partial->compile()->unwrapErr()->describe());
    }

    #[Test]
    public function an_overridable_derived_value_is_an_option_typed_parameter(): void
    {
        // Callers may not override internals implicitly (declarations and
        // definitions are disjoint; undeclared bindings are stripped). An
        // override is modeled in-language instead: an optional, typed
        // parameter the derived value consults — certified on both paths.
        // The match's null arm makes absence unreachable, but without
        // narrowing the join still reads Number? — so the author claims
        // presence with an Ascription, the runtime-verified spelling of
        // "I handled null already".
        $rate = (new Expression(
            source: new InfixExpression(new SymbolSource('turnover'), '*', new SymbolSource('riskFactor')),
            definitions: new Definitions([
                // riskFactor = (match riskFactorOverride { null => 1.5, _ => riskFactorOverride }) is Number
                'riskFactor' => new \Superscript\Axiom\Sources\Ascription(new NumberType(), new MatchExpression(
                    subject: new SymbolSource('riskFactorOverride'),
                    arms: [
                        new MatchArm(new LiteralPattern(null), new StaticSource(1.5)),
                        new MatchArm(new WildcardPattern(), new SymbolSource('riskFactorOverride')),
                    ],
                )),
            ]),
            declarations: [
                'turnover' => new NumberType(),
                'riskFactorOverride' => new \Superscript\Axiom\Types\Optional(new \Superscript\Axiom\Types\OptionType(new NumberType())),
            ],
        ))->compile()->unwrap();

        $this->assertEquals(150, $rate(['turnover' => 100])->unwrap()->unwrap());
        $this->assertEquals(200, $rate(['turnover' => 100, 'riskFactorOverride' => 2.0])->unwrap()->unwrap());

        // The old implicit path is gone: an undeclared key aimed at the
        // internal is stripped, not honored.
        $this->assertEquals(150, $rate(['turnover' => 100, 'riskFactor' => 99.0])->unwrap()->unwrap());
    }

    #[Test]
    public function a_domain_operator_is_one_declaration_checked_and_run_alike(): void
    {
        // A host teaches the language a domain operator with the rule
        // builder: one declaration is one verdict — the compiler certifies
        // the row and binds its evaluation into the program.
        $percentage = new class extends \Superscript\Axiom\Extension {
            public function operators(): array
            {
                return [
                    \Superscript\Axiom\Operators\Operator::infix('%of')
                        ->takes(new NumberType(), new NumberType())
                        ->returns(new NumberType())
                        ->evaluatesWith(fn(int|float $percent, int|float $total) => $total * $percent / 100),
                ];
            }
        };

        $commission = (new Expression(
            source: new InfixExpression(new SymbolSource('rate'), '%of', new SymbolSource('premium')),
            dialect: \Superscript\Axiom\Dialect::core()->with($percentage),
            declarations: ['rate' => new NumberType(), 'premium' => new NumberType()],
        ))->compile()->unwrap();

        $this->assertInstanceOf(NumberType::class, $commission->returns);
        $this->assertEquals(125.0, $commission(['rate' => 25, 'premium' => 500])->unwrap()->unwrap());

        // And the compiler refuses what the rule never claimed: strings
        // are outside the declared signature, so no program exists.
        $misuse = new Expression(
            source: new InfixExpression(new StaticSource('a quarter'), '%of', new SymbolSource('premium')),
            dialect: \Superscript\Axiom\Dialect::core()->with($percentage),
            declarations: ['premium' => new NumberType()],
        );

        $this->assertTrue($misuse->compile()->isErr());
    }
}
