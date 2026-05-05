<?php

declare(strict_types=1);

namespace Superscript\Axiom\Resolvers;

use InvalidArgumentException;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Patterns\ExpressionMatcher;
use Superscript\Axiom\Patterns\LiteralMatcher;
use Superscript\Axiom\Patterns\PatternMatcher;
use Superscript\Axiom\Patterns\WildcardMatcher;
use Superscript\Axiom\SchemaVersion;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\TypeDefinition;
use Superscript\Axiom\Sources\UnaryExpression;

/**
 * Builds a {@see DelegatingResolver} whose version-sensitive bindings are
 * owned by the library and cannot be overridden by consumers.
 *
 * Consumers extend a preset via {@see withResolver()}, {@see withMatcher()},
 * and {@see withOverloader()}; they cannot replace the resolver class for
 * a Source type that the version's default map already binds.
 */
final class ResolverPreset
{
    /** @var array<class-string<Source>, class-string<Resolver>> */
    private array $extraResolverMap = [];

    /** @var list<PatternMatcher> */
    private array $extraMatchers = [];

    private ?OperatorOverloader $overloader = null;

    private function __construct(
        public readonly SchemaVersion $version,
    ) {}

    public static function for(SchemaVersion $version): self
    {
        return new self($version);
    }

    /**
     * Add a resolver for a {@see Source} type the consumer owns.
     *
     * @param class-string<Source>   $sourceClass
     * @param class-string<Resolver> $resolverClass
     */
    public function withResolver(string $sourceClass, string $resolverClass): self
    {
        if (array_key_exists($sourceClass, self::defaultResolverMap($this->version))) {
            throw new InvalidArgumentException(sprintf(
                'Cannot override version-sensitive resolver binding for %s. '
                . 'This binding is owned by SchemaVersion::%s.',
                $sourceClass,
                $this->version->name,
            ));
        }

        $clone = clone $this;
        $clone->extraResolverMap[$sourceClass] = $resolverClass;

        return $clone;
    }

    public function withMatcher(PatternMatcher $matcher): self
    {
        $clone = clone $this;
        $clone->extraMatchers[] = $matcher;

        return $clone;
    }

    public function withOverloader(OperatorOverloader $overloader): self
    {
        $clone = clone $this;
        $clone->overloader = $overloader;

        return $clone;
    }

    public function build(): DelegatingResolver
    {
        $resolver = new DelegatingResolver([
            ...self::defaultResolverMap($this->version),
            ...$this->extraResolverMap,
        ]);

        $resolver->instance(
            OperatorOverloader::class,
            $this->overloader ?? self::defaultOverloader($this->version),
        );

        $resolver->instance(MatchResolver::class, new MatchResolver($resolver, [
            ...self::defaultMatchers($this->version),
            new ExpressionMatcher($resolver),
            ...$this->extraMatchers,
        ]));

        return $resolver;
    }

    /**
     * @return array<class-string<Source>, class-string<Resolver>>
     */
    private static function defaultResolverMap(SchemaVersion $version): array
    {
        return match ($version) {
            SchemaVersion::V1 => [
                StaticSource::class       => StaticResolver::class,
                SymbolSource::class       => SymbolResolver::class,
                InfixExpression::class    => InfixResolver::class,
                UnaryExpression::class    => UnaryResolver::class,
                TypeDefinition::class     => ValueResolver::class,
                MatchExpression::class    => MatchResolver::class,
                MemberAccessSource::class => MemberAccessResolver::class,
            ],
        };
    }

    private static function defaultOverloader(SchemaVersion $version): OperatorOverloader
    {
        return match ($version) {
            SchemaVersion::V1 => new DefaultOverloader(),
        };
    }

    /**
     * @return list<PatternMatcher>
     */
    private static function defaultMatchers(SchemaVersion $version): array
    {
        return match ($version) {
            SchemaVersion::V1 => [
                new WildcardMatcher(),
                new LiteralMatcher(),
            ],
        };
    }
}
