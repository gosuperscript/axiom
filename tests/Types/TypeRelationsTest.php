<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\Shapes\BooleanShape;
use Superscript\Axiom\Types\Shapes\DictShape;
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
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Axiom\Types\UnionType;
use Superscript\Axiom\Types\UnknownType;

#[CoversClass(TypeRelations::class)]
#[UsesClass(TypeMismatch::class)]
#[UsesClass(TypeDescriber::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(UnionType::class)]
#[UsesClass(UnknownType::class)]
#[UsesClass(BooleanShape::class)]
#[UsesClass(DictShape::class)]
#[UsesClass(ListShape::class)]
#[UsesClass(LiteralShape::class)]
#[UsesClass(NeverShape::class)]
#[UsesClass(NumberShape::class)]
#[UsesClass(OpaqueShape::class)]
#[UsesClass(OptionShape::class)]
#[UsesClass(RecordShape::class)]
#[UsesClass(StringShape::class)]
#[UsesClass(UnionShape::class)]
#[UsesClass(UnknownShape::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
final class TypeRelationsTest extends TestCase
{
    #[Test]
    #[DataProvider('assignableCases')]
    public function it_accepts_sound_assignments(Shape $source, Shape $target): void
    {
        $result = TypeRelations::assignable($source, $target);

        $this->assertTrue(
            $result->isOk(),
            sprintf(
                '%s should be assignable to %s but was refused: %s',
                TypeDescriber::describeShape($source),
                TypeDescriber::describeShape($target),
                $result->isErr() ? $result->unwrapErr()->describe() : '',
            ),
        );
    }

    public static function assignableCases(): \Generator
    {
        yield 'primitive to itself' => [new NumberShape(), new NumberShape()];
        yield 'unknown to itself' => [new UnknownShape(), new UnknownShape()];
        yield 'never to anything' => [new NeverShape(), new NumberShape()];
        yield 'never to never' => [new NeverShape(), new NeverShape()];

        yield 'literal to its base' => [new LiteralShape('shop'), new StringShape()];
        yield 'number literal to Number' => [new LiteralShape(5), new NumberShape()];
        yield 'literal to equal literal' => [new LiteralShape(5), new LiteralShape(5.0)];
        yield 'literal to a union containing it' => [
            new LiteralShape('shop'),
            UnionShape::of(new LiteralShape('shop'), new LiteralShape('office')),
        ];
        yield 'union of literals to the base' => [
            UnionShape::of(new LiteralShape(1), new LiteralShape(2)),
            new NumberShape(),
        ];
        yield 'union to a wider union' => [
            UnionShape::of(new LiteralShape('a'), new LiteralShape('b')),
            UnionShape::of(new LiteralShape('a'), new LiteralShape('b'), new LiteralShape('c')),
        ];
        yield 'union to a union of the bases' => [
            UnionShape::of(new LiteralShape('a'), new LiteralShape(1)),
            UnionShape::of(new StringShape(), new NumberShape()),
        ];

        yield 'present value fills an option slot' => [new NumberShape(), new OptionShape(new NumberShape())];
        yield 'option to option with wider inner' => [
            new OptionShape(new LiteralShape(1)),
            new OptionShape(new NumberShape()),
        ];
        yield 'the null type fills every option slot' => [
            new OptionShape(new NeverShape()),
            new OptionShape(new StringShape()),
        ];
        yield 'literal fills an option of the base' => [new LiteralShape('shop'), new OptionShape(new StringShape())];

        yield 'list with identical element' => [new ListShape(new NumberShape()), new ListShape(new NumberShape())];
        yield 'list with narrower element' => [new ListShape(new LiteralShape(1)), new ListShape(new NumberShape())];
        yield 'sized list into unbounded list' => [new ListShape(new NumberShape(), 2, 2), new ListShape(new NumberShape())];
        yield 'empty list into any list admitting empty' => [
            new ListShape(new NeverShape(), 0, 0),
            new ListShape(new StringShape(), 0, 5),
        ];
        yield 'tighter bounds into looser bounds' => [new ListShape(new NumberShape(), 2, 3), new ListShape(new NumberShape(), 1, 4)];
        yield 'equal max bound is contained' => [new ListShape(new NumberShape(), 1, 3), new ListShape(new NumberShape(), 0, 3)];

        yield 'dict with narrower value' => [new DictShape(new LiteralShape('a')), new DictShape(new StringShape())];

        yield 'record to itself' => [
            new RecordShape(['a' => new NumberShape()]),
            new RecordShape(['a' => new NumberShape()]),
        ];
        yield 'missing optional field on a closed source reads as null' => [
            new RecordShape(['a' => new NumberShape()]),
            new RecordShape(['a' => new NumberShape(), 'b' => new OptionShape(new StringShape())]),
        ];
        yield 'record field narrows' => [
            new RecordShape(['a' => new LiteralShape(1)]),
            new RecordShape(['a' => new NumberShape()]),
        ];
        yield 'closed record of non-optional fields into a dict' => [
            new RecordShape(['a' => new LiteralShape(1), 'b' => new NumberShape()]),
            new DictShape(new NumberShape()),
        ];

        yield 'opaque to same opaque' => [new OpaqueShape('ClaimId'), new OpaqueShape('ClaimId')];
        yield 'opaque parameters relate covariantly' => [
            new OpaqueShape('money', ['currency' => new LiteralShape('GBP')]),
            new OpaqueShape('money', ['currency' => UnionShape::of(new LiteralShape('GBP'), new LiteralShape('USD'))]),
        ];
        yield 'identical parameterized opaques' => [
            new OpaqueShape('money', ['currency' => new LiteralShape('GBP')]),
            new OpaqueShape('money', ['currency' => new LiteralShape('GBP')]),
        ];
    }

