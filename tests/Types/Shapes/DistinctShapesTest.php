<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types\Shapes;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Operators\ValueEquality;
use Superscript\Axiom\Types\Shapes\DistinctShapes;
use Superscript\Axiom\Types\Shapes\LiteralShape;
use Superscript\Axiom\Types\Shapes\Shape;
use Superscript\Axiom\Types\Shapes\StringShape;

/**
 * DistinctShapes is only exercised indirectly elsewhere (through
 * UnionShape::of() and UnionType::join(), in ShapeTest and UnionTypeTest),
 * and both call sites read its behaviour differently: of() discards add()'s
 * return value, so a lie about entering is invisible there — join() acts on
 * it directly. Testing the class on its own pins the contract {@see
 * DistinctShapes::add()} documents, independent of how any one caller
 * happens to use it.
 */
#[CoversClass(DistinctShapes::class)]
#[UsesClass(LiteralShape::class)]
#[UsesClass(StringShape::class)]
#[UsesClass(ValueEquality::class)]
final class DistinctShapesTest extends TestCase
{
    #[Test]
    public function add_answers_whether_the_shape_entered(): void
    {
        $distinct = new DistinctShapes(static fn(Shape $a, Shape $b): bool => $a->equals($b));
        $a = new LiteralShape('a');
        $duplicate = new LiteralShape('a');
        $b = new LiteralShape('b');

        $this->assertTrue($distinct->add($a));
        $this->assertFalse($distinct->add($duplicate));
        $this->assertTrue($distinct->add($b));
        $this->assertSame([$a, $b], $distinct->all());
    }

    #[Test]
    public function pairwise_distinct_literals_never_reach_the_sameness_judgment(): void
    {
        // Every value below is pairwise distinct under LiteralShape's own
        // valueKey() {@see \Superscript\Axiom\Tests\Types\Shapes\ShapeTest::value_key_is_pinned_to_its_type_prefixed_format()},
        // so a correctly indexed set never has to ask $same anything here:
        // each add() finds its own bucket empty. A single call means add()
        // stopped consulting the index — the one way to lose a bucket that
        // valueKey()'s own tests can't see, since valueKey() would still
        // return the right answer; add() would just stop asking it.
        $comparisons = 0;
        $counting = function (Shape $a, Shape $b) use (&$comparisons): bool {
            $comparisons++;

            return $a->equals($b);
        };
        $distinct = new DistinctShapes($counting);

        foreach ([
            new LiteralShape(true), new LiteralShape(false),
            new LiteralShape(1), new LiteralShape(2), new LiteralShape(3), new LiteralShape(4), new LiteralShape(5),
            new LiteralShape('a'), new LiteralShape('b'), new LiteralShape('c'), new LiteralShape('d'), new LiteralShape('e'),
        ] as $shape) {
            $distinct->add($shape);
        }

        $this->assertSame(0, $comparisons);
    }
}
