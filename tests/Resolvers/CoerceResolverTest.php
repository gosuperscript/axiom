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
}
