<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Types\Shapes\UnknownShape;
use Superscript\Axiom\Types\UnknownType;

#[CoversClass(UnknownType::class)]
#[UsesClass(UnknownShape::class)]
final class UnknownTypeTest extends TestCase
{
    #[Test]
    public function it_admits_any_present_value(): void
    {
        $type = new UnknownType();

        $this->assertSame(5, $type->assert(5)->unwrap()->unwrap());
        $this->assertSame('a', $type->coerce('a')->unwrap()->unwrap());
    }

    #[Test]
    public function it_reads_null_as_absence(): void
    {
        $type = new UnknownType();

        $this->assertTrue($type->assert(null)->unwrap()->isNone());
        $this->assertTrue($type->coerce(null)->unwrap()->isNone());
    }

    #[Test]
    public function it_compares_strictly(): void
    {
        $type = new UnknownType();

        $this->assertTrue($type->compare(5, 5));
        $this->assertFalse($type->compare(5, '5'));
    }

    #[Test]
    public function it_formats_via_export(): void
    {
        $this->assertSame("'a'", (new UnknownType())->format('a'));
    }

    #[Test]
    public function it_projects_to_the_unknown_shape(): void
    {
        $this->assertInstanceOf(UnknownShape::class, (new UnknownType())->shape());
    }
}
