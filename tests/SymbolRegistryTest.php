<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use Psl\Type\Exception\AssertException;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\SymbolRegistry;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymbolRegistry::class)]
#[UsesClass(StaticSource::class)]
final class SymbolRegistryTest extends TestCase
{
    #[Test]
    public function it_must_be_created_with_sources(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Symbol values must be either Source instances or arrays of Sources');

        (new SymbolRegistry([
            'test' => 42,
        ]));
    }

    #[Test]
    public function it_can_get_a_symbol_without_namespace(): void
    {
        $registry = new SymbolRegistry([
            'A' => new StaticSource(1),
            'B' => new StaticSource(2),
        ]);

        $result = $registry->get('A');
        $this->assertTrue($result->isSome());
        $this->assertInstanceOf(StaticSource::class, $result->unwrap());
        $this->assertEquals(1, $result->unwrap()->value);
    }

    #[Test]
    public function it_returns_none_for_nonexistent_symbol(): void
    {
        $registry = new SymbolRegistry([
            'A' => new StaticSource(1),
        ]);

        $result = $registry->get('B');
        $this->assertTrue($result->isNone());
    }

    #[Test]
    public function it_can_get_a_namespaced_symbol(): void
    {
        $registry = new SymbolRegistry([
            'math' => [
                'pi' => new StaticSource(3.14),
                'e' => new StaticSource(2.71),
            ],
            'constants' => [
                'c' => new StaticSource(299792458),
            ],
        ]);

        $result = $registry->get('pi', 'math');
        $this->assertTrue($result->isSome());
        $this->assertEquals(3.14, $result->unwrap()->value);

        $result = $registry->get('e', 'math');
        $this->assertTrue($result->isSome());
        $this->assertEquals(2.71, $result->unwrap()->value);

        $result = $registry->get('c', 'constants');
        $this->assertTrue($result->isSome());
        $this->assertEquals(299792458, $result->unwrap()->value);
    }

    #[Test]
    public function it_returns_none_for_nonexistent_namespaced_symbol(): void
    {
        $registry = new SymbolRegistry([
            'math' => [
                'pi' => new StaticSource(3.14),
            ],
        ]);

        // Wrong namespace
        $result = $registry->get('pi', 'physics');
        $this->assertTrue($result->isNone());

        // Wrong name in correct namespace
        $result = $registry->get('e', 'math');
        $this->assertTrue($result->isNone());
    }

    #[Test]
    public function it_distinguishes_between_namespaced_and_non_namespaced_symbols(): void
    {
        $registry = new SymbolRegistry([
            'value' => new StaticSource(1),
            'ns' => [
                'value' => new StaticSource(2),
            ],
        ]);

        // Getting without namespace should return the non-namespaced symbol
        $result = $registry->get('value');
        $this->assertTrue($result->isSome());
        $this->assertEquals(1, $result->unwrap()->value);

        // Getting with namespace should return the namespaced symbol
        $result = $registry->get('value', 'ns');
        $this->assertTrue($result->isSome());
        $this->assertEquals(2, $result->unwrap()->value);
    }

    #[Test]
    public function it_supports_nested_namespace_keys(): void
    {
        $registry = new SymbolRegistry([
            'level1' => [
                'level2.value' => new StaticSource(42),
            ],
        ]);

        $result = $registry->get('level2.value', 'level1');
        $this->assertTrue($result->isSome());
        $this->assertEquals(42, $result->unwrap()->value);
    }

    #[Test]
    public function it_returns_null_label_when_no_labels_are_set(): void
    {
        $registry = new SymbolRegistry([
            'A' => new StaticSource(1),
        ]);

        $this->assertNull($registry->getLabel('A'));
    }

    #[Test]
    public function it_returns_the_label_when_set(): void
    {
        $registry = new SymbolRegistry(
            ['A' => new StaticSource(1)],
            ['A' => 'The A variable'],
        );

        $this->assertSame('The A variable', $registry->getLabel('A'));
    }

    #[Test]
    public function it_returns_null_label_for_unknown_names(): void
    {
        $registry = new SymbolRegistry(
            ['A' => new StaticSource(1)],
            ['A' => 'The A variable'],
        );

        $this->assertNull($registry->getLabel('B'));
    }

    #[Test]
    public function it_returns_the_label_for_namespaced_symbol(): void
    {
        $registry = new SymbolRegistry(
            ['math' => ['pi' => new StaticSource(3.14)]],
            ['math.pi' => 'The ratio of circumference to diameter'],
        );

        $this->assertSame('The ratio of circumference to diameter', $registry->getLabel('pi', 'math'));
    }

    #[Test]
    public function it_must_validate_namespaced_array_contains_only_sources(): void
    {
        $this->expectException(AssertException::class);

        (new SymbolRegistry([
            'math' => [
                'pi' => new StaticSource(3.14),
                'invalid' => 42,  // Invalid: not a Source instance
            ],
        ]));
    }
}