    #[Test]
    #[DataProvider('notAssignableCases')]
    public function it_refuses_unsound_assignments(Shape $source, Shape $target, ?string $fragment = null): void
    {
        $result = TypeRelations::assignable($source, $target);

        $this->assertTrue(
            $result->isErr(),
            sprintf(
                '%s should not be assignable to %s',
                TypeDescriber::describeShape($source),
                TypeDescriber::describeShape($target),
            ),
        );

        if ($fragment !== null) {
            $this->assertStringContainsString($fragment, $result->unwrapErr()->describe());
        }
    }

    public static function notAssignableCases(): \Generator
    {
        yield 'number to string' => [new NumberShape(), new StringShape()];
        yield 'boolean to number' => [new BooleanShape(), new NumberShape()];

        yield 'unknown certifies nothing' => [new UnknownShape(), new NumberShape(), ': Unknown certifies nothing'];
        yield 'nothing is assignable to unknown' => [new NumberShape(), new UnknownShape(), ': Unknown certifies nothing'];
        yield 'nothing but never is assignable to never' => [new NumberShape(), new NeverShape()];

        yield 'base does not narrow to its literal' => [
            new StringShape(),
            new LiteralShape('shop'),
        ];
        yield 'literal of the wrong base is refused with its reason' => [
            new LiteralShape('shop'),
            new NumberShape(),
            'a literal is substitutable only for its own base',
        ];
        yield 'literal of a different base' => [new LiteralShape(5), new StringShape()];
        yield 'distinct literals' => [new LiteralShape('shop'), new LiteralShape('office')];
        yield 'union with a failing member' => [
            UnionShape::of(new NumberShape(), new StringShape()),
            new NumberShape(),
            'every union member must be assignable',
        ];
        yield 'no union member accepts the source' => [
            new BooleanShape(),
            UnionShape::of(new StringShape(), new NumberShape()),
            'no union member accepts it',
        ];

        yield 'an option does not fill a present slot' => [
            new OptionShape(new NumberShape()),
            new NumberShape(),
            'the value may be absent',
        ];
        yield 'option inner must be assignable' => [
            new OptionShape(new StringShape()),
            new OptionShape(new NumberShape()),
            'String is not assignable to Number',
        ];
        yield 'present value must match the option inner' => [
            new StringShape(),
            new OptionShape(new NumberShape()),
            'String is not assignable to Number',
        ];

        yield 'list element mismatch' => [
            new ListShape(new StringShape()),
            new ListShape(new NumberShape()),
            'String is not assignable to Number',
        ];
        yield 'list minimum not met' => [
            new ListShape(new NumberShape(), 0),
            new ListShape(new NumberShape(), 1),
            'length bounds are not contained',
        ];
        yield 'unbounded list into bounded list' => [
            new ListShape(new NumberShape(), 0, null),
            new ListShape(new NumberShape(), 0, 3),
        ];
        yield 'looser max into tighter max' => [new ListShape(new NumberShape(), 0, 5), new ListShape(new NumberShape(), 0, 3)];

        yield 'dict value mismatch' => [
            new DictShape(new StringShape()),
            new DictShape(new NumberShape()),
            'String is not assignable to Number',
        ];
        yield 'dict does not certify record fields' => [
            new DictShape(new NumberShape()),
            new RecordShape(['a' => new NumberShape()]),
        ];

        yield 'record with an undeclared extra field' => [
            new RecordShape(['a' => new NumberShape(), 'x' => new StringShape()]),
            new RecordShape(['a' => new NumberShape()]),
            "Field 'x' is not part of the record",
        ];
        yield 'record field type mismatch' => [
            new RecordShape(['a' => new StringShape()]),
            new RecordShape(['a' => new NumberShape()]),
            "Field 'a' is incompatible",
        ];
        yield 'record field mismatch carries the field cause' => [
            new RecordShape(['a' => new StringShape()]),
            new RecordShape(['a' => new NumberShape()]),
            'String is not assignable to Number',
        ];
        yield 'required field missing' => [
            new RecordShape([]),
            new RecordShape(['a' => new NumberShape()]),
            "Required field 'a' is missing",
        ];

        yield 'record with optional field into dict' => [
            new RecordShape(['a' => new OptionShape(new NumberShape())]),
            new DictShape(new NumberShape()),
            "Optional field 'a' may be null",
        ];
        yield 'record field incompatible with dict value' => [
            new RecordShape(['a' => new StringShape()]),
            new DictShape(new NumberShape()),
            "Field 'a' is incompatible",
        ];
        yield 'record-to-dict field mismatch carries the field cause' => [
            new RecordShape(['a' => new StringShape()]),
            new DictShape(new NumberShape()),
            'String is not assignable to Number',
        ];
        yield 'a record-to-dict failure reports every field, not just the first' => [
            new RecordShape(['a' => new OptionShape(new StringShape()), 'b' => new StringShape()]),
            new DictShape(new NumberShape()),
            "Field 'b' is incompatible",
        ];
        yield 'list is not a dict' => [new ListShape(new NumberShape()), new DictShape(new NumberShape())];
        yield 'number is not a dict' => [new NumberShape(), new DictShape(new NumberShape())];

        yield 'opaque with different identity' => [
            new OpaqueShape('ClaimId'),
            new OpaqueShape('CatalogueKey'),
            'nominal identities differ',
        ];
        yield 'opaque is not structurally transparent' => [new OpaqueShape('ClaimId'), new StringShape()];
        yield 'opaque parameters do not widen backwards' => [
            new OpaqueShape('money', ['currency' => UnionShape::of(new LiteralShape('GBP'), new LiteralShape('USD'))]),
            new OpaqueShape('money', ['currency' => new LiteralShape('GBP')]),
            "Parameter 'currency' is incompatible",
        ];
        yield 'opaque parameter mismatch carries the parameter cause' => [
            new OpaqueShape('money', ['currency' => new LiteralShape('USD')]),
            new OpaqueShape('money', ['currency' => new LiteralShape('GBP')]),
            "'USD' is not assignable to 'GBP'",
        ];
        yield 'a record source never satisfies an opaque target' => [
            new RecordShape(['currency' => new LiteralShape('GBP')]),
            new OpaqueShape('money', ['currency' => new LiteralShape('GBP')]),
        ];
        yield 'opaques with different parameter lists' => [
            new OpaqueShape('money', ['currency' => new LiteralShape('GBP')]),
            new OpaqueShape('money'),
            'the parameter lists differ',
        ];
        yield 'a parameterized opaque never matches a fictional record' => [
            new OpaqueShape('money', ['currency' => new LiteralShape('GBP')]),
            new RecordShape(['currency' => new LiteralShape('GBP')]),
        ];
        yield 'record is not a list' => [new RecordShape(['a' => new NumberShape()]), new ListShape(new NumberShape())];
    }

