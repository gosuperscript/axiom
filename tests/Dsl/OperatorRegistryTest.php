<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Dsl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\Dsl\Associativity;
use Superscript\Axiom\Dsl\OperatorEntry;
use Superscript\Axiom\Dsl\OperatorPosition;
use Superscript\Axiom\Dsl\OperatorRegistry;

#[CoversClass(OperatorRegistry::class)]
#[UsesClass(OperatorEntry::class)]
#[UsesClass(Associativity::class)]
#[UsesClass(OperatorPosition::class)]
class OperatorRegistryTest extends TestCase
{
    #[Test]
    public function it_registers_and_retrieves_an_operator(): void
    {
        $registry = new OperatorRegistry();
        $registry->register('+', 50, Associativity::Left);

        $entry = $registry->get('+');

        $this->assertNotNull($entry);
        $this->assertSame('+', $entry->symbol);
        $this->assertSame(50, $entry->precedence);
        $this->assertSame(Associativity::Left, $entry->associativity);
        $this->assertSame(OperatorPosition::Infix, $entry->position);
        $this->assertFalse($entry->isKeyword);
    }

    #[Test]
    public function it_returns_null_for_unknown_operator(): void
    {
        $registry = new OperatorRegistry();

        $this->assertNull($registry->get('??'));
    }

    #[Test]
    public function it_checks_is_operator(): void
    {
        $registry = new OperatorRegistry();
        $registry->register('+', 50, Associativity::Left);

        $this->assertTrue($registry->isOperator('+'));
        $this->assertFalse($registry->isOperator('-'));
    }

    #[Test]
    public function it_checks_is_keyword_operator(): void
    {
        $registry = new OperatorRegistry();
        $registry->register('in', 40, Associativity::Left, isKeyword: true);
        $registry->register('+', 50, Associativity::Left);

        $this->assertTrue($registry->isKeywordOperator('in'));
        $this->assertFalse($registry->isKeywordOperator('+'));
        $this->assertFalse($registry->isKeywordOperator('unknown'));
    }

    #[Test]
    public function it_returns_all_operators(): void
    {
        $registry = new OperatorRegistry();
        $registry->register('+', 50, Associativity::Left);
        $registry->register('*', 60, Associativity::Left);

        $all = $registry->all();

        $this->assertCount(2, $all);
        $this->assertArrayHasKey('+', $all);
        $this->assertArrayHasKey('*', $all);
    }

    #[Test]
    public function it_returns_operators_sorted_by_precedence(): void
    {
        $registry = new OperatorRegistry();
        $registry->register('*', 60, Associativity::Left);
        $registry->register('||', 10, Associativity::Left);
        $registry->register('+', 50, Associativity::Left);

        $sorted = $registry->byPrecedence();

        $this->assertSame('||', $sorted[0]->symbol);
        $this->assertSame('+', $sorted[1]->symbol);
        $this->assertSame('*', $sorted[2]->symbol);
    }

    #[Test]
    public function it_allows_idempotent_re_registration_at_same_precedence(): void
    {
        $registry = new OperatorRegistry();
        $registry->register('+', 50, Associativity::Left);
        $registry->register('+', 50, Associativity::Left);

        $this->assertNotNull($registry->get('+'));
    }

    #[Test]
    public function it_throws_when_re_registering_at_different_precedence(): void
    {
        $registry = new OperatorRegistry();
        $registry->register('+', 50, Associativity::Left);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Operator '+' is already registered at precedence 50");

        $registry->register('+', 60, Associativity::Left);
    }

    #[Test]
    public function it_registers_prefix_operator(): void
    {
        $registry = new OperatorRegistry();
        $registry->register('!', 70, Associativity::Right, OperatorPosition::Prefix);

        $entry = $registry->get('!');

        $this->assertNotNull($entry);
        $this->assertSame(OperatorPosition::Prefix, $entry->position);
        $this->assertSame(Associativity::Right, $entry->associativity);
    }
}
