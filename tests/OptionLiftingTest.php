<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Tests\Fixtures\Money;
use Superscript\Axiom\Tests\Fixtures\MoneyType;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\TypeRelations;

/**
 * Absence through operators, end to end: most symbols a host declares are
 * optional (an unanswered question, an unrated term), and the program's
 * static face must agree with its runtime face. Operators are strict in
 * present values and absence propagates; the boolean connectives are the
 * deliberate exception (Kleene — a dominant present operand decides); the
 * boundary decides what a still-absent result means.
 */
#[CoversNothing]
final class OptionLiftingTest extends TestCase
{
    private static function aboveQuarter(): InfixExpression
    {
        return new InfixExpression(new SymbolSource('x'), '>', new StaticSource(0.25));
    }

    #[Test]
    public function a_comparison_over_an_optional_symbol_is_optional_and_propagates(): void
    {
        $program = (new Expression(
            source: self::aboveQuarter(),
            declarations: ['x' => new OptionType(new NumberType())],
        ))->compile()->unwrap();

        $this->assertTrue(TypeRelations::areEquivalent($program->returns, new OptionType(new BooleanType()))->isOk());
        $this->assertTrue($program(['x' => 0.3])->unwrap()->unwrap());
        $this->assertFalse($program(['x' => 0.1])->unwrap()->unwrap());
        $this->assertTrue($program([])->unwrap()->isNone(), 'an unanswered symbol answers absence, not an error');
    }

    #[Test]
    public function negation_does_not_resurrect_an_absent_comparison(): void
    {
        $program = (new Expression(
            source: new UnaryExpression(operator: '!', operand: self::aboveQuarter()),
            declarations: ['x' => new OptionType(new NumberType())],
        ))->compile()->unwrap();

        $this->assertFalse($program(['x' => 0.3])->unwrap()->unwrap());
        $this->assertTrue($program([])->unwrap()->isNone(), 'not-knowing negated is still not-knowing');
    }

    #[Test]
    public function a_dominant_present_operand_decides_a_disjunction(): void
    {
        $program = (new Expression(
            source: new InfixExpression(self::aboveQuarter(), '||', new SymbolSource('y')),
            declarations: ['x' => new OptionType(new NumberType()), 'y' => new BooleanType()],
        ))->compile()->unwrap();

        $this->assertTrue($program(['y' => true])->unwrap()->unwrap(), 'true decides, however unanswered the other arm');
        $this->assertTrue($program(['y' => false])->unwrap()->isNone(), 'false decides nothing in a disjunction');
    }

    #[Test]
    public function a_dominant_present_operand_decides_a_conjunction(): void
    {
        $program = (new Expression(
            source: new InfixExpression(self::aboveQuarter(), '&&', new SymbolSource('y')),
            declarations: ['x' => new OptionType(new NumberType()), 'y' => new BooleanType()],
        ))->compile()->unwrap();

        $this->assertFalse($program(['y' => false])->unwrap()->unwrap(), 'false decides, however unanswered the other arm');
        $this->assertTrue($program(['y' => true])->unwrap()->isNone(), 'true decides nothing in a conjunction');
    }

    #[Test]
    public function the_emptiness_probe_is_untouched_by_lifting(): void
    {
        // Equality reads optional operands as given — `x == null` stays the
        // presence probe it has always been, present in its answer.
        $program = (new Expression(
            source: new InfixExpression(new SymbolSource('x'), '==', new StaticSource(null)),
            declarations: ['x' => new OptionType(new NumberType())],
        ))->compile()->unwrap();

        $this->assertTrue($program([])->unwrap()->unwrap());
        $this->assertFalse($program(['x' => 1])->unwrap()->unwrap());
    }

    #[Test]
    public function a_total_opaque_value_is_provably_not_absent_without_value_equality(): void
    {
        $program = (new Expression(
            source: new InfixExpression(new SymbolSource('price'), '===', new StaticSource(null)),
            declarations: ['price' => new MoneyType('GBP')],
        ))->compile()->unwrap();

        $this->assertFalse($program(['price' => new Money(500, 'GBP')])->unwrap()->unwrap());
    }