    #[Test]
    #[DataProvider('overlapCases')]
    public function it_decides_overlap_symmetrically(Shape $a, Shape $b, bool $expected): void
    {
        $this->assertSame($expected, TypeRelations::shapesOverlap($a, $b)->isOk());
        $this->assertSame($expected, TypeRelations::shapesOverlap($b, $a)->isOk());
    }

    #[Test]
    public function a_non_empty_list_dict_refusal_carries_the_empty_array_cause(): void
    {
        $result = TypeRelations::shapesOverlap(
            new ListShape(new NumberShape(), min: 1),
            new DictShape(new NumberShape()),
        );

        $this->assertStringContainsString(
            'Only the empty array inhabits both a list and a dict, and this list cannot be empty.',
            $result->unwrapErr()->describe(),
        );
    }

    #[Test]
    public function a_list_record_refusal_carries_the_empty_array_cause(): void
    {
        $result = TypeRelations::shapesOverlap(
            new ListShape(new NumberShape(), min: 1),
            new RecordShape([]),
        );

        $this->assertStringContainsString(
            'Only the empty array inhabits both a list and a record, so they overlap exactly when the record is empty and the list can be empty.',
            $result->unwrapErr()->describe(),
        );
    }

    public static function overlapCases(): \Generator
    {
        yield 'unknown overlaps everything' => [new UnknownShape(), new NumberShape(), true];
        yield 'unknown overlaps unknown' => [new UnknownShape(), new UnknownShape(), true];
        yield 'never overlaps nothing' => [new NeverShape(), new NumberShape(), false];
        yield 'never does not even overlap never' => [new NeverShape(), new NeverShape(), false];
        yield 'never does not overlap unknown' => [new UnknownShape(), new NeverShape(), true];

        yield 'a shape overlaps itself' => [new NumberShape(), new NumberShape(), true];
        yield 'distinct primitives do not overlap' => [new NumberShape(), new StringShape(), false];

        yield 'options always overlap (shared null)' => [
            new OptionShape(new NumberShape()),
            new OptionShape(new StringShape()),
            true,
        ];
        yield 'option overlaps its inner base' => [new OptionShape(new NumberShape()), new NumberShape(), true];
        yield 'option with disjoint present type' => [new OptionShape(new StringShape()), new NumberShape(), false];

        yield 'unions overlap on a shared member' => [
            UnionShape::of(new LiteralShape('a'), new LiteralShape('b')),
            UnionShape::of(new LiteralShape('b'), new LiteralShape('c')),
            true,
        ];
        yield 'disjoint unions do not overlap' => [
            UnionShape::of(new LiteralShape('a'), new LiteralShape('b')),
            UnionShape::of(new LiteralShape('c'), new LiteralShape('d')),
            false,
        ];
        yield 'a union overlaps a non-union on a member' => [
            UnionShape::of(new LiteralShape('a'), new LiteralShape('b')),
            new LiteralShape('b'),
            true,
        ];

        yield 'distinct literals do not overlap' => [new LiteralShape('a'), new LiteralShape('b'), false];
        yield 'literal overlaps its base' => [new LiteralShape('a'), new StringShape(), true];
        yield 'literal does not overlap a different base' => [new LiteralShape('a'), new NumberShape(), false];

        yield 'lists that can both be empty overlap regardless of elements' => [
            new ListShape(new NumberShape()),
            new ListShape(new StringShape()),
            true,
        ];
        yield 'non-empty lists need element overlap' => [
            new ListShape(new NumberShape(), 1),
            new ListShape(new StringShape(), 1),
            false,
        ];
        yield 'non-empty lists with overlapping elements' => [
            new ListShape(new LiteralShape(1), 1),
            new ListShape(new NumberShape(), 1),
            true,
        ];
        yield 'lists with disjoint length bounds' => [
            new ListShape(new NumberShape(), 3),
            new ListShape(new NumberShape(), 0, 2),
            false,
        ];
        yield 'bounded lists with disjoint length ranges' => [
            new ListShape(new NumberShape(), 0, 2),
            new ListShape(new NumberShape(), 4, 6),
            false,
        ];
        yield 'bounded lists with intersecting length ranges and overlapping elements' => [
            new ListShape(new NumberShape(), 1, 3),
            new ListShape(new NumberShape(), 2, 5),
            true,
        ];
        yield 'lists sharing exactly the boundary length overlap' => [
            new ListShape(new NumberShape(), 0, 2),
            new ListShape(new NumberShape(), 2, 5),
            true,
        ];

        yield 'dicts always overlap (shared empty dict)' => [
            new DictShape(new NumberShape()),
            new DictShape(new StringShape()),
            true,
        ];

        yield 'records with compatible shared fields overlap' => [
            new RecordShape(['a' => new NumberShape()]),
            new RecordShape(['a' => new LiteralShape(1)]),
            true,
        ];
        yield 'records with disjoint shared fields do not overlap' => [
            new RecordShape(['a' => new LiteralShape('x')]),
            new RecordShape(['a' => new LiteralShape('y')]),
            false,
        ];
        yield 'a required field forbidden by a closed record blocks overlap' => [
            new RecordShape(['a' => new NumberShape(), 'b' => new StringShape()]),
            new RecordShape(['a' => new NumberShape()]),
            false,
        ];
        yield 'an optional extra field does not block overlap with a closed record' => [
            new RecordShape(['a' => new NumberShape(), 'b' => new OptionShape(new StringShape())]),
            new RecordShape(['a' => new NumberShape()]),
            true,
        ];

        yield 'record overlaps dict when required fields fit the value' => [
            new RecordShape(['a' => new LiteralShape(1)]),
            new DictShape(new NumberShape()),
            true,
        ];
        yield 'record does not overlap dict when a required field is disjoint' => [
            new RecordShape(['a' => new StringShape()]),
            new DictShape(new NumberShape()),
            false,
        ];
        yield 'optional fields are ignored for record-dict overlap' => [
            new RecordShape(['a' => new OptionShape(new StringShape())]),
            new DictShape(new NumberShape()),
            true,
        ];
        yield 'a required field after an optional one still blocks record-dict overlap' => [
            new RecordShape(['a' => new OptionShape(new StringShape()), 'b' => new StringShape()]),
            new DictShape(new NumberShape()),
            false,
        ];
        yield 'record does not overlap a non-dict non-record' => [
            new RecordShape(['a' => new NumberShape()]),
            new NumberShape(),
            false,
        ];

        yield 'the empty record overlaps an emptiable list at []' => [
            new ListShape(new NumberShape()),
            new RecordShape([]),
            true,
        ];
        yield 'a non-empty list never overlaps the empty record' => [
            new ListShape(new NumberShape(), min: 1),
            new RecordShape([]),
            false,
        ];
        yield 'a record with fields never overlaps a list' => [
            new ListShape(new NumberShape()),
            new RecordShape(['a' => new OptionShape(new NumberShape())]),
            false,
        ];

        yield 'opaque overlaps only itself' => [new OpaqueShape('ClaimId'), new OpaqueShape('ClaimId'), true];
        yield 'distinct opaques do not overlap' => [new OpaqueShape('ClaimId'), new OpaqueShape('CatalogueKey'), false];
        yield 'parameterized opaques overlap when parameters overlap' => [
            new OpaqueShape('money', ['currency' => UnionShape::of(new LiteralShape('GBP'), new LiteralShape('USD'))]),
            new OpaqueShape('money', ['currency' => UnionShape::of(new LiteralShape('USD'), new LiteralShape('EUR'))]),
            true,
        ];
        yield 'parameterized opaques with disjoint parameters do not overlap' => [
            new OpaqueShape('money', ['currency' => new LiteralShape('GBP')]),
            new OpaqueShape('money', ['currency' => new LiteralShape('USD')]),
            false,
        ];
        yield 'opaques with mismatched parameter lists do not overlap' => [
            new OpaqueShape('money', ['currency' => new LiteralShape('GBP')]),
            new OpaqueShape('money'),
            false,
        ];
        yield 'opaque does not overlap a primitive' => [new OpaqueShape('ClaimId'), new StringShape(), false];
        // The empty array inhabits both List and Dict — one PHP value, two
        // types — so overlap holds exactly when the list admits emptiness,
        // and for no other pairing of the two container heads.
        yield 'list overlaps dict at the empty array' => [new ListShape(new NumberShape()), new DictShape(new NumberShape()), true];
        yield 'a non-empty list does not overlap dict' => [new ListShape(new NumberShape(), min: 1), new DictShape(new NumberShape()), false];
        yield 'a list does not overlap a record' => [new ListShape(new NumberShape()), new RecordShape(['a' => new NumberShape()]), false];
        yield 'a number does not overlap a list' => [new NumberShape(), new ListShape(new NumberShape()), false];
        yield 'a number does not overlap a dict' => [new NumberShape(), new DictShape(new NumberShape()), false];
    }

