<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
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
