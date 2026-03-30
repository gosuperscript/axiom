<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Dsl;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dsl\CompilationResult;
use Superscript\Axiom\Dsl\Compiler;
use Superscript\Axiom\Dsl\CoreDslPlugin;
use Superscript\Axiom\Dsl\FunctionRegistry;
use Superscript\Axiom\Dsl\Lexer;
use Superscript\Axiom\Dsl\OperatorRegistry;
use Superscript\Axiom\Dsl\Parser;
use Superscript\Axiom\Dsl\PrettyPrinter;
use Superscript\Axiom\Dsl\TypeRegistry;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\OperatorOverloader;
use Superscript\Axiom\Patterns\ExpressionMatcher;
use Superscript\Axiom\Patterns\LiteralMatcher;
use Superscript\Axiom\Patterns\WildcardMatcher;
use Superscript\Axiom\Resolvers\DelegatingResolver;
use Superscript\Axiom\Resolvers\InfixResolver;
use Superscript\Axiom\Resolvers\MatchResolver;
use Superscript\Axiom\Resolvers\MemberAccessResolver;
use Superscript\Axiom\Resolvers\StaticResolver;
use Superscript\Axiom\Resolvers\SymbolResolver;
use Superscript\Axiom\Resolvers\ValueResolver;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\TypeDefinition;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\SymbolRegistry;

#[CoversNothing]
class IntegrationTest extends TestCase
{
    private function compileDsl(string $source): CompilationResult
    {
        $operators = new OperatorRegistry();
        $functions = new FunctionRegistry();
        $types = new TypeRegistry();

        $plugin = new CoreDslPlugin();
        $plugin->operators($operators);
        $plugin->types($types);

        $lexer = new Lexer($operators);
        $tokens = $lexer->tokenize($source);

        $parser = new Parser($operators, $functions);
        $program = $parser->parse($tokens);

        $compiler = new Compiler($types);

        return $compiler->compile($program);
    }

    private function makeResolver(CompilationResult $result, array $inputValues = []): DelegatingResolver
    {
        $resolver = new DelegatingResolver([
            StaticSource::class => StaticResolver::class,
            InfixExpression::class => InfixResolver::class,
            TypeDefinition::class => ValueResolver::class,
            SymbolSource::class => SymbolResolver::class,
            MatchExpression::class => MatchResolver::class,
            UnaryExpression::class => \Superscript\Axiom\Resolvers\UnaryResolver::class,
            MemberAccessSource::class => MemberAccessResolver::class,
        ]);

        $resolver->instance(OperatorOverloader::class, new DefaultOverloader());

        $matchers = [
            new WildcardMatcher(),
            new LiteralMatcher(),
            new ExpressionMatcher($resolver),
        ];
        $resolver->instance(MatchResolver::class, new MatchResolver($resolver, $matchers));

        // Build symbol registry from compiled symbols + input values
        $symbols = [];
        foreach ($result->symbols as $name => $source) {
            $symbols[$name] = $source;
        }
        foreach ($inputValues as $name => $value) {
            if (is_array($value)) {
                $symbols[$name] = [];
                foreach ($value as $k => $v) {
                    $symbols[$name][$k] = new StaticSource($v);
                }
            } else {
                $symbols[$name] = new StaticSource($value);
            }
        }

        $resolver->instance(SymbolRegistry::class, new SymbolRegistry($symbols));

        return $resolver;
    }

    private function resolve(string $dsl, array $inputs = []): array
    {
        $result = $this->compileDsl($dsl);
        $resolver = $this->makeResolver($result, $inputs);

        $values = [];
        foreach ($result->outputs as $name) {
            $source = $result->symbols[$name];
            $resolved = $resolver->resolve($source);
            $values[$name] = $resolved->unwrap()->unwrap();
        }

        return $values;
    }

    // --- End-to-end: if/then/else ---

    #[Test]
    public function e2e_simple_if_then_else(): void
    {
        $dsl = <<<'DSL'
        loading: number = if claims > 2
            then 25
            else 0
        DSL;

        $values = $this->resolve($dsl, ['claims' => 3]);

        $this->assertSame(25, $values['loading']);
    }

    #[Test]
    public function e2e_if_then_else_false_branch(): void
    {
        $dsl = <<<'DSL'
        loading: number = if claims > 2
            then 25
            else 0
        DSL;

        $values = $this->resolve($dsl, ['claims' => 1]);

        $this->assertSame(0, $values['loading']);
    }

    #[Test]
    public function e2e_chained_else_if(): void
    {
        $dsl = <<<'DSL'
        factor: number = if claims == 0
            then 0.9
            else if claims <= 2
            then 1.0
            else 1.5
        DSL;

        $this->assertSame(0.9, $this->resolve($dsl, ['claims' => 0])['factor']);
        $this->assertSame(1.0, $this->resolve($dsl, ['claims' => 1])['factor']);
        $this->assertSame(1.0, $this->resolve($dsl, ['claims' => 2])['factor']);
        $this->assertSame(1.5, $this->resolve($dsl, ['claims' => 5])['factor']);
    }

