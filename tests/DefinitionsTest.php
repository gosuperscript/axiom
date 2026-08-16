<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;

#[CoversClass(Definitions::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
final class DefinitionsTest extends TestCase
{
    #[Test]
    public function it_rejects_non_source_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Every definition must be a Source instance.');

        new Definitions(['test' => 42]);
    }

    #[Test]
    public function definition_names_cannot_encode_access_paths(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('root symbol name without dots');

        new Definitions(['math.pi' => new StaticSource(3.14)]);
    }

    #[Test]
    public function definition_names_must_be_non_empty_strings(): void
    {
        foreach ([[new StaticSource(1)], ['' => new StaticSource(1)]] as $definitions) {
            try {
                new Definitions($definitions);
                $this->fail('The invalid definition name should have been rejected.');
            } catch (InvalidArgumentException $error) {
                $this->assertStringContainsString('root symbol name', $error->getMessage());
            }
        }
    }

    #[Test]
    public function it_returns_a_source_without_a_namespace(): void
    {
        $definitions = new Definitions([
            'A' => new StaticSource(1),
            'B' => new StaticSource(2),
        ]);

        $result = $definitions->get('A');
        $this->assertTrue($result->isSome());
        $this->assertInstanceOf(StaticSource::class, $result->unwrap());
        $this->assertSame(1, $result->unwrap()->value);
    }

    #[Test]
    public function keys_lists_every_root_definition(): void
    {
        $definitions = new Definitions([
            'rate' => new StaticSource(1.2),
            'customer' => new StaticSource(['turnover' => 2]),
        ]);

        $this->assertSame(['rate', 'customer'], $definitions->keys());
    }

    #[Test]
    public function it_returns_none_for_missing_names(): void
    {
        $definitions = new Definitions(['A' => new StaticSource(1)]);

        $this->assertTrue($definitions->get('B')->isNone());
    }

    #[Test]
    public function structured_values_are_owned_by_one_root_definition(): void
    {
        $definitions = new Definitions([
            'math' => new StaticSource(['pi' => 3.14, 'e' => 2.71]),
        ]);

        $this->assertSame(['pi' => 3.14, 'e' => 2.71], $definitions->get('math')->unwrap()->value);
    }

    #[Test]
    public function root_entries_are_isolated(): void
    {
        $definitions = new Definitions([
            'value' => new StaticSource(1),
            'other' => new StaticSource(2),
        ]);

        $this->assertSame(1, $definitions->get('value')->unwrap()->value);
        $this->assertSame(2, $definitions->get('other')->unwrap()->value);
    }

    #[Test]
    public function a_structural_array_is_not_a_definition_map(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Definitions([
            'math' => ['pi' => new StaticSource(3.14)],
        ]);
    }

    #[Test]
    public function has_reports_presence(): void
    {
        $definitions = new Definitions([
            'A' => new StaticSource(1),
            'pi' => new StaticSource(3.14),
        ]);

        $this->assertTrue($definitions->has('A'));
        $this->assertFalse($definitions->has('B'));
        $this->assertTrue($definitions->has('pi'));
    }

    #[Test]
    public function default_constructor_has_no_entries(): void
    {
        $definitions = new Definitions();

        $this->assertFalse($definitions->has('anything'));
        $this->assertTrue($definitions->get('anything')->isNone());
    }
}