    #[Test]
    public function overlap_failures_carry_causes(): void
    {
        $result = TypeRelations::shapesOverlap(
            new ListShape(new NumberShape(), 3),
            new ListShape(new NumberShape(), 0, 2),
        );

        $this->assertStringContainsString('The length bounds do not intersect.', $result->unwrapErr()->describe());
    }

    #[Test]
    public function never_overlap_failures_name_never(): void
    {
        $this->assertStringContainsString(
            'Never has no values',
            TypeRelations::shapesOverlap(new NeverShape(), new NumberShape())->unwrapErr()->describe(),
        );
        $this->assertStringContainsString(
            'Never has no values',
            TypeRelations::shapesOverlap(new NumberShape(), new NeverShape())->unwrapErr()->describe(),
        );
    }

    #[Test]
    public function option_overlap_failures_carry_the_inner_cause(): void
    {
        $described = TypeRelations::shapesOverlap(new OptionShape(new StringShape()), new NumberShape())
            ->unwrapErr()->describe();

        $this->assertStringContainsString('String and Number share no values.', $described);

        $reversed = TypeRelations::shapesOverlap(new NumberShape(), new OptionShape(new StringShape()))
            ->unwrapErr()->describe();

        $this->assertStringContainsString('Number and String share no values.', $reversed);
    }

