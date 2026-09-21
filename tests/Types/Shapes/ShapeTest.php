<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types\Shapes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Operators\ValueEquality;
use Superscript\Axiom\Types\Shapes\BooleanShape;
use Superscript\Axiom\Types\Shapes\DictShape;
use Superscript\Axiom\Types\Shapes\DistinctShapes;
use Superscript\Axiom\Types\Shapes\ListShape;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\OpaqueShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\RecordShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\StringShape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Axiom\Types\Shapes\UnknownShape;

#[CoversClass(BooleanShape::class)]
#[CoversClass(DistinctShapes::class)]
#[CoversClass(NumberShape::class)]
#[CoversClass(StringShape::class)]
#[CoversClass(LiteralShape::class)]
#[CoversClass(OptionShape::class)]
#[CoversClass(UnionShape::class)]
#[CoversClass(RecordShape::class)]
#[CoversClass(DictShape::class)]
#[CoversClass(ListShape::class)]
#[CoversClass(UnknownShape::class)]
#[CoversClass(NeverShape::class)]
#[CoversClass(OpaqueShape::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
#[CoversClass(\Superscript\Axiom\Types\Shapes\RecordPropertyShape::class)]
final class ShapeTest extends TestCase
{
    #[Test]
    #[DataProvider('equalityCases')]
    public function it_decides_structural_equality(Shape $a, Shape $b, bool $expected): void
    {
        $this->assertSame($expected, $a->equals($b));
        $this->assertSame($expected, $b->equals($a));
    }

