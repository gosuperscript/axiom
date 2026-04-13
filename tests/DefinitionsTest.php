<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Psl\Type\Exception\AssertException;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Sources\StaticSource;

#[CoversClass(Definitions::class)]
#[UsesClass(StaticSource::class)]
final class DefinitionsTest extends TestCase
{
    #[Test]
    public function it_rejects_non_source_values(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Definition values must be either Source instances or arrays of Sources');

        new Definitions(['test' => 42]);
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
    public function it_returns_none_for_missing_names(): void
    {
        $definitions = new Definitions(['A' => new StaticSource(1)]);

        $this->assertTrue($definitions->get('B')->isNone());
    }

    #[Test]
    public function it_returns_a_namespaced_source(): void
    {
        $definitions = new Definitions([
            'math' => [
                'pi' => new StaticSource(3.14),
                'e' => new StaticSource(2.71),
            ],
        ]);

        $this->assertSame(3.14, $definitions->get('pi', 'math')->unwrap()->value);
        $this->assertSame(2.71, $definitions->get('e', 'math')->unwrap()->value);
    }

    #[Test]
    public function namespaced_and_non_namespaced_entries_are_isolated(): void
    {
        $definitions = new Definitions([
            'value' => new StaticSource(1),
            'ns' => ['value' => new StaticSource(2)],
        ]);

        $this->assertSame(1, $definitions->get('value')->unwrap()->value);
        $this->assertSame(2, $definitions->get('value', 'ns')->unwrap()->value);
    }

    #[Test]
    public function namespaced_array_must_contain_only_sources(): void
    {
        $this->expectException(AssertException::class);

        new Definitions([
            'math' => [
                'pi' => new StaticSource(3.14),
                'invalid' => 42,
            ],
        ]);
    }

    #[Test]
    public function has_reports_presence(): void
    {
        $definitions = new Definitions([
            'A' => new StaticSource(1),
            'math' => ['pi' => new StaticSource(3.14)],
        ]);

        $this->assertTrue($definitions->has('A'));
        $this->assertFalse($definitions->has('B'));
        $this->assertTrue($definitions->has('pi', 'math'));
        $this->assertFalse($definitions->has('pi', 'physics'));
    }

    #[Test]
    public function default_constructor_has_no_entries(): void
    {
        $definitions = new Definitions();

        $this->assertFalse($definitions->has('anything'));
        $this->assertTrue($definitions->get('anything')->isNone());
    }
}