    #[Test]
    public function list_element_overlap_failures_carry_the_element_cause(): void
    {
        $described = TypeRelations::shapesOverlap(
            new ListShape(new NumberShape(), 1),
            new ListShape(new StringShape(), 1),
        )->unwrapErr()->describe();

        $this->assertStringContainsString('Number and String share no values.', $described);
    }

    #[Test]
    public function record_overlap_failures_report_every_conflict(): void
    {
        $described = TypeRelations::shapesOverlap(
            new RecordShape(['x' => new NumberShape(), 'y' => new NumberShape(), 'a' => new LiteralShape('x')]),
            new RecordShape(['a' => new LiteralShape('y')]),
        )->unwrapErr()->describe();

        $this->assertStringContainsString("Required field 'x' is forbidden by the record.", $described);
        $this->assertStringContainsString("Required field 'y' is forbidden by the record.", $described);
        $this->assertStringContainsString("Field 'a' cannot satisfy both records.", $described);
        $this->assertStringContainsString("'x' and 'y' share no values.", $described);
    }

    #[Test]
    public function opaque_overlap_failures_carry_the_parameter_cause(): void
    {
        $described = TypeRelations::shapesOverlap(
            new OpaqueShape('money', ['currency' => new LiteralShape('GBP')]),
            new OpaqueShape('money', ['currency' => new LiteralShape('USD')]),
        )->unwrapErr()->describe();

        $this->assertStringContainsString("Parameter 'currency' cannot satisfy both.", $described);
        $this->assertStringContainsString("'GBP' and 'USD' share no values.", $described);
    }

