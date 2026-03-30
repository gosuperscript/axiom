<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Dsl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dsl\Associativity;
use Superscript\Axiom\Dsl\CoreDslPlugin;
use Superscript\Axiom\Dsl\FunctionEntry;
use Superscript\Axiom\Dsl\FunctionParam;
use Superscript\Axiom\Dsl\FunctionRegistry;
use Superscript\Axiom\Dsl\OperatorEntry;
use Superscript\Axiom\Dsl\OperatorPosition;
use Superscript\Axiom\Dsl\OperatorRegistry;
use Superscript\Axiom\Dsl\TypeRegistry;
use Superscript\Axiom\Operators\BinaryOverloader;
use Superscript\Axiom\Operators\ComparisonOverloader;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\HasOverloader;
use Superscript\Axiom\Operators\InOverloader;
use Superscript\Axiom\Operators\IntersectsOverloader;
use Superscript\Axiom\Operators\LogicalOverloader;
use Superscript\Axiom\Operators\NullOverloader;
use Superscript\Axiom\Patterns\LiteralMatcher;
use Superscript\Axiom\Patterns\WildcardMatcher;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;

#[CoversClass(CoreDslPlugin::class)]
#[UsesClass(OperatorRegistry::class)]
#[UsesClass(OperatorEntry::class)]
#[UsesClass(Associativity::class)]
#[UsesClass(OperatorPosition::class)]
#[UsesClass(TypeRegistry::class)]
#[UsesClass(FunctionRegistry::class)]
#[UsesClass(FunctionEntry::class)]
#[UsesClass(FunctionParam::class)]
#[UsesClass(DefaultOverloader::class)]
#[UsesClass(NullOverloader::class)]
#[UsesClass(BinaryOverloader::class)]
#[UsesClass(ComparisonOverloader::class)]
#[UsesClass(HasOverloader::class)]
#[UsesClass(InOverloader::class)]
#[UsesClass(LogicalOverloader::class)]
#[UsesClass(IntersectsOverloader::class)]
#[UsesClass(WildcardMatcher::class)]
#[UsesClass(LiteralMatcher::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(ListType::class)]
#[UsesClass(DictType::class)]
class CoreDslPluginTest extends TestCase
{
    #[Test]
    public function it_registers_all_operators(): void
    {
        $plugin = new CoreDslPlugin();
        $registry = new OperatorRegistry();
        $plugin->operators($registry);

        $all = $registry->all();

        $this->assertTrue($registry->isOperator('+'));
        $this->assertTrue($registry->isOperator('-'));
        $this->assertTrue($registry->isOperator('*'));
        $this->assertTrue($registry->isOperator('/'));
        $this->assertTrue($registry->isOperator('||'));
        $this->assertTrue($registry->isOperator('&&'));
        $this->assertTrue($registry->isOperator('='));
        $this->assertTrue($registry->isOperator('=='));
        $this->assertTrue($registry->isOperator('==='));
        $this->assertTrue($registry->isOperator('!='));
        $this->assertTrue($registry->isOperator('!=='));
        $this->assertTrue($registry->isOperator('<'));
        $this->assertTrue($registry->isOperator('<='));
        $this->assertTrue($registry->isOperator('>'));
        $this->assertTrue($registry->isOperator('>='));
        $this->assertTrue($registry->isOperator('!'));
        $this->assertTrue($registry->isOperator('|>'));

        // Keyword operators
        $this->assertTrue($registry->isKeywordOperator('in'));
        $this->assertTrue($registry->isKeywordOperator('has'));
        $this->assertTrue($registry->isKeywordOperator('intersects'));
        $this->assertTrue($registry->isKeywordOperator('xor'));
        $this->assertTrue($registry->isKeywordOperator('not'));
    }

    #[Test]
    public function it_registers_core_types(): void
    {
        $plugin = new CoreDslPlugin();
        $types = new TypeRegistry();
        $plugin->types($types);

        $this->assertTrue($types->has('number'));
        $this->assertTrue($types->has('string'));
        $this->assertTrue($types->has('bool'));
        $this->assertTrue($types->has('list'));
        $this->assertTrue($types->has('dict'));

        $this->assertInstanceOf(NumberType::class, $types->resolve('number'));
        $this->assertInstanceOf(StringType::class, $types->resolve('string'));
        $this->assertInstanceOf(BooleanType::class, $types->resolve('bool'));
        $this->assertInstanceOf(ListType::class, $types->resolve('list'));
        $this->assertInstanceOf(DictType::class, $types->resolve('dict'));
    }

    #[Test]
    public function it_registers_no_functions(): void
    {
        $plugin = new CoreDslPlugin();
        $functions = new FunctionRegistry();
        $plugin->functions($functions);

        $this->assertCount(0, $functions->all());
    }

    #[Test]
    public function it_returns_pattern_matchers(): void
    {
        $plugin = new CoreDslPlugin();
        $patterns = $plugin->patterns();

        $this->assertCount(2, $patterns);
        $this->assertInstanceOf(WildcardMatcher::class, $patterns[0]);
        $this->assertInstanceOf(LiteralMatcher::class, $patterns[1]);
    }

    #[Test]
    public function it_returns_empty_literals(): void
    {
        $plugin = new CoreDslPlugin();

        $this->assertCount(0, $plugin->literals());
    }

    #[Test]
    public function it_returns_overloaders(): void
    {
        $plugin = new CoreDslPlugin();
        $overloaders = $plugin->overloaders();

        $this->assertCount(1, $overloaders);
        $this->assertInstanceOf(DefaultOverloader::class, $overloaders[0]);
    }
}