    public static function equalityCases(): \Generator
    {
        yield 'boolean = boolean' => [new BooleanShape(), new BooleanShape(), true];
        yield 'number = number' => [new NumberShape(), new NumberShape(), true];
        yield 'string = string' => [new StringShape(), new StringShape(), true];
        yield 'unknown = unknown' => [new UnknownShape(), new UnknownShape(), true];
        yield 'never = never' => [new NeverShape(), new NeverShape(), true];
        yield 'boolean != number' => [new BooleanShape(), new NumberShape(), false];
        yield 'number != string' => [new NumberShape(), new StringShape(), false];
        yield 'string != boolean' => [new StringShape(), new BooleanShape(), false];
        yield 'unknown != never' => [new UnknownShape(), new NeverShape(), false];

        yield 'same string literal' => [new LiteralShape('shop'), new LiteralShape('shop'), true];
        yield 'different string literal' => [new LiteralShape('shop'), new LiteralShape('office'), false];
        yield 'same int literal' => [new LiteralShape(5), new LiteralShape(5), true];
        yield 'int and float literal of the same number' => [new LiteralShape(5), new LiteralShape(5.0), true];
        yield 'different number literal' => [new LiteralShape(5), new LiteralShape(6), false];
        yield 'boolean literal' => [new LiteralShape(true), new LiteralShape(true), true];
        yield 'true is not false' => [new LiteralShape(true), new LiteralShape(false), false];
        yield 'numeric string is not a number literal' => [new LiteralShape('5'), new LiteralShape(5), false];
        yield 'numeric strings compare strictly, not numerically' => [new LiteralShape('1e3'), new LiteralShape('1000'), false];
        yield 'literal is not its base' => [new LiteralShape('shop'), new StringShape(), false];

        yield 'option of same inner' => [new OptionShape(new NumberShape()), new OptionShape(new NumberShape()), true];
        yield 'option of different inner' => [new OptionShape(new NumberShape()), new OptionShape(new StringShape()), false];
        yield 'option is not its inner' => [new OptionShape(new NumberShape()), new NumberShape(), false];

        yield 'unions are order-insensitive' => [
            UnionShape::of(new LiteralShape('a'), new LiteralShape('b')),
            UnionShape::of(new LiteralShape('b'), new LiteralShape('a')),
            true,
        ];
        yield 'unions with different members' => [
            UnionShape::of(new LiteralShape('a'), new LiteralShape('b')),
            UnionShape::of(new LiteralShape('a'), new LiteralShape('c')),
            false,
        ];
        yield 'unions of different size' => [
            UnionShape::of(new LiteralShape('a'), new LiteralShape('b')),
            UnionShape::of(new LiteralShape('a'), new LiteralShape('b'), new LiteralShape('c')),
            false,
        ];
        yield 'union is not a primitive' => [
            UnionShape::of(new NumberShape(), new StringShape()),
            new NumberShape(),
            false,
        ];

        yield 'records are field-order-insensitive' => [
            new RecordShape(['a' => new NumberShape(), 'b' => new StringShape()]),
            new RecordShape(['b' => new StringShape(), 'a' => new NumberShape()]),
            true,
        ];
        yield 'records with different field names' => [
            new RecordShape(['a' => new NumberShape()]),
            new RecordShape(['b' => new NumberShape()]),
            false,
        ];
        yield 'records with different field shapes' => [
            new RecordShape(['a' => new NumberShape()]),
            new RecordShape(['a' => new StringShape()]),
            false,
        ];
        yield 'records with different field counts' => [
            new RecordShape(['a' => new NumberShape()]),
            new RecordShape(['a' => new NumberShape(), 'b' => new StringShape()]),
            false,
        ];
        yield 'record is not a dict' => [
            new RecordShape(['a' => new NumberShape()]),
            new DictShape(new NumberShape()),
            false,
        ];

        yield 'dicts with equal values' => [new DictShape(new NumberShape()), new DictShape(new NumberShape()), true];
        yield 'dicts with different values' => [new DictShape(new NumberShape()), new DictShape(new StringShape()), false];

        yield 'lists with equal element and bounds' => [new ListShape(new NumberShape(), 1, 3), new ListShape(new NumberShape(), 1, 3), true];
        yield 'lists with different elements' => [new ListShape(new NumberShape()), new ListShape(new StringShape()), false];
        yield 'lists with different min' => [new ListShape(new NumberShape(), 1), new ListShape(new NumberShape(), 2), false];
        yield 'lists with different max' => [new ListShape(new NumberShape(), 0, 3), new ListShape(new NumberShape(), 0, null), false];
        yield 'list is not a dict' => [new ListShape(new NumberShape()), new DictShape(new NumberShape()), false];

        yield 'opaque with same identity' => [new OpaqueShape('ClaimId'), new OpaqueShape('ClaimId'), true];
        yield 'opaque with different identity' => [new OpaqueShape('ClaimId'), new OpaqueShape('CatalogueKey'), false];
        yield 'opaque is not a string' => [new OpaqueShape('ClaimId'), new StringShape(), false];
        yield 'parameterized opaques compare parameter-wise' => [
            new OpaqueShape('money', ['currency' => new LiteralShape('GBP')]),
            new OpaqueShape('money', ['currency' => new LiteralShape('GBP')]),
            true,
        ];
        yield 'parameterized opaques with different parameter values' => [
            new OpaqueShape('money', ['currency' => new LiteralShape('GBP')]),
            new OpaqueShape('money', ['currency' => new LiteralShape('USD')]),
            false,
        ];
        yield 'parameterized opaques with different parameter names' => [
            new OpaqueShape('money', ['currency' => new LiteralShape('GBP')]),
            new OpaqueShape('money', ['region' => new LiteralShape('GBP')]),
            false,
        ];
        yield 'a parameterless opaque differs from a parameterized one' => [
            new OpaqueShape('money'),
            new OpaqueShape('money', ['currency' => new LiteralShape('GBP')]),
            false,
        ];
    }

