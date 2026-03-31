<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Dsl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dsl\FunctionEntry;
use Superscript\Axiom\Dsl\FunctionParam;
use Superscript\Axiom\Dsl\FunctionRegistry;

#[CoversClass(FunctionRegistry::class)]
#[CoversClass(FunctionEntry::class)]
#[CoversClass(FunctionParam::class)]
class FunctionRegistryTest extends TestCase
{
    #[Test]
    public function it_registers_and_resolves_a_function(): void
    {
        $registry = new FunctionRegistry();
        $registry->register('abs', [new FunctionParam('value', 'number')], fn(mixed $v) => abs((int) $v));

        $entry = $registry->resolve('abs');

        $this->assertNotNull($entry);
        $this->assertSame('abs', $entry->name);
        $this->assertCount(1, $entry->params);
        $this->assertSame('value', $entry->params[0]->name);
        $this->assertSame('number', $entry->params[0]->type);
        $this->assertFalse($entry->params[0]->optional);
    }

    #[Test]
    public function it_checks_has(): void
    {
        $registry = new FunctionRegistry();
        $registry->register('abs', [], fn() => null);

        $this->assertTrue($registry->has('abs'));
        $this->assertFalse($registry->has('unknown'));
    }

    #[Test]
    public function it_returns_all_functions(): void
    {
        $registry = new FunctionRegistry();
        $registry->register('abs', [], fn() => null);
        $registry->register('round', [], fn() => null);

        $all = $registry->all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('abs', $all);
        $this->assertArrayHasKey('round', $all);
    }

    #[Test]
    public function it_returns_null_for_unknown_function(): void
    {
        $registry = new FunctionRegistry();

        $this->assertNull($registry->resolve('unknown'));
    }
}
