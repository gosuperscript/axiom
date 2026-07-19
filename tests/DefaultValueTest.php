<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Expression;
use Superscript\Axiom\SourceCompilers\DefaultValueSourceCompiler;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\DefaultValue;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Tests\Fixtures\SpyObserver;
use Superscript\Axiom\Tests\Fixtures\ProjectedNumberOptionType;
use Superscript\Axiom\Tests\Fixtures\Money;
use Superscript\Axiom\Tests\Fixtures\MoneyType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;

#[CoversClass(DefaultValueSourceCompiler::class)]
#[UsesClass(DefaultValue::class)]
#[UsesClass(Expression::class)]
#[\PHPUnit\Framework\Attributes\UsesNamespace('Superscript\\Axiom')]
final class DefaultValueTest extends TestCase
{
    #[Test]
    public function it_replaces_absence_with_a_value_coerced_to_the_present_type(): void
    {
        $expression = new Expression(
            new DefaultValue(new SymbolSource('amount'), '12.5'),
            declarations: ['amount' => new OptionType(new NumberType())],
        );

        $program = $expression->compile()->unwrap();

        $this->assertInstanceOf(NumberType::class, $program->returns);
        $absentObserver = new SpyObserver();
        $presentObserver = new SpyObserver();

        $this->assertSame(12.5, $program->call([], observer: $absentObserver)->unwrap()->unwrap());
        $this->assertSame([
            ['source', null],
            ['used_default', true],
            ['result', 12.5],
            ['label', 'default'],
        ], array_slice($absentObserver->timeline, -4));
        $this->assertSame(7, $program->call(['amount' => 7], observer: $presentObserver)->unwrap()->unwrap());
        $this->assertSame([
            ['source', 7],
            ['used_default', false],
            ['result', 7],
            ['label', 'default'],
        ], array_slice($presentObserver->timeline, -4));
    }

    #[Test]
    public function it_preserves_the_present_collection_type_for_an_empty_default(): void
    {
        $expression = new Expression(
            new DefaultValue(new SymbolSource('items'), []),
            declarations: ['items' => new OptionType(new ListType(new StringType()))],
        );

        $program = $expression->compile()->unwrap();

        $this->assertEquals(new ListType(new StringType()), $program->returns);
        $this->assertSame([], $program([])->unwrap()->unwrap());
        $this->assertSame(['one'], $program(['items' => ['one']])->unwrap()->unwrap());
    }

    #[Test]
    public function it_preserves_a_host_type_inside_a_core_option_instead_of_reifying_its_shape(): void
    {
        $default = new Money(1250, 'GBP');
        $program = (new Expression(
            new DefaultValue(new SymbolSource('premium'), $default),
            declarations: ['premium' => new OptionType(new MoneyType('GBP'))],
        ))->compile()->unwrap();

        $this->assertEquals(new MoneyType('GBP'), $program->returns);
        $this->assertSame($default, $program([])->unwrap()->unwrap());
    }

    #[Test]
    public function it_reifies_the_present_type_of_a_host_projected_option(): void
    {
        $program = (new Expression(
            new DefaultValue(new SymbolSource('amount'), '3'),
            declarations: ['amount' => new ProjectedNumberOptionType()],
        ))->compile()->unwrap();

        $this->assertInstanceOf(NumberType::class, $program->returns);
        $this->assertSame(3, $program([])->unwrap()->unwrap());
    }

    #[Test]
    public function it_is_the_typed_identity_for_a_total_source(): void
    {
        $program = (new Expression(new DefaultValue(new StaticSource(4), 'not-a-number')))->compile()->unwrap();

        $this->assertSame(4, $program()->unwrap()->unwrap());
    }

    #[Test]
    public function it_rejects_a_default_that_cannot_be_coerced_to_the_present_type(): void
    {
        $result = (new Expression(
            new DefaultValue(new SymbolSource('amount'), 'not-a-number'),
            declarations: ['amount' => new OptionType(new NumberType())],
        ))->compile();

        $this->assertStringContainsString(
            'The default value cannot be coerced to Number',
            $result->unwrapErr()->describe(),
        );
    }

    #[Test]
    public function it_rejects_a_default_that_itself_reads_as_missing(): void
    {
        $result = (new Expression(
            new DefaultValue(new SymbolSource('name'), ''),
            declarations: ['name' => new OptionType(new StringType())],
        ))->compile();

        $this->assertStringContainsString(
            'The default value reads as missing, but a present String is required.',
            $result->unwrapErr()->describe(),
        );
    }

    #[Test]
    public function it_rejects_a_default_when_the_optional_source_has_no_present_inhabitants(): void
    {
        $result = (new Expression(new DefaultValue(new StaticSource(null), 0)))->compile();

        $this->assertStringContainsString(
            'The default value cannot be coerced to Never',
            $result->unwrapErr()->describe(),
        );
    }

    #[Test]
    public function it_still_labels_the_node_when_its_child_fails_during_evaluation(): void
    {
        $observer = new SpyObserver();
        $program = (new Expression(new DefaultValue(
            new Coerce(new OptionType(new NumberType()), new StaticSource('not-a-number')),
            0,
        )))->compile()->unwrap();

        $this->assertTrue($program->call(observer: $observer)->isErr());
        $this->assertSame(['label', 'default'], $observer->timeline[array_key_last($observer->timeline)]);
    }
}
