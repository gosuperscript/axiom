<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Superscript\Axiom\Operators\BinaryOverloader;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\NullOverloader;
use Superscript\Axiom\Operators\OverloaderManager;
use Webmozart\Assert\InvalidArgumentException;

#[CoversClass(OverloaderManager::class)]
#[UsesClass(DefaultOverloader::class)]
#[UsesClass(BinaryOverloader::class)]
#[UsesClass(NullOverloader::class)]
class OverloaderManagerTest extends TestCase
{
    #[Test]
    public function it_asserts_all_overloaders_are_instance_of_interface(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new OverloaderManager([new stdClass()]);
    }

    #[Test]
    public function it_evaluates_an_expression_if_an_overloader_is_found(): void
    {
        $manager = new OverloaderManager([
            new DefaultOverloader(),
        ]);

        $this->assertTrue($manager->supportsOverloading(1, 1, '+'));

        $result = $manager->evaluate(1, 1, '+');
        $this->assertTrue($result->isOk());
        $this->assertEquals(2, $result->unwrap());
    }

    #[Test]
    public function it_returns_an_error_if_no_supported_overloader_is_found(): void
    {
        $manager = new OverloaderManager([]);
        $this->assertFalse($manager->supportsOverloading(1, 1, '+'));

        $result = $manager->evaluate(1, 1, '+');
        $this->assertTrue($result->isErr());
        $this->assertInstanceOf(RuntimeException::class, $result->unwrapErr());
        $this->assertEquals('No overloader found for [1] + [1]', $result->unwrapErr()->getMessage());
    }
}