    #[Test]
    public function shapes_expose_their_structure(): void
    {
        $dict = new DictShape(new NumberShape());
        $this->assertInstanceOf(NumberShape::class, $dict->value);

        $list = new ListShape(new StringShape(), 1, 3);
        $this->assertInstanceOf(StringShape::class, $list->element);
        $this->assertSame(1, $list->min);
        $this->assertSame(3, $list->max);

        $plain = new ListShape(new StringShape());
        $this->assertSame(0, $plain->min);
        $this->assertNull($plain->max);

        $record = new RecordShape(['a' => new NumberShape()]);
        $this->assertInstanceOf(NumberShape::class, $record->properties['a']->value);

        $opaque = new OpaqueShape('ClaimId');
        $this->assertSame('ClaimId', $opaque->identity);
    }

    #[Test]
    public function record_property_presence_is_independent_of_value_absence(): void
    {
        $required = new \Superscript\Axiom\Types\Shapes\RecordPropertyShape(new NumberShape(), false);
        $optional = new \Superscript\Axiom\Types\Shapes\RecordPropertyShape(new NumberShape(), true);
        $optionalOption = new \Superscript\Axiom\Types\Shapes\RecordPropertyShape(new OptionShape(new NumberShape()), true);

        $this->assertInstanceOf(NumberShape::class, $required->accessed());
        $this->assertInstanceOf(OptionShape::class, $optional->accessed());
        $this->assertSame($optionalOption->value, $optionalOption->accessed());
        $this->assertTrue($required->equals(new \Superscript\Axiom\Types\Shapes\RecordPropertyShape(new NumberShape(), false)));
        $this->assertFalse($required->equals($optional));
        $this->assertSame($optional, (new RecordShape(['value' => $optional]))->properties['value']);
        $this->assertFalse((new RecordShape(['value' => new NumberShape()]))->properties['value']->optional);
    }

    #[Test]
    public function literal_shapes_derive_their_base(): void
    {
        $this->assertInstanceOf(BooleanShape::class, (new LiteralShape(true))->base);
        $this->assertInstanceOf(StringShape::class, (new LiteralShape('shop'))->base);
        $this->assertInstanceOf(NumberShape::class, (new LiteralShape(5))->base);
        $this->assertInstanceOf(NumberShape::class, (new LiteralShape(5.5))->base);
    }

    #[Test]
    public function value_key_is_pinned_to_its_type_prefixed_format(): void
    {
        // valueKey() is public and documented on its own terms: pin its
        // exact output so a format regression is caught here, directly,
        // rather than surfacing three calls away as a stray bucket
        // collision inside DistinctShapes.
        $true = new LiteralShape(true);
        $false = new LiteralShape(false);
        $shop = new LiteralShape('shop');
        $five = new LiteralShape(5);
        $fiveFloat = new LiteralShape(5.0);
        $zero = new LiteralShape(0.0);
        $negativeZero = new LiteralShape(-0.0);

        $this->assertSame('b:1', $true->valueKey());
        $this->assertSame('b:0', $false->valueKey());
        $this->assertSame('s:shop', $shop->valueKey());
        $this->assertSame('n:' . var_export(5.0, true), $five->valueKey());

        // 5 and 5.0 denote the same Number and must land in the same
        // bucket; both zeroes are one value, whatever their prints.
        $this->assertSame($five->valueKey(), $fiveFloat->valueKey());
        $this->assertSame('n:0', $zero->valueKey());
        $this->assertSame($zero->valueKey(), $negativeZero->valueKey());
    }

    #[Test]
    public function option_nesting_collapses_on_construction(): void
    {
        $nested = new OptionShape(new OptionShape(new NumberShape()));

        $this->assertInstanceOf(NumberShape::class, $nested->inner);
        $this->assertTrue($nested->equals(new OptionShape(new NumberShape())));
    }

    #[Test]
    public function an_empty_union_is_never(): void
    {
        $this->assertInstanceOf(NeverShape::class, UnionShape::of());
    }

    #[Test]
    public function a_single_member_union_is_the_member(): void
    {
        $shape = UnionShape::of(new NumberShape());

        $this->assertInstanceOf(NumberShape::class, $shape);
    }

