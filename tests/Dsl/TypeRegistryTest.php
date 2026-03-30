<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Dsl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\Dsl\TypeRegistry;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;

#[CoversClass(TypeRegistry::class)]
class TypeRegistryTest extends TestCase
{
    #[Test]
    public function it_registers_and_resolves_a_type(): void
    {
        $registry = new TypeRegistry();
        $registry->register('number', fn() => new NumberType());

        $type = $registry->resolve('number');

        $this->assertInstanceOf(NumberType::class, $type);
    }

    #[Test]
    public function it_resolves_a_type_with_args(): void
    {
        $registry = new TypeRegistry();
        $registry->register('string', fn(mixed ...$args) => new StringType());

        $type = $registry->resolve('string', 'extra');

        $this->assertInstanceOf(StringType::class, $type);
    }

    #[Test]
    public function it_checks_has(): void
    {
        $registry = new TypeRegistry();
        $registry->register('number', fn() => new NumberType());

        $this->assertTrue($registry->has('number'));
        $this->assertFalse($registry->has('unknown'));
    }

    #[Test]
    public function it_returns_all_factories(): void
    {
        $registry = new TypeRegistry();
        $registry->register('number', fn() => new NumberType());
        $registry->register('string', fn() => new StringType());

        $all = $registry->all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('number', $all);
        $this->assertArrayHasKey('string', $all);
    }

    #[Test]
    public function it_throws_for_unknown_type(): void
    {
        $registry = new TypeRegistry();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unknown type 'foo'");

        $registry->resolve('foo');
    }
}
