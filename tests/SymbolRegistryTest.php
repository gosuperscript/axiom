<?php

declare(strict_types=1);

namespace Superscript\Abacus\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psl\Type\Exception\AssertException;
use Superscript\Abacus\SymbolRegistry;
use PHPUnit\Framework\TestCase;

#[CoversClass(SymbolRegistry::class)]
final class SymbolRegistryTest extends TestCase
{
    #[Test]
    public function it_must_be_created_with_sources(): void
    {
        $this->expectException(AssertException::class);

        (new SymbolRegistry([
            'test' => 42,
        ]));
    }
}