    #[Test]
    public function unions_flatten_nested_unions(): void
    {
        $shape = UnionShape::of(
            UnionShape::of(new LiteralShape('a'), new LiteralShape('b')),
            new LiteralShape('c'),
        );

        $this->assertTrue($shape->equals(UnionShape::of(new LiteralShape('a'), new LiteralShape('b'), new LiteralShape('c'))));
    }

    #[Test]
    public function unions_deduplicate_members(): void
    {
        $shape = UnionShape::of(new LiteralShape('a'), new LiteralShape('a'), new LiteralShape('b'));

        $this->assertTrue($shape->equals(UnionShape::of(new LiteralShape('a'), new LiteralShape('b'))));
    }

    #[Test]
    public function deduplication_keeps_later_members_of_a_flattened_union(): void
    {
        $shape = UnionShape::of(
            new LiteralShape('a'),
            UnionShape::of(new LiteralShape('a'), new LiteralShape('c')),
        );

        $this->assertTrue($shape->equals(UnionShape::of(new LiteralShape('a'), new LiteralShape('c'))));
    }

    #[Test]
    public function literal_deduplication_is_value_equality_not_key_identity(): void
    {
        // 5 and 5.0 denote the same Number and merge; the string '5', the
        // boolean true and the number 1 all stay distinct from one another.
        $numbers = UnionShape::of(new LiteralShape(5), new LiteralShape(5.0), new LiteralShape('5'));
        $this->assertTrue($numbers->equals(UnionShape::of(new LiteralShape(5), new LiteralShape('5'))));

        $mixed = UnionShape::of(new LiteralShape(true), new LiteralShape(1), new LiteralShape(false));
        $this->assertInstanceOf(UnionShape::class, $mixed);
        $this->assertCount(3, $mixed->members);

        // Both zeroes are one value (-0.0 equals 0.0), whatever their prints.
        $zeroes = UnionShape::of(new LiteralShape(0.0), new LiteralShape(-0.0));
        $this->assertInstanceOf(LiteralShape::class, $zeroes);

        // Two large integers can share a float image (their bucket) while
        // remaining distinct values; the bucket must not merge them.
        $large = UnionShape::of(new LiteralShape(9007199254740993), new LiteralShape(9007199254740992));
        $this->assertInstanceOf(UnionShape::class, $large);
        $this->assertCount(2, $large->members);

        // NAN equals nothing, itself included: duplicates stay.
        $nan = UnionShape::of(new LiteralShape(NAN), new LiteralShape(NAN));
        $this->assertInstanceOf(UnionShape::class, $nan);
        $this->assertCount(2, $nan->members);
    }

    #[Test]
    public function literal_deduplication_matches_a_pairwise_oracle(): void
    {
        // The index is an optimization of pairwise deduplication, so its
        // result must be indistinguishable from the naive scan — the spec —
        // over values chosen to stress every key corner at once.
        $shapes = array_map(
            static fn(bool|int|float|string $value): LiteralShape => new LiteralShape($value),
            self::hostileLiteralValues(),
        );

        $oracle = [];

        foreach ($shapes as $shape) {
            if (!array_any($oracle, static fn(LiteralShape $kept): bool => $kept->equals($shape))) {
                $oracle[] = $shape;
            }
        }

        $union = UnionShape::of(...$shapes);

        $this->assertInstanceOf(UnionShape::class, $union);
        $this->assertSame($oracle, $union->members);
    }

    #[Test]
    public function value_keys_stay_in_lockstep_with_value_equality(): void
    {
        // The law the index rests on: equal literals must share a key, or a
        // bucket hides candidates from itself and equal members stop merging.
        // This test is the alarm for that invariant — a future change to
        // ValueEquality that is not mirrored in LiteralShape::valueKey()
        // turns it red, provided the pool exercises the changed rule.
        $values = self::hostileLiteralValues();

        foreach ($values as $a) {
            foreach ($values as $b) {
                if (!ValueEquality::equals($a, $b)) {
                    continue;
                }

                $this->assertSame(
                    new LiteralShape($a)->valueKey(),
                    new LiteralShape($b)->valueKey(),
                    sprintf(
                        'ValueEquality calls %s and %s equal but their valueKeys differ; LiteralShape::valueKey() must change in lockstep with ValueEquality.',
                        var_export($a, true),
                        var_export($b, true),
                    ),
                );
            }
        }
    }