    /**
     * The authored default, end to end: the same comparison that types
     * `Boolean?` over an optional symbol types `Boolean` once the author
     * spells out what an unanswered question counts as.
     */
    #[Test]
    public function an_authored_default_makes_a_comparison_definite(): void
    {
        $program = (new Expression(
            source: new InfixExpression(
                new InfixExpression(new SymbolSource('x'), '??', new StaticSource(0)),
                '>',
                new StaticSource(0.25),
            ),
            declarations: ['x' => new OptionType(new NumberType())],
        ))->compile()->unwrap();

        $this->assertTrue(TypeRelations::areEquivalent($program->returns, new BooleanType())->isOk());
        $this->assertTrue($program(['x' => 0.3])->unwrap()->unwrap());
        $this->assertFalse($program([])->unwrap()->unwrap(), 'unanswered counts as 0, and 0 is not above a quarter');
    }

    #[Test]
    public function authored_defaults_chain_left_to_right(): void
    {
        $program = (new Expression(
            source: new InfixExpression(
                new InfixExpression(new SymbolSource('x'), '??', new SymbolSource('y')),
                '??',
                new StaticSource(0),
            ),
            declarations: ['x' => new OptionType(new NumberType()), 'y' => new OptionType(new NumberType())],
        ))->compile()->unwrap();

        $this->assertTrue(TypeRelations::areEquivalent($program->returns, new NumberType())->isOk());
        $this->assertSame(1, $program(['x' => 1, 'y' => 2])->unwrap()->unwrap());
        $this->assertSame(2, $program(['y' => 2])->unwrap()->unwrap());
        $this->assertSame(0, $program([])->unwrap()->unwrap());
    }

    /**
     * An optional field behind an optional owner is optional once, not
     * twice: absence is one null in the value domain however many Option
     * constructors the type is built from. So the authored default
     * discharges it in full, and the comparison is definite — the same
     * guarantee a directly declared optional symbol gets.
     */
    #[Test]
    public function an_authored_default_discharges_absence_behind_an_optional_owner(): void
    {
        $premium = new MemberAccessSource(new SymbolSource('quote'), 'premium');
        $declarations = ['quote' => new OptionType(new RecordType(['premium' => new OptionType(new NumberType())]))];

        $bare = (new Expression(source: $premium, declarations: $declarations))->compile()->unwrap();
        $this->assertTrue(TypeRelations::areEquivalent($bare->returns, new OptionType(new NumberType()))->isOk());

        $program = (new Expression(
            source: new InfixExpression(
                new InfixExpression($premium, '??', new StaticSource(0)),
                '>',
                new StaticSource(0.25),
            ),
            declarations: $declarations,
        ))->compile()->unwrap();

        $this->assertTrue(TypeRelations::areEquivalent($program->returns, new BooleanType())->isOk());
        $this->assertTrue($program(['quote' => ['premium' => 0.3]])->unwrap()->unwrap());
        $this->assertFalse($program(['quote' => []])->unwrap()->unwrap(), 'an unanswered field counts as 0');
        $this->assertFalse($program([])->unwrap()->unwrap(), 'an unanswered owner counts as 0 too');
    }

    /**
     * The same collapse on the lift path: a rule matched on the present
     * type is reached through every Option constructor, not just the
     * outermost one.
     */
    #[Test]
    public function lifting_reaches_through_an_optional_owner(): void
    {
        $program = (new Expression(
            source: new UnaryExpression(
                operator: '!',
                operand: new MemberAccessSource(new SymbolSource('quote'), 'accepted'),
            ),
            declarations: ['quote' => new OptionType(new RecordType(['accepted' => new OptionType(new BooleanType())]))],
        ))->compile()->unwrap();

        $this->assertTrue(TypeRelations::areEquivalent($program->returns, new OptionType(new BooleanType()))->isOk());
        $this->assertFalse($program(['quote' => ['accepted' => true]])->unwrap()->unwrap());
        $this->assertTrue($program(['quote' => []])->unwrap()->isNone());
        $this->assertTrue($program([])->unwrap()->isNone());
    }

    #[Test]
    public function defaulting_a_value_that_can_never_be_absent_refuses(): void
    {
        // The fallback could never fire, so the operator is a constant the
        // author did not mean to write.
        $verdict = (new Expression(
            source: new InfixExpression(new SymbolSource('x'), '??', new StaticSource(0)),
            declarations: ['x' => new NumberType()],
        ))->compile();

        $this->assertStringContainsString('the fallback can never fire', $verdict->unwrapErr()->describe());
    }

    #[Test]
    public function literal_null_arithmetic_still_refuses(): void
    {
        // A bare null types as Option<Never>: nothing can ever be present,
        // so the lift declines and the exact refusal reaches the author.
        $verdict = (new Expression(
            source: new InfixExpression(new StaticSource(null), '+', new StaticSource(1)),
        ))->compile();

        $this->assertStringContainsString('[+] expects Number and Number', $verdict->unwrapErr()->describe());
    }
}
