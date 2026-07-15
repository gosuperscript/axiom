<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Bindings;
use Superscript\Axiom\Context;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Resolvers\Resolver;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Resolvers\CoerceResolver;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

#[CoversClass(CoerceResolver::class)]
#[CoversClass(Coerce::class)]
#[UsesClass(StringType::class)]
#[UsesClass(Context::class)]
#[UsesClass(Bindings::class)]
#[UsesClass(Definitions::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\Superscript\Axiom\Dialect::class)]
#[UsesClass(\Superscript\Axiom\Types\NumberType::class)]
#[UsesClass(\Superscript\Axiom\Types\OptionType::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OptionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
class CoerceResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_a_value()
    {
        $resolver = new CoerceResolver(new class implements Resolver {
            public function resolve(Source $source, Context $context): Result
            {
                return Ok(Some('Hello, World!'));
            }
        });
        $source = new Coerce(new StringType(), new class implements Source {});

        $result = $resolver->resolve($source, new Context());
        $this->assertInstanceOf(Result::class, $result);
        $this->assertEquals('Hello, World!', $result->unwrap()->unwrap());
    }

    #[Test]
    public function absence_cannot_cross_a_non_optional_coercion(): void
    {
        // The checker takes Coerce at its declared type, so a silent None
        // here would deliver null into a certified expression. '' under
        // Number is an absence reading — the node errs by name instead.
        $resolver = new CoerceResolver(new class implements Resolver {
            public function resolve(Source $source, Context $context): Result
            {
                return Ok(Some(''));
            }
        });
        $source = new Coerce(new \Superscript\Axiom\Types\NumberType(), new class implements Source {});

        $result = $resolver->resolve($source, new Context());

        $this->assertStringContainsString(
            'reads as missing, but Number is required; coerce to Number?',
            $result->unwrapErr()->getMessage(),
        );
    }

    #[Test]
    public function an_absence_reading_inhabits_an_optional_coercion(): void
    {
        // Option<Number> coerces '' to a present null (absence is a value
        // of the option), so the guard has nothing to refuse.
        $resolver = new CoerceResolver(new class implements Resolver {
            public function resolve(Source $source, Context $context): Result
            {
                return Ok(Some(''));
            }
        });
        $type = new \Superscript\Axiom\Types\OptionType(new \Superscript\Axiom\Types\NumberType());
        $source = new Coerce($type, new class implements Source {});

        $result = $resolver->resolve($source, new Context());

        $this->assertTrue($result->isOk());
    }

    #[Test]
    public function an_absent_inner_value_inhabits_an_optional_coercion(): void
    {
        // The inner source resolved to nothing at all; Option<Number>
        // includes absence, so the guard has nothing to refuse.
        $resolver = new CoerceResolver(new class implements Resolver {
            public function resolve(Source $source, Context $context): Result
            {
                return Ok(\Superscript\Monads\Option\None());
            }
        });
        $type = new \Superscript\Axiom\Types\OptionType(new \Superscript\Axiom\Types\NumberType());
        $source = new Coerce($type, new class implements Source {});

        $result = $resolver->resolve($source, new Context());

        $this->assertTrue($result->isOk());
        $this->assertTrue($result->unwrap()->isNone());
    }
}
