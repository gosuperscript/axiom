<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Resolvers;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Bindings;
use Superscript\Axiom\Context;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Operators\BinaryOverloader;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\NullOverloader;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Patterns\ExpressionMatcher;
use Superscript\Axiom\Patterns\LiteralMatcher;
use Superscript\Axiom\Patterns\WildcardMatcher;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\InfixResolver;
use Superscript\Axiom\Resolvers\MatchResolver;
use Superscript\Axiom\Resolvers\ResolverPreset;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\SymbolResolver;
use Superscript\Axiom\Resolvers\ValueResolver;
use Superscript\Axiom\SchemaVersion;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\TypeDefinition;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Tests\Resolvers\Fixtures\CustomMatcher;
use Superscript\Axiom\Tests\Resolvers\Fixtures\CustomMatchPattern;
use Superscript\Axiom\Tests\Resolvers\Fixtures\CustomSource;
use Superscript\Axiom\Tests\Resolvers\Fixtures\CustomSourceResolver;

#[CoversClass(ResolverPreset::class)]
#[UsesClass(DelegatingResolver::class)]
#[UsesClass(StaticResolver::class)]
#[UsesClass(SymbolResolver::class)]
#[UsesClass(InfixResolver::class)]
#[UsesClass(ValueResolver::class)]
#[UsesClass(MatchResolver::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(InfixExpression::class)]
#[UsesClass(TypeDefinition::class)]
#[UsesClass(MatchExpression::class)]
#[UsesClass(MatchArm::class)]
#[UsesClass(WildcardPattern::class)]
#[UsesClass(DefaultOverloader::class)]
#[UsesClass(BinaryOverloader::class)]
#[UsesClass(NullOverloader::class)]
#[UsesClass(WildcardMatcher::class)]
#[UsesClass(LiteralMatcher::class)]
#[UsesClass(ExpressionMatcher::class)]
#[UsesClass(Context::class)]
#[UsesClass(Bindings::class)]
#[UsesClass(Definitions::class)]
final class ResolverPresetTest extends TestCase
{
    #[Test]
    public function for_v1_builds_a_working_default_stack(): void
    {
        $resolver = ResolverPreset::for(SchemaVersion::V1)->build();

        $this->assertInstanceOf(DelegatingResolver::class, $resolver);

        // PI * radius * radius — the README quick-start expression
        $source = new InfixExpression(
            left: new SymbolSource('PI'),
            operator: '*',
            right: new InfixExpression(
                left: new SymbolSource('radius'),
                operator: '*',
                right: new SymbolSource('radius'),
            ),
        );

        $context = new Context(
            bindings: new Bindings(['radius' => 5]),
            definitions: new Definitions(['PI' => new StaticSource(3.14)]),
        );

        $this->assertSame(3.14 * 5 * 5, $resolver->resolve($source, $context)->unwrap()->unwrap());
    }

    #[Test]
    public function with_resolver_adds_a_binding_for_a_consumer_owned_source(): void
    {
        $resolver = ResolverPreset::for(SchemaVersion::V1)
            ->withResolver(CustomSource::class, CustomSourceResolver::class)
            ->build();

        $result = $resolver->resolve(new CustomSource('hello'), new Context());

        $this->assertSame('custom:hello', $result->unwrap()->unwrap());
    }

    #[Test]
    public function with_resolver_throws_when_overriding_a_version_sensitive_binding(): void
    {
        $preset = ResolverPreset::for(SchemaVersion::V1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(
            'Cannot override version-sensitive resolver binding for '
            . TypeDefinition::class
            . '. This binding is owned by SchemaVersion::V1.',
        );

        $preset->withResolver(TypeDefinition::class, CustomSourceResolver::class);
    }

    #[Test]
    public function with_overloader_replaces_the_default_overloader(): void
    {
        $custom = new DefaultOverloader();

        $resolver = ResolverPreset::for(SchemaVersion::V1)
            ->withOverloader($custom)
            ->build();

        $this->assertSame($custom, $resolver->get(OperatorOverloader::class));
    }

    #[Test]
    public function default_overloader_is_used_when_no_override_is_given(): void
    {
        $resolver = ResolverPreset::for(SchemaVersion::V1)->build();

        $this->assertInstanceOf(DefaultOverloader::class, $resolver->get(OperatorOverloader::class));
    }

    #[Test]
    public function with_matcher_extends_the_match_resolver_with_a_custom_pattern(): void
    {
        $resolver = ResolverPreset::for(SchemaVersion::V1)
            ->withMatcher(new CustomMatcher())
            ->build();

        $source = new MatchExpression(
            subject: new StaticSource('go'),
            arms: [
                new MatchArm(new CustomMatchPattern('stop'), new StaticSource('stopped')),
                new MatchArm(new CustomMatchPattern('go'), new StaticSource('going')),
                new MatchArm(new WildcardPattern(), new StaticSource('idle')),
            ],
        );

        $result = $resolver->resolve($source, new Context());

        $this->assertSame('going', $result->unwrap()->unwrap());
    }

    #[Test]
    public function with_resolver_returns_a_new_instance(): void
    {
        $original = ResolverPreset::for(SchemaVersion::V1);
        $extended = $original->withResolver(CustomSource::class, CustomSourceResolver::class);

        $this->assertNotSame($original, $extended);
    }

    #[Test]
    public function with_matcher_returns_a_new_instance(): void
    {
        $original = ResolverPreset::for(SchemaVersion::V1);
        $extended = $original->withMatcher(new CustomMatcher());

        $this->assertNotSame($original, $extended);
    }

    #[Test]
    public function with_overloader_returns_a_new_instance(): void
    {
        $original = ResolverPreset::for(SchemaVersion::V1);
        $extended = $original->withOverloader(new DefaultOverloader());

        $this->assertNotSame($original, $extended);
    }

    #[Test]
    public function version_is_exposed_on_the_preset(): void
    {
        $this->assertSame(SchemaVersion::V1, ResolverPreset::for(SchemaVersion::V1)->version);
    }
}
