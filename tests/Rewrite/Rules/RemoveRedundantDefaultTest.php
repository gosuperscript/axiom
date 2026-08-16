<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Rewrite\Rules;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Rewrite\Preservation;
use Superscript\Axiom\Rewrite\Rewriter;
use Superscript\Axiom\Rewrite\Rules\RemoveRedundantDefault;
use Superscript\Axiom\Sources\DefaultValue;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;

#[CoversClass(RemoveRedundantDefault::class)]
#[UsesNamespace('Superscript\\Axiom')]
final class RemoveRedundantDefaultTest extends TestCase
{
    #[Test]
    public function it_proposes_the_inner_source_of_any_default(): void
    {
        $inner = new SymbolSource('amount');

        $this->assertSame($inner, (new RemoveRedundantDefault())->rewrite(new DefaultValue($inner, 0)));
        $this->assertNull((new RemoveRedundantDefault())->rewrite($inner));
    }

    #[Test]
    public function it_visits_defaults_and_claims_verdict_preservation(): void
    {
        $rule = new RemoveRedundantDefault();

        $this->assertSame([DefaultValue::class], $rule->visits());
        $this->assertSame([Preservation::Verdict], $rule->preserves());
        $this->assertSame('axiom.rewrite.remove-redundant-default', $rule->identifier());
    }

    #[Test]
    public function a_default_over_a_definite_source_is_removed(): void
    {
        $expression = new Expression(
            source: new DefaultValue(new SymbolSource('amount'), 0),
            declarations: ['amount' => new NumberType()],
        );

        $run = (new Rewriter([new RemoveRedundantDefault()]))->rewrite($expression);

        $this->assertSame('amount', $run->source->describe());
        $this->assertSame('type preservation upheld: both compile to Number', $run->report->applied()[0]->verdicts[0]->describe());
    }

    #[Test]
    public function a_default_that_can_actually_fire_is_refused(): void
    {
        $expression = new Expression(
            source: new DefaultValue(new SymbolSource('amount'), 0),
            declarations: ['amount' => new OptionType(new NumberType())],
        );

        $run = (new Rewriter([new RemoveRedundantDefault()]))->rewrite($expression);

        $this->assertFalse($run->changed);
        $this->assertSame(
            'type preservation broken: the original compiles to Number and the replacement to Number?',
            $run->report->refused()[0]->verdicts[0]->describe(),
        );
    }

    #[Test]
    public function a_nested_redundant_default_is_removed_without_touching_the_useful_one(): void
    {
        $expression = new Expression(
            source: new DefaultValue(new DefaultValue(new SymbolSource('amount'), 1), 0),
            declarations: ['amount' => new OptionType(new NumberType())],
        );

        $run = (new Rewriter([new RemoveRedundantDefault()]))->rewrite($expression);

        $this->assertSame('amount ?? 1', $run->source->describe(), 'the inner default is the one absence can reach');
        $this->assertSame(['$'], array_map(fn($record): string => $record->path, $run->report->applied()));
        $this->assertSame(['$.source'], array_map(fn($record): string => $record->path, $run->report->refused()));
    }

    #[Test]
    public function the_program_answers_the_same_thing_either_way(): void
    {
        $expression = new Expression(
            source: new DefaultValue(new SymbolSource('amount'), 0),
            declarations: ['amount' => new NumberType()],
        );

        $run = (new Rewriter([new RemoveRedundantDefault()]))->rewrite($expression);

        $before = $expression->compile()->unwrap();
        $after = $run->expression()->compile()->unwrap();

        $this->assertSame(7, $before(['amount' => 7])->unwrap()->unwrap());
        $this->assertSame(7, $after(['amount' => 7])->unwrap()->unwrap());
    }
}