    #[Test]
    public function record_dict_overlap_failures_carry_the_field_cause(): void
    {
        $described = TypeRelations::shapesOverlap(
            new RecordShape(['a' => new StringShape()]),
            new DictShape(new NumberShape()),
        )->unwrapErr()->describe();

        $this->assertStringContainsString("Required field 'a' cannot inhabit the dict.", $described);
        $this->assertStringContainsString('String and Number share no values.', $described);
    }

    #[Test]
    public function equivalence_is_assignability_both_ways(): void
    {
        $enum = UnionShape::of(new LiteralShape('a'), new LiteralShape('b'));
        $reordered = UnionShape::of(new LiteralShape('b'), new LiteralShape('a'));

        $this->assertTrue(TypeRelations::shapesEquivalent($enum, $reordered)->isOk());

        $oneWay = TypeRelations::shapesEquivalent(new LiteralShape('a'), new StringShape());

        $this->assertTrue($oneWay->isErr());
        $this->assertStringContainsString('not equivalent', $oneWay->unwrapErr()->describe());
        $this->assertStringContainsString('is not assignable to', $oneWay->unwrapErr()->describe());
    }

    #[Test]
    public function type_level_entry_points_project_through_shapes(): void
    {
        $this->assertTrue(TypeRelations::isTypeAssignableTo(new NumberType(), new OptionType(new NumberType()))->isOk());
        $this->assertTrue(TypeRelations::isTypeAssignableTo(new NumberType(), new StringType())->isErr());

        $this->assertTrue(TypeRelations::areEquivalent(new NumberType(), new NumberType())->isOk());
        $this->assertTrue(TypeRelations::areEquivalent(new NumberType(), new StringType())->isErr());

        $this->assertTrue(TypeRelations::overlaps(new OptionType(new NumberType()), new NumberType())->isOk());
        $this->assertTrue(TypeRelations::overlaps(new NumberType(), new StringType())->isErr());
    }

    #[Test]
    public function admits_is_assignability_with_no_unknown_hole(): void
    {
        $this->assertTrue(TypeRelations::admits(new NumberType(), new NumberType())->isOk());
        $this->assertTrue(TypeRelations::admits(new OptionType(new NumberType()), new NumberType())->isErr());
        $this->assertTrue(TypeRelations::admits(new UnionType(new NumberType(), new StringType()), new NumberType())->isErr());

        // Unknown is inert: refused, with the two bridges in the message.
        $unknown = TypeRelations::admits(new UnknownType(), new NumberType());

        $this->assertTrue($unknown->isErr());
        $this->assertStringContainsString('An Unknown operand is inert', $unknown->unwrapErr()->message);
        $this->assertStringContainsString('Ascription', $unknown->unwrapErr()->message);
        $this->assertStringContainsString('Coerce', $unknown->unwrapErr()->message);
    }
}
