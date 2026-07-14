<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Types\LiteralTypeRegistry;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeMismatch;

#[CoversClass(LiteralTypeRegistry::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(\Superscript\Axiom\Types\StringType::class)]
#[UsesClass(TypeMismatch::class)]
final class LiteralTypeRegistryTest extends TestCase
{
    #[Test]
    public function it_resolves_a_registered_class_through_its_factory(): void
    {
        $registry = new LiteralTypeRegistry([
            \DateTimeImmutable::class => fn(object $value): Type => new NumberType(),
        ]);

        $result = $registry->resolve(new \DateTimeImmutable());

        $this->assertInstanceOf(NumberType::class, $result->unwrap());
    }

    #[Test]
    public function it_resolves_subclasses_of_a_registered_class(): void
    {
        $registry = new LiteralTypeRegistry([
            \DateTimeInterface::class => fn(object $value): Type => new NumberType(),
        ]);

        $this->assertTrue($registry->resolve(new \DateTimeImmutable())->isOk());
    }

    #[Test]
    public function it_matches_by_instance_not_by_position(): void
    {
        $registry = new LiteralTypeRegistry([
            \ArrayObject::class => fn(object $value): Type => new \Superscript\Axiom\Types\StringType(),
            \DateTimeImmutable::class => fn(object $value): Type => new NumberType(),
        ]);

        $this->assertInstanceOf(NumberType::class, $registry->resolve(new \DateTimeImmutable())->unwrap());
    }

    #[Test]
    public function an_unregistered_class_is_a_mismatch(): void
    {
        $result = (new LiteralTypeRegistry())->resolve(new \stdClass());

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('No literal type is registered for [stdClass]', $result->unwrapErr()->message);
    }
}
