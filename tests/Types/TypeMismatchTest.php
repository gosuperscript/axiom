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
