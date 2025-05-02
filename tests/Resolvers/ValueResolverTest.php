<?php

namespace Superscript\Abacus\Tests\Resolvers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Abacus\Resolvers\Resolver;
use Superscript\Abacus\Source;
use Superscript\Abacus\Types\StringType;
use Superscript\Abacus\ValueDefinition;
use Superscript\Abacus\Resolvers\ValueResolver;
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
        });
        $valueDefinition = new ValueDefinition(new StringType(), new class implements Source {});

        $result = $resolver->resolve($valueDefinition);

        $this->assertInstanceOf(Result::class, $result);
        $this->assertEquals('Hello, World!', $result->unwrap()->unwrap());
    }
}