    /**
     * Literal values picked to collide or nearly collide under every key
     * rule, plus sentinels for plausible future equality changes (letter
     * case, surrounding whitespace, numeric strings, unicode composition).
     *
     * @return list<bool|int|float|string>
     */
    private static function hostileLiteralValues(): array
    {
        return [
            true, false,
            0, 1, -1, 5, 9007199254740992, 9007199254740993, PHP_INT_MAX,
            0.0, -0.0, 1.0, 5.0, 0.1, 0.3, 0.1 + 0.2, 1e308, INF, -INF, NAN,
            9007199254740992.0,
            '', '0', '-0', '1', '5', '0.0', 'a', 'A', ' a', 'a ', 'true', "\u{00E9}", "e\u{0301}",
        ];
    }

    #[Test]
    public function deduplication_keeps_literals_and_their_base_apart(): void
    {
        // A literal is substitutable for its base but never equal to it:
        // 'a' | String keeps both members, in first-seen order.
        $shape = UnionShape::of(new LiteralShape('a'), new StringShape(), new LiteralShape('a'), new StringShape());

        $this->assertInstanceOf(UnionShape::class, $shape);
        $this->assertCount(2, $shape->members);
        $this->assertInstanceOf(LiteralShape::class, $shape->members[0]);
        $this->assertInstanceOf(StringShape::class, $shape->members[1]);
    }

    #[Test]
    public function unions_eliminate_never_members(): void
    {
        $shape = UnionShape::of(new NeverShape(), new NumberShape());

        $this->assertInstanceOf(NumberShape::class, $shape);
    }

    #[Test]
    public function an_unknown_member_absorbs_the_union(): void
    {
        $shape = UnionShape::of(new UnknownShape(), new NumberShape());

        $this->assertInstanceOf(UnknownShape::class, $shape);
    }

    #[Test]
    public function unions_hoist_option_members(): void
    {
        $shape = UnionShape::of(new OptionShape(new NumberShape()), new StringShape());

        $this->assertInstanceOf(OptionShape::class, $shape);
        $this->assertTrue($shape->inner->equals(UnionShape::of(new NumberShape(), new StringShape())));
    }

    #[Test]
    public function a_union_of_only_the_null_type_stays_the_null_type(): void
    {
        $shape = UnionShape::of(new OptionShape(new NeverShape()));

        $this->assertInstanceOf(OptionShape::class, $shape);
        $this->assertInstanceOf(NeverShape::class, $shape->inner);
    }

    #[Test]
    public function an_optional_unknown_member_absorbs_into_an_optional_unknown(): void
    {
        $shape = UnionShape::of(new OptionShape(new NumberShape()), new UnknownShape());

        $this->assertInstanceOf(OptionShape::class, $shape);
        $this->assertInstanceOf(UnknownShape::class, $shape->inner);
    }

    #[Test]
    public function a_list_shape_with_a_negative_minimum_does_not_construct(): void
    {
        // Relations trust the bounds, so an impossible claim must not exist.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('min -1 is negative');

        new ListShape(new NumberShape(), min: -1);
    }

    #[Test]
    public function a_list_shape_with_contradictory_bounds_does_not_construct(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max 1 is below min 2');

        new ListShape(new NumberShape(), min: 2, max: 1);
    }

    #[Test]
    public function equal_list_bounds_are_legal(): void
    {
        $exact = new ListShape(new NumberShape(), min: 2, max: 2);

        $this->assertSame(2, $exact->min);
        $this->assertSame(2, $exact->max);
    }
}
