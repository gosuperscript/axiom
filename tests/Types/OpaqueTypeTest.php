<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\OpaqueType;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\OpaqueShape;

#[CoversClass(OpaqueType::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(OpaqueShape::class)]
final class OpaqueTypeTest extends TestCase
{
    #[Test]
    public function it_is_dynamically_unverifiable_by_core(): void
    {
        $type = new OpaqueType('ClaimId');

        $this->assertSame('abc-123', $type->assert('abc-123')->unwrap()->unwrap());
        $this->assertSame(42, $type->coerce(42)->unwrap()->unwrap());
        $this->assertTrue($type->assert(null)->unwrap()->isNone());
    }

    #[Test]
    public function it_compares_strictly_and_formats_via_export(): void
    {
        $type = new OpaqueType('ClaimId');

        $this->assertTrue($type->compare('a', 'a'));
        $this->assertFalse($type->compare('a', 'b'));
        $this->assertSame("'a'", $type->format('a'));
    }

    #[Test]
    public function it_projects_to_a_parameterized_opaque_shape(): void
    {
        $shape = (new OpaqueType('money', ['currency' => new LiteralType('GBP')]))->shape();

        $this->assertInstanceOf(OpaqueShape::class, $shape);
        $this->assertSame('money', $shape->identity);
        $this->assertTrue($shape->parameters['currency']->equals(new LiteralShape('GBP')));
    }
}
