<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Rewrite\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Rewrite\ArrayBindingsCorpus;
use Superscript\Axiom\Rewrite\Preservation;
use Superscript\Axiom\Rewrite\Rewriter;
use Superscript\Axiom\Rewrite\Rules\RemoveDoubleNegation;
use Superscript\Axiom\Rewrite\VerdictPreservation;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\Optional;
use Superscript\Axiom\Types\OptionType;

#[CoversClass(RemoveDoubleNegation::class)]
#[UsesNamespace('Superscript\\Axiom')]
final class RemoveDoubleNegationTest extends TestCase
{
    #[Test]
    public function a_pair_of_negations_leaves_only_the_operand(): void
    {
        $operand = new SymbolSource('flag');

        $rewritten = (new RemoveDoubleNegation())->rewrite(new UnaryExpression('!', new UnaryExpression('!', $operand)));

        $this->assertSame($operand, $rewritten);
    }

    #[Test]
    public function the_two_spellings_of_negation_cancel_each_other(): void
    {
        $operand = new SymbolSource('flag');

        $this->assertSame($operand, (new RemoveDoubleNegation())->rewrite(new UnaryExpression('not', new UnaryExpression('!', $operand))));
        $this->assertSame($operand, (new RemoveDoubleNegation())->rewrite(new UnaryExpression('!', new UnaryExpression('not', $operand))));
    }

    #[Test]
    public function a_single_negation_is_left_alone(): void
    {
        $this->assertNull((new RemoveDoubleNegation())->rewrite(new UnaryExpression('!', new SymbolSource('flag'))));
    }

    #[Test]
    public function another_operator_is_left_alone(): void
    {
        $this->assertNull((new RemoveDoubleNegation())->rewrite(new UnaryExpression('-', new UnaryExpression('-', new SymbolSource('count')))));
        $this->assertNull((new RemoveDoubleNegation())->rewrite(new UnaryExpression('!', new UnaryExpression('-', new SymbolSource('count')))));
        $this->assertNull((new RemoveDoubleNegation())->rewrite(new UnaryExpression('-', new UnaryExpression('!', new SymbolSource('flag')))));
    }

    #[Test]
    public function another_node_shape_is_left_alone(): void
    {
        $this->assertNull((new RemoveDoubleNegation())->rewrite(new StaticSource(true)));
    }

    #[Test]
    public function a_dialect_declares_which_of_its_spellings_are_involutive(): void
    {
        $rule = new RemoveDoubleNegation(['!']);

        $this->assertNull($rule->rewrite(new UnaryExpression('not', new UnaryExpression('not', new SymbolSource('flag')))));
        $this->assertNotNull($rule->rewrite(new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('flag')))));
    }

    #[Test]
    public function it_visits_unary_expressions_and_claims_verdict_preservation(): void
    {
        $rule = new RemoveDoubleNegation();

        $this->assertSame([UnaryExpression::class], $rule->visits());
        $this->assertSame([Preservation::Verdict], $rule->preserves());
        $this->assertSame('axiom.rewrite.remove-double-negation', $rule->identifier());
    }

    /**
     * Absence is where a "harmless" simplification usually stops being one:
     * the lifted `!` propagates it, so `!!` is the lift of the identity and
     * an unanswered question stays unanswered on both sides of the rewrite.
     */
    #[Test]
    public function it_is_neutral_over_absence(): void
    {
        $comparison = new InfixExpression(new SymbolSource('roof'), '>', new StaticSource(0.25));
        $expression = new Expression(
            source: new UnaryExpression('!', new UnaryExpression('!', $comparison)),
            declarations: ['roof' => new Optional(new OptionType(new NumberType()))],
        );

        $run = (new Rewriter(
            [new RemoveDoubleNegation()],
            obligations: [new VerdictPreservation(new ArrayBindingsCorpus([
                'above' => ['roof' => 0.3],
                'below' => ['roof' => 0.1],
                'unanswered' => [],
            ]))],
        ))->rewrite($expression);

        $this->assertSame('roof > 0.25', $run->source->describe());
        $this->assertSame('verdict preservation upheld: 3 corpus case(s) agree', $run->report->applied()[0]->verdicts[1]->describe());

        $program = $run->expression()->compile()->unwrap();
        $this->assertTrue($program([])->unwrap()->isNone(), 'not-knowing, negated twice or not at all, is still not-knowing');
    }

    #[Test]
    public function a_negation_over_something_that_is_not_boolean_is_refused_rather_than_simplified(): void
    {
        $expression = new Expression(
            source: new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('count'))),
            declarations: ['count' => new NumberType()],
        );

        $run = (new Rewriter([new RemoveDoubleNegation()]))->rewrite($expression);

        $this->assertFalse($run->changed, 'the original never compiled; handing back a certified Number program would invent one');
        $this->assertCount(1, $run->report->refused());
    }

    #[Test]
    public function a_boolean_expression_keeps_its_answers(): void
    {
        $expression = new Expression(
            source: new InfixExpression(
                new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('flag'))),
                '&&',
                new UnaryExpression('!', new SymbolSource('other')),
            ),
            declarations: ['flag' => new BooleanType(), 'other' => new BooleanType()],
        );

        $run = (new Rewriter([new RemoveDoubleNegation()]))->rewrite($expression);
        $before = $expression->compile()->unwrap();
        $after = $run->expression()->compile()->unwrap();

        foreach ([[true, true], [true, false], [false, true], [false, false]] as [$flag, $other]) {
            $bindings = ['flag' => $flag, 'other' => $other];
            $this->assertSame($before($bindings)->unwrap()->unwrap(), $after($bindings)->unwrap()->unwrap());
        }

        $this->assertSame('flag && !other', $run->source->describe());
    }
}
