<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psl\Type\Exception\AssertException;
use Superscript\Schema\Sources\StaticSource;
use Superscript\Schema\SymbolRegistry;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymbolRegistry::class)]
final class SymbolRegistryTest extends TestCase
{
    #[Test]
    public function it_must_be_created_with_sources(): void
    {
        $this->expectException(AssertException::class);

        (new SymbolRegistry([
            ['name' => 'test', 'namespace' => null, 'source' => 42],
        ]));
    }

    #[Test]
    public function it_can_get_a_symbol_without_namespace(): void
    {
        $registry = new SymbolRegistry([
            ['name' => 'A', 'namespace' => null, 'source' => new StaticSource(1)],
            ['name' => 'B', 'namespace' => null, 'source' => new StaticSource(2)],
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
            ['name' => 'A', 'namespace' => null, 'source' => new StaticSource(1)],
        ]);

        $result = $registry->get('B');
        $this->assertTrue($result->isNone());
    }

    #[Test]
    public function it_can_get_a_namespaced_symbol(): void
    {
        $registry = new SymbolRegistry([
            ['name' => 'pi', 'namespace' => 'math', 'source' => new StaticSource(3.14)],
            ['name' => 'e', 'namespace' => 'math', 'source' => new StaticSource(2.71)],
            ['name' => 'c', 'namespace' => 'constants', 'source' => new StaticSource(299792458)],
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
            ['name' => 'pi', 'namespace' => 'math', 'source' => new StaticSource(3.14)],
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
            ['name' => 'value', 'namespace' => null, 'source' => new StaticSource(1)],
            ['name' => 'value', 'namespace' => 'ns', 'source' => new StaticSource(2)],
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
            ['name' => 'level2.value', 'namespace' => 'level1', 'source' => new StaticSource(42)],
        ]);

        $result = $registry->get('level2.value', 'level1');
        $this->assertTrue($result->isSome());
        $this->assertEquals(42, $result->unwrap()->value);
    }
}
