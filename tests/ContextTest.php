<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Bindings;
use Superscript\Axiom\Context;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Tests\Resolvers\Fixtures\SpyInspector;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

#[CoversClass(Context::class)]
#[UsesClass(Bindings::class)]
#[UsesClass(Definitions::class)]
final class ContextTest extends TestCase
{
    #[Test]
    public function defaults_to_empty_bindings_definitions_and_no_inspector(): void
    {
        $context = new Context();

        $this->assertFalse($context->bindings->has('anything'));
        $this->assertFalse($context->definitions->has('anything'));
        $this->assertNull($context->inspector);
    }

    #[Test]
    public function memoize_symbol_stores_and_retrieves_results(): void
    {
        $context = new Context();
        $result = Ok(Some(42));

        $this->assertFalse($context->hasMemoizedSymbol('A'));

        $context->memoizeSymbol('A', $result);

        $this->assertTrue($context->hasMemoizedSymbol('A'));
        $this->assertSame($result, $context->getMemoizedSymbol('A'));
    }

    #[Test]
    public function memo_is_keyed_per_symbol(): void
    {
        $context = new Context();
        $resultA = Ok(Some(1));
        $resultB = Ok(Some(2));

        $context->memoizeSymbol('A', $resultA);
        $context->memoizeSymbol('B', $resultB);

        $this->assertSame($resultA, $context->getMemoizedSymbol('A'));
        $this->assertSame($resultB, $context->getMemoizedSymbol('B'));
    }

    #[Test]
    public function it_exposes_its_inspector(): void
    {
        $inspector = new SpyInspector();
        $context = new Context(inspector: $inspector);

        $this->assertSame($inspector, $context->inspector);
    }
}
