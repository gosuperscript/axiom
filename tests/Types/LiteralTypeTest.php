<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Exceptions\TransformValueException;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\BooleanShape;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\StringShape;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\TypeDescriber;

#[CoversClass(LiteralType::class)]
#[UsesClass(TransformValueException::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(TypeDescriber::class)]
#[UsesClass(LiteralShape::class)]
#[UsesClass(BooleanShape::class)]
#[UsesClass(NumberShape::class)]
#[UsesClass(StringShape::class)]
final class LiteralTypeTest extends TestCase
{
    #[Test]
    public function it_admits_only_its_value(): void
    {
        $shop = new LiteralType('shop');

        $this->assertSame('shop', $shop->assert('shop')->unwrap()->unwrap());
        $this->assertTrue($shop->assert('office')->isErr());
        $this->assertTrue($shop->assert(5)->isErr());
    }

    #[Test]
    public function string_identity_is_strict_even_for_numeric_strings(): void
    {
        $literal = new LiteralType('1000');

        $this->assertTrue($literal->assert('1e3')->isErr());
        $this->assertSame('1000', $literal->assert('1000')->unwrap()->unwrap());
    }

    #[Test]
    public function numeric_identity_is_loose(): void
    {
        $five = new LiteralType(5);

        $this->assertSame(5, $five->assert(5)->unwrap()->unwrap());
        $this->assertSame(5.0, $five->assert(5.0)->unwrap()->unwrap());
        $this->assertTrue($five->assert(6)->isErr());
    }

    #[Test]
    public function boolean_identity_is_strict(): void
    {
        $true = new LiteralType(true);

        $this->assertTrue($true->assert(true)->unwrap()->unwrap());
        $this->assertTrue($true->assert(false)->isErr());
    }

    #[Test]
    public function it_coerces_through_its_base_then_refines(): void
    {
        $five = new LiteralType(5);

        $this->assertSame(5, $five->coerce('5')->unwrap()->unwrap());
        $this->assertTrue($five->coerce('6')->isErr());
        $this->assertTrue($five->coerce('not a number')->isErr());
    }

    #[Test]
    public function an_absence_reading_passes_through_coercion(): void
    {
        $shop = new LiteralType('shop');

        $this->assertTrue($shop->coerce('')->unwrap()->isNone());
    }

    #[Test]
    public function it_compares_and_formats_through_its_base(): void
    {
        $shop = new LiteralType('shop');

        $this->assertTrue($shop->compare('shop', 'shop'));
        $this->assertFalse($shop->compare('shop', 'office'));
        $this->assertSame('shop', $shop->format('shop'));
        $this->assertSame('5', (new LiteralType(5))->format(5));
    }

    #[Test]
    public function it_projects_to_a_literal_shape(): void
    {
        $shape = (new LiteralType('shop'))->shape();

        $this->assertInstanceOf(LiteralShape::class, $shape);
        $this->assertSame('shop', $shape->value);
    }
}
