<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\ReferencePath;

#[CoversClass(ReferencePath::class)]
final class ReferencePathTest extends TestCase
{
    #[Test]
    public function it_models_a_root_followed_by_structural_properties(): void
    {
        $root = new ReferencePath('customer');
        $path = $root->append('address')->append('postcode');

        $this->assertSame(['customer'], $root->segments);
        $this->assertTrue($root->isRoot());
        $this->assertSame('customer', $path->root());
        $this->assertSame(['address', 'postcode'], $path->properties());
        $this->assertFalse($path->isRoot());
        $this->assertSame('customer.address.postcode', $path->describe());
        $this->assertSame([
            'root' => 'customer',
            'properties' => ['address', 'postcode'],
        ], $path->jsonSerialize());
        $this->assertSame('{"root":"customer","properties":["address","postcode"]}', json_encode($path));
        $this->assertNotSame($root->key(), $path->key());
    }

    #[Test]
    public function names_must_be_non_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReferencePath('customer', '');
    }

    #[Test]
    public function the_root_must_be_non_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ReferencePath('');
    }

    #[Test]
    public function dots_are_reserved_for_describing_structural_access(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Dots describe structural access between segments.');

        new ReferencePath('customer', 'address.postcode');
    }
}
