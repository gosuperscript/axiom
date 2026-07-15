<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types\Shapes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Types\Shapes\DictShape;
use Superscript\Axiom\Types\Shapes\ListShape;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\RecordShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\ShapeDomain;
use Superscript\Axiom\Types\Shapes\StringShape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Axiom\Types\Shapes\UnknownShape;

#[CoversClass(ShapeDomain::class)]
#[UsesClass(ListShape::class)]
#[UsesClass(LiteralShape::class)]
#[UsesClass(OptionShape::class)]
#[UsesClass(UnionShape::class)]
#[UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
final class ShapeDomainTest extends TestCase
{
    /**
     * The leaf predicate every core caller uses: objects are unclaimed.
     */
    private static function noOpaques(): callable
    {
        return fn(Shape $leaf) => !$leaf instanceof OpaqueShape;
    }

    #[Test]
    #[DataProvider('cases')]
    public function it_quantifies_universally_over_the_sealed_algebra(Shape $shape, bool $expected): void
    {
        $this->assertSame($expected, ShapeDomain::all($shape, self::noOpaques()));
    }

    #[Test]
    public function unknown_and_never_are_the_traversals_own_law(): void
    {
        // Unknown FAILS without consulting the leaf — its values are
        // unknowable, so no total claim over them is possible (inert
        // Unknown has no gradual hole); Never passes vacuously — even a
        // leaf that accepts everything cannot claim Unknown, and one that
        // refuses everything cannot veto Never.
        $acceptEverything = fn(Shape $leaf) => true;
        $refuseEverything = fn(Shape $leaf) => false;

        $this->assertFalse(ShapeDomain::all(new UnknownShape(), $acceptEverything));
        $this->assertTrue(ShapeDomain::all(new NeverShape(), $refuseEverything));
        $this->assertFalse(ShapeDomain::all(new NumberShape(), $refuseEverything), 'ordinary leaves do consult the predicate');
    }

    public static function cases(): \Generator
    {
        yield 'a scalar leaf passes' => [new NumberShape(), true];
        yield 'a literal leaf passes' => [new LiteralShape(5), true];
        yield 'an opaque leaf is refused' => [new OpaqueShape('money'), false];

        yield 'Unknown fails — inert, no total claim is possible' => [new UnknownShape(), false];
        yield 'an Unknown buried in a union fails the whole union' => [UnionShape::of(new NumberShape(), new UnknownShape()), false];
        yield 'Never passes vacuously' => [new NeverShape(), true];

        yield 'an option is transparent' => [new OptionShape(new NumberShape()), true];
        yield 'an option over an opaque is refused' => [new OptionShape(new OpaqueShape('money')), false];

        yield 'a union needs EVERY member claimed' => [UnionShape::of(new NumberShape(), new OpaqueShape('money')), false];
        yield 'a fully-claimed union passes' => [UnionShape::of(new NumberShape(), new StringShape()), true];

        yield 'containers recurse element-wise: list' => [new ListShape(new OpaqueShape('money')), false];
        yield 'a statically empty list never observes its element' => [new ListShape(new OpaqueShape('money'), max: 0), true];
        yield 'a possibly non-empty list still observes its element' => [new ListShape(new OpaqueShape('money'), max: 1), false];
        yield 'containers recurse element-wise: dict' => [new DictShape(new OpaqueShape('money')), false];
        yield 'containers recurse element-wise: record' => [new RecordShape(['price' => new OpaqueShape('money')]), false];
        yield 'a record of claimed fields passes' => [new RecordShape(['n' => new NumberShape()]), true];
    }
}
