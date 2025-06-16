<?php

declare(strict_types=1);

namespace Superscript\Schema\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Schema\Resolvers\Resolver;
use Superscript\Schema\Source;
use Superscript\Schema\Sources\ValueDefinition;
use Superscript\Schema\Types\NumberType;
use Superscript\Schema\Types\StringType;
use Superscript\Schema\Resolvers\ValueResolver;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

#[CoversClass(ValueResolver::class)]
#[CoversClass(ValueDefinition::class)]
#[UsesClass(StringType::class)]
class ValueResolverTest extends TestCase
{
    #[Test]
    public function it_can_resolve_a_value()
    {
        $resolver = new ValueResolver(new class implements Resolver {
            public function resolve(Source $source): Result
            {
                return Ok(Some('Hello, World!'));
            }

            public static function supports(Source $source): bool
            {
                return true;
            }
        });
        $source = new ValueDefinition(new StringType(), new class implements Source {});

        $this->assertTrue($resolver::supports($source));
        $result = $resolver->resolve($source);
        $this->assertInstanceOf(Result::class, $result);
        $this->assertEquals('Hello, World!', $result->unwrap()->unwrap());
    }

    #[Test]
    public function it_supports_only_value_definitions(): void
    {
        $this->assertTrue(ValueResolver::supports(new ValueDefinition(new NumberType(), new class implements Source {})));
        $this->assertFalse(ValueResolver::supports(new class implements Source {}));
    }
}
