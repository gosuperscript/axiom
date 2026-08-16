<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Rewrite;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Superscript\Axiom\Rewrite\CoreSourceDescenders;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\MatchPattern;

/**
 * The core node set is closed, and descent over it must stay exhaustive. A
 * node class with no arm is not a loud failure at runtime — it is an opaque
 * leaf, so every rewrite beneath it silently stops happening and the report
 * says so in a line nobody reads. This law makes the omission fail here
 * instead, at the moment the class is added.
 */
#[CoversClass(CoreSourceDescenders::class)]
#[UsesNamespace('Superscript\\Axiom')]
final class CoreDescentExhaustivenessTest extends TestCase
{
    #[Test]
    public function every_core_source_class_has_a_descent_arm(): void
    {
        $arms = CoreSourceDescenders::sources();

        foreach (self::coreClasses(Source::class) as $class) {
            $this->assertArrayHasKey($class, $arms, sprintf(
                '[%s] is a core source with no descent arm: the rewriter would treat it as an opaque leaf and never rewrite inside it.',
                $class,
            ));
        }

        $this->assertSame([], array_diff(array_keys($arms), self::coreClasses(Source::class)), 'every arm names a core source class');
    }

    #[Test]
    public function every_core_pattern_class_has_a_descent_arm(): void
    {
        $arms = CoreSourceDescenders::patterns();

        foreach (self::coreClasses(MatchPattern::class) as $class) {
            $this->assertArrayHasKey($class, $arms, sprintf('[%s] is a core match pattern with no descent arm.', $class));
        }

        $this->assertSame([], array_diff(array_keys($arms), self::coreClasses(MatchPattern::class)), 'every arm names a core pattern class');
    }

    /**
     * Read off the directory rather than a hand-kept list: a list would have
     * to be updated by the same person who forgot the arm.
     *
     * @param class-string $interface
     * @return list<class-string>
     */
    private static function coreClasses(string $interface): array
    {
        $classes = [];

        foreach (glob(__DIR__ . '/../../src/Sources/*.php') ?: [] as $file) {
            /** @var class-string $class */
            $class = 'Superscript\\Axiom\\Sources\\' . basename($file, '.php');

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->implementsInterface($interface)) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }
}
