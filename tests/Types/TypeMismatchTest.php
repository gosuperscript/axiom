<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Types\TypeMismatch;

#[CoversClass(TypeMismatch::class)]
final class TypeMismatchTest extends TestCase
{
    #[Test]
    public function it_describes_a_flat_mismatch(): void
    {
        $mismatch = new TypeMismatch('Number is not assignable to String.');

        $this->assertSame('Number is not assignable to String.', $mismatch->describe());
    }

    #[Test]
    public function mismatches_are_not_dead_by_default(): void
    {
        $this->assertFalse((new TypeMismatch('x'))->dead);
        $this->assertTrue((new TypeMismatch('x', dead: true))->dead);
    }

    #[Test]
    public function the_deepest_path_is_the_last_one_in_the_chain(): void
    {
        // A refusal is stamped as it leaves the node that made it, and every
        // ancestor that adds context wraps it and stamps its own path — so
        // the chain reads outermost-first and the node that actually refused
        // is the last path in it.
        $mismatch = new TypeMismatch('outer', [
            new TypeMismatch('inner', [
                new TypeMismatch('the node that refused', path: '$.children[0].node.children[1].node'),
            ], path: '$.children[0].node'),
        ], path: '$');

        $this->assertSame('$.children[0].node.children[1].node', $mismatch->deepestPath());
    }

    #[Test]
    public function a_cause_about_no_node_leaves_the_deepest_path_standing(): void
    {
        // A relation given two types knows neither node, so its verdict is
        // unlocated and must not erase the node named above it.
        $mismatch = new TypeMismatch('outer', [
            new TypeMismatch('String is not assignable to Number.'),
        ], path: '$.children[0].node');

        $this->assertSame('$.children[0].node', $mismatch->deepestPath());
    }

    #[Test]
    public function a_refusal_about_the_whole_program_names_no_node(): void
    {
        $this->assertNull(new TypeMismatch('The definition graph is not well-founded.')->deepestPath());
    }

    #[Test]
    public function it_describes_a_nested_cause_chain_with_indentation(): void
    {
        $mismatch = new TypeMismatch('outer', [
            new TypeMismatch('first cause', [
                new TypeMismatch('root cause'),
            ]),
            new TypeMismatch('second cause'),
        ]);

        $this->assertSame(
            "outer\n  first cause\n    root cause\n  second cause",
            $mismatch->describe(),
        );
    }
}
