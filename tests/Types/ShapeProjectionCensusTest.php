<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NeverType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\Shapes\BooleanShape;
use Superscript\Axiom\Types\Shapes\DictShape;
use Superscript\Axiom\Types\Shapes\ListShape;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\NeverShape;
use Superscript\Axiom\Types\Shapes\NumberShape;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Shapes\RecordShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\StringShape;
use Superscript\Axiom\Types\Shapes\UnionShape;
use Superscript\Axiom\Types\Shapes\UnknownShape;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnionType;
use Superscript\Axiom\Types\UnknownType;

/**
 * The shape-soundness census: every core type projects into the sealed
 * vocabulary, and the projection is the expected constructor. A new core
 * type must be added here to exist.
 */
#[CoversClass(BooleanType::class)]
#[CoversClass(NumberType::class)]
#[CoversClass(StringType::class)]
#[CoversClass(ListType::class)]
#[CoversClass(DictType::class)]
#[UsesClass(LiteralType::class)]
#[UsesClass(NeverType::class)]
#[UsesClass(OptionType::class)]
#[UsesClass(RecordType::class)]
#[UsesClass(UnionType::class)]
#[UsesClass(UnknownType::class)]
#[UsesClass(\Superscript\Axiom\Types\OpaqueType::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeReifier::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OpaqueShape::class)]
#[UsesClass(BooleanShape::class)]
#[UsesClass(DictShape::class)]
#[UsesClass(ListShape::class)]
#[UsesClass(LiteralShape::class)]
#[UsesClass(NeverShape::class)]
#[UsesClass(NumberShape::class)]
#[UsesClass(OptionShape::class)]
#[UsesClass(RecordShape::class)]
#[UsesClass(StringShape::class)]
#[UsesClass(UnionShape::class)]
#[UsesClass(UnknownShape::class)]
final class ShapeProjectionCensusTest extends TestCase
{
    /**
     * @param class-string<Shape> $expected
     */
    #[Test]
    #[DataProvider('census')]
    public function every_core_type_projects_into_the_sealed_vocabulary(Type $type, string $expected): void
    {
        $this->assertInstanceOf($expected, $type->shape());
    }

    public static function census(): \Generator
    {
        yield [new BooleanType(), BooleanShape::class];
        yield [new NumberType(), NumberShape::class];
        yield [new StringType(), StringShape::class];
        yield [new ListType(new NumberType()), ListShape::class];
        yield [new DictType(new StringType()), DictShape::class];
        yield [new LiteralType('shop'), LiteralShape::class];
        yield [new OptionType(new NumberType()), OptionShape::class];
        yield [new UnionType(new LiteralType('a'), new LiteralType('b')), UnionShape::class];
        yield [new RecordType(['a' => new NumberType()]), RecordShape::class];
        yield [new UnknownType(), UnknownShape::class];
        yield [new NeverType(), NeverShape::class];
        yield [new \Superscript\Axiom\Types\OpaqueType('ClaimId'), \Superscript\Axiom\Types\Shapes\OpaqueShape::class];
    }

    /**
     * C2, the shape-truth law: a record projection is a claim that the
     * member-access mechanism can reach every projected field on every
     * value and obtain an inhabitant of the field's shape. Verified over
     * specimens — trust, but generatively verify. Packages extend this by
     * contributing their own record-projected types and specimens.
     *
     * @param array<string, mixed> $specimen
     */
    #[Test]
    #[DataProvider('recordProjections')]
    public function record_projections_are_true(Type $type, array $specimen): void
    {
        $shape = $type->shape();
        $this->assertInstanceOf(RecordShape::class, $shape);

        foreach ($shape->fields as $name => $field) {
            $this->assertArrayHasKey(
                $name,
                $specimen,
                sprintf("C2: projected field '%s' is not reachable on a specimen — the shape lies about its shape", $name),
            );

            $this->assertTrue(
                \Superscript\Axiom\Types\TypeReifier::reify($field)->coerce($specimen[$name])->isOk(),
                sprintf("C2: specimen field '%s' does not inhabit its projected shape", $name),
            );
        }
    }

    public static function recordProjections(): \Generator
    {
        yield 'closed record' => [
            new RecordType(['name' => new StringType(), 'age' => new NumberType()]),
            ['name' => 'Ada', 'age' => 36],
        ];
        yield 'record with optional field' => [
            new RecordType(['note' => new OptionType(new StringType())]),
            ['note' => null],
        ];
    }
}