    // --- End-to-end: match dispatch ---

    #[Test]
    public function e2e_match_dispatch(): void
    {
        $dsl = <<<'DSL'
        tier_factor: number = match tier {
            "micro" => 1.3,
            "small" => 1.1,
            _ => 0.85
        }
        DSL;

        $this->assertSame(1.3, $this->resolve($dsl, ['tier' => 'micro'])['tier_factor']);
        $this->assertSame(1.1, $this->resolve($dsl, ['tier' => 'small'])['tier_factor']);
        $this->assertSame(0.85, $this->resolve($dsl, ['tier' => 'large'])['tier_factor']);
    }

    // --- End-to-end: subjectless cond ---

    #[Test]
    public function e2e_subjectless_cond(): void
    {
        $dsl = <<<'DSL'
        claims_factor: number = match {
            claims == 0 => 0.90,
            claims <= 2 => 1.00,
            _ => 1.50
        }
        DSL;

        $this->assertSame(0.90, $this->resolve($dsl, ['claims' => 0])['claims_factor']);
        $this->assertSame(1.00, $this->resolve($dsl, ['claims' => 1])['claims_factor']);
        $this->assertSame(1.50, $this->resolve($dsl, ['claims' => 5])['claims_factor']);
    }

    // --- End-to-end: schema ---

    #[Test]
    public function e2e_schema_with_asserts(): void
    {
        $dsl = <<<'DSL'
        schema PremiumCalc {
            input quote: dict
            input rates: dict

            private base: number = rates.base * quote.sum_insured / 1000
            gross: number = base * 1.1

            assert gross >= 500
        }
        DSL;

        $result = $this->compileDsl($dsl);

        $this->assertArrayHasKey('quote', $result->inputs);
        $this->assertArrayHasKey('rates', $result->inputs);
        $this->assertContains('gross', $result->outputs);
        $this->assertNotContains('base', $result->outputs);
        $this->assertCount(1, $result->assertions);

        // Resolve with actual values
        $resolver = $this->makeResolver($result, [
            'quote' => ['sum_insured' => 100000],
            'rates' => ['base' => 10],
        ]);

        $grossSource = $result->symbols['gross'];
        $grossValue = $resolver->resolve($grossSource)->unwrap()->unwrap();
        $this->assertSame(1100.0, $grossValue);

        // Resolve assertion
        $assertSource = $result->assertions[0];
        $assertResult = $resolver->resolve($assertSource)->unwrap()->unwrap();
        $this->assertTrue($assertResult);
    }

    // --- Round-trip ---

    #[Test]
    public function round_trip_simple_declaration(): void
    {
        $source = 'x: number = 42';

        $operators = new OperatorRegistry();
        $functions = new FunctionRegistry();
        $plugin = new CoreDslPlugin();
        $plugin->operators($operators);

        $lexer = new Lexer($operators);
        $parser = new Parser($operators, $functions);

        $program = $parser->parse($lexer->tokenize($source));
        $printed = (new PrettyPrinter())->print($program);

        $this->assertSame($source, $printed);
    }

    #[Test]
    public function round_trip_match_with_subject(): void
    {
        $source = <<<'DSL'
        x: number = match tier {
            "micro" => 1.3,
            "small" => 1.1,
            _ => 0.85,
        }
        DSL;

        $operators = new OperatorRegistry();
        $functions = new FunctionRegistry();
        $plugin = new CoreDslPlugin();
        $plugin->operators($operators);

        $lexer = new Lexer($operators);
        $parser = new Parser($operators, $functions);

        $program = $parser->parse($lexer->tokenize($source));
        $printed = (new PrettyPrinter())->print($program);

        // Re-parse should produce structurally identical program
        $reparsed = $parser->parse($lexer->tokenize($printed));
        $this->assertEquals($program->body[0]->expression->arms[0]->pattern->value ?? null, $reparsed->body[0]->expression->arms[0]->pattern->value ?? null);
    }

    // --- End-to-end with member access ---

    #[Test]
    public function e2e_member_access_in_condition(): void
    {
        $dsl = <<<'DSL'
        loading: number = if quote.claims > 2
            then 25
            else 0
        DSL;

        $values = $this->resolve($dsl, ['quote' => ['claims' => 3]]);

        $this->assertSame(25, $values['loading']);
    }

    #[Test]
    public function e2e_multiple_symbols(): void
    {
        $dsl = <<<'DSL'
        a: number = 10
        b: number = a * 2
        DSL;

        $result = $this->compileDsl($dsl);
        $resolver = $this->makeResolver($result);

        $aValue = $resolver->resolve($result->symbols['a'])->unwrap()->unwrap();
        $bValue = $resolver->resolve($result->symbols['b'])->unwrap()->unwrap();

        $this->assertSame(10, $aValue);
        $this->assertSame(20, $bValue);
    }
}
