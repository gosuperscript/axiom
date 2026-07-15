<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Types\NeverType;
use Superscript\Axiom\Types\Shapes\NeverShape;

#[CoversClass(NeverType::class)]
#[UsesClass(TransformValueException::class)]
#[UsesClass(NeverShape::class)]
final class NeverTypeTest extends TestCase
{
    #[Test]
    public function no_value_inhabits_it(): void
    {
        $type = new NeverType();

        $this->assertTrue($type->assert(5)->isErr());
        $this->assertTrue($type->assert(null)->isErr());
        $this->assertTrue($type->coerce('anything')->isErr());
    }

    #[Test]
    public function nothing_compares_equal(): void
    {
        $this->assertFalse((new NeverType())->compare(1, 1));
    }

    #[Test]
    public function it_formats_to_nothing(): void
    {
        $this->assertSame('', (new NeverType())->format(1));
    }

    #[Test]
    public function it_projects_to_the_never_shape(): void
    {
        $this->assertInstanceOf(NeverShape::class, (new NeverType())->shape());
    }
}
