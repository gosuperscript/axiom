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
#[\PHPUnit\Framework\Attributes\UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
final class OpaqueTypeTest extends TestCase
{
    #[Test]
    public function it_is_fail_closed_because_core_cannot_verify_membership(): void
    {
        $type = new OpaqueType('ClaimId');

        // A fail-open placeholder would claim every non-null value for any
        // signature or boundary declared over it; the honest posture for an
        // unverifiable identity is to reject everything, loudly.
        $this->assertStringContainsString(
            'Core cannot verify membership of opaque identity [ClaimId]',
            $type->assert('abc-123')->unwrapErr()->getMessage(),
        );
        $this->assertStringContainsString(
            'the host that owns the identity owns the membership check',
            $type->coerce(42)->unwrapErr()->getMessage(),
        );
        $this->assertTrue($type->assert(null)->isErr());
    }

    #[Test]
    public function it_formats_via_export(): void
    {
        $type = new OpaqueType('ClaimId');

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
