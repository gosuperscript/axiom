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
use Superscript\Axiom\Resolvers\AscriptionResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Sources\Ascription;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Tests\Resolvers\Fixtures\SpyInspector;

#[CoversClass(AscriptionResolver::class)]
#[CoversClass(Ascription::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(Context::class)]
#[UsesClass(Bindings::class)]
#[UsesClass(Definitions::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(\Superscript\Axiom\Exceptions\TransformValueException::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\Superscript\Axiom\Dialect::class)]
final class AscriptionResolverTest extends TestCase
{
    #[Test]
    public function a_true_claim_passes_the_value_through_unconverted(): void
    {
        $resolver = new AscriptionResolver(new StaticResolver());
        $claim = new Ascription(new NumberType(), new StaticSource(42));

        $this->assertSame(42, $resolver->resolve($claim, new Context())->unwrap()->unwrap());
    }

    #[Test]
    public function a_false_claim_is_a_loud_tripwire_never_a_conversion(): void
    {
        $resolver = new AscriptionResolver(new StaticResolver());
        // '42' coerces to Number — but ascription asserts, it never converts.
        $claim = new Ascription(new NumberType(), new StaticSource('42'));

        $this->assertTrue($resolver->resolve($claim, new Context())->isErr());
    }

    #[Test]
    public function absence_cannot_cross_a_non_optional_claim(): void
    {
        // The checker takes the claim at its word (Ascription : T), so a
        // silent None here would deliver null into an expression certified
        // to receive a Number.
        $resolver = new AscriptionResolver(new StaticResolver());
        $claim = new Ascription(new NumberType(), new StaticSource(null));

        $result = $resolver->resolve($claim, new Context());

        $this->assertStringContainsString(
            'reads as missing, but the claim Number is required; claim Number?',
            $result->unwrapErr()->getMessage(),
        );
    }

    #[Test]
    public function an_absent_value_inhabits_an_optional_claim(): void
    {
        $resolver = new AscriptionResolver(new StaticResolver());
        $claim = new Ascription(new \Superscript\Axiom\Types\OptionType(new NumberType()), new StaticSource(null));

        $this->assertTrue($resolver->resolve($claim, new Context())->unwrap()->isNone());
    }

    #[Test]
    public function it_annotates_the_claim(): void
    {
        $inspector = new SpyInspector();
        $resolver = new AscriptionResolver(new StaticResolver());
        $claim = new Ascription(new StringType(), new StaticSource('hello'));

        $resolver->resolve($claim, new Context(inspector: $inspector));

        $this->assertSame('is StringType', $inspector->annotations['label']);
    }

    #[Test]
    public function it_describes_the_claim(): void
    {
        $claim = new Ascription(new NumberType(), new StaticSource(42));

        $this->assertSame('42 (is number)', $claim->describe());

        $anonymous = new Ascription(new NumberType(), new \Superscript\Axiom\Tests\Sources\Fixtures\UndescribableSource());

        $this->assertSame('UndescribableSource (is number)', $anonymous->describe());
    }
}
