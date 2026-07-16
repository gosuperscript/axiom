<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Types;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Bindings;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Tests\Fixtures\CountingSource;
use Superscript\Axiom\Tests\Fixtures\EvaluationCounter;
use Superscript\Axiom\Tests\Fixtures\SourceCompilerExtension;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\TypeEnvironment;
use Superscript\Axiom\Types\TypeInference;

#[CoversClass(TypeEnvironment::class)]
#[UsesClass(\Superscript\Axiom\CoreSourceCompilers::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\ConstantNode::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\InfixExpressionCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\StaticSourceCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\SymbolSourceCompiler::class)]
#[UsesClass(\Superscript\Axiom\UnboundSymbols::class)]
#[UsesClass(TypeInference::class)]
#[UsesClass(Definitions::class)]
#[UsesClass(Bindings::class)]
#[UsesClass(Runtime::class)]
#[UsesClass(\Superscript\Axiom\CompiledNode::class)]
#[UsesClass(\Superscript\Axiom\Execution\Node::class)]
#[UsesClass(\Superscript\Axiom\Execution\Entered::class)]
#[UsesClass(\Superscript\Axiom\Execution\Annotated::class)]
#[UsesClass(\Superscript\Axiom\Execution\Exited::class)]
#[UsesClass(Dialect::class)]
#[UsesClass(\Superscript\Axiom\Extension::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilation::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(InfixExpression::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(\Superscript\Axiom\Types\RecordType::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\RecordShape::class)]
#[UsesClass(\Superscript\Axiom\Operators\BinaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\ResolvedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\Operator::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithOperand::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\Equality::class)]
#[UsesClass(\Superscript\Axiom\Operators\Has::class)]
#[UsesClass(\Superscript\Axiom\Operators\In::class)]
#[UsesClass(\Superscript\Axiom\Operators\Intersects::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralType::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\BooleanType::class)]
final class TypeEnvironmentTest extends TestCase
{
    private static function compiler(?Dialect $dialect = null): TypeInference
    {
        $dialect ??= Dialect::core();

        return new TypeInference(
            $dialect->operators(),
            $dialect->unaryOperators(),
            $dialect->literals(),
            $dialect->sourceCompilers(),
        );
    }

    #[Test]
    public function a_declared_type_terminates_recursion(): void
    {
        $environment = new TypeEnvironment(declarations: ['turnover' => new NumberType()]);

        $result = $environment->nodeOfSymbol('turnover', null, self::compiler());

        $this->assertInstanceOf(NumberType::class, $result->unwrap()->returns);
    }

    #[Test]
    public function a_declared_symbol_evaluates_by_reading_its_binding(): void
    {
        $environment = new TypeEnvironment(declarations: ['turnover' => new NumberType()]);
        $node = $environment->nodeOfSymbol('turnover', null, self::compiler())->unwrap();

        $bound = $node->evaluate(new Runtime(new Bindings(['turnover' => 600000])));
        $this->assertSame(600000, $bound->unwrap()->unwrap());

        // A bound null is still a bound key, but its value is honestly
        // absent: one representation of null in the resolution channel.
        $null = $node->evaluate(new Runtime(new Bindings(['turnover' => null])));
        $this->assertTrue($null->unwrap()->isNone());

        $missing = $node->evaluate(new Runtime(new Bindings()));
        $this->assertTrue($missing->unwrap()->isNone());
    }

    #[Test]
    public function symbol_nodes_annotate_their_resolved_values(): void
    {
        $compiler = self::compiler();

        // A declared symbol annotates the bound value it read...
        $observer = new \Superscript\Axiom\Tests\Fixtures\SpyObserver();
        $declared = (new TypeEnvironment(declarations: ['turnover' => new NumberType()]))
            ->nodeOfSymbol('turnover', null, $compiler)->unwrap();

        $declared->evaluate(new Runtime(new Bindings(['turnover' => 600000]), $observer));

        $this->assertContains(['label', 'turnover'], $observer->timeline);
        $this->assertContains(['result', 600000], $observer->timeline);

        // ...and a defined symbol annotates the value its slot produced.
        $observer = new \Superscript\Axiom\Tests\Fixtures\SpyObserver();
        $defined = (new TypeEnvironment(new Definitions(['base' => new StaticSource(7)])))
            ->nodeOfSymbol('base', null, $compiler)->unwrap();

        $defined->evaluate(new Runtime(observer: $observer));

        $this->assertContains(['label', 'base'], $observer->timeline);
        $this->assertContains(['result', 7], $observer->timeline);
    }

    #[Test]
    public function a_namespaced_declaration_uses_the_flattened_key(): void
    {
        $environment = new TypeEnvironment(declarations: ['customer.turnover' => new NumberType()]);

        $result = $environment->nodeOfSymbol('turnover', 'customer', self::compiler());

        $this->assertInstanceOf(NumberType::class, $result->unwrap()->returns);
    }

    #[Test]
    public function a_derived_symbol_compiles_through_the_symbol_graph(): void
    {
        $environment = new TypeEnvironment(
            definitions: new Definitions([
                'base' => new StaticSource(2),
                'derived' => new InfixExpression(
                    left: new SymbolSource('base'),
                    operator: '*',
                    right: new StaticSource(3),
                ),
            ]),
        );

        $result = $environment->nodeOfSymbol('derived', null, self::compiler());

        $this->assertInstanceOf(NumberType::class, $result->unwrap()->returns);
        $this->assertSame(6, $result->unwrap()->evaluate(new Runtime())->unwrap()->unwrap());
    }

    #[Test]
    public function compiled_symbols_are_memoized(): void
    {
        $environment = new TypeEnvironment(
            definitions: new Definitions(['base' => new StaticSource(2)]),
        );

        $first = $environment->nodeOfSymbol('base', null, self::compiler());
        $second = $environment->nodeOfSymbol('base', null, self::compiler());

        $this->assertSame($first, $second);
    }

    #[Test]
    public function a_definition_evaluates_lazily_and_at_most_once_per_invocation(): void
    {
        $counting = new CountingSource(2);
        $counter = new EvaluationCounter();
        $dialect = Dialect::core()->with(new SourceCompilerExtension($counter));

        $environment = new TypeEnvironment(
            definitions: new Definitions([
                'base' => $counting,
                'derived' => new InfixExpression(new SymbolSource('base'), '+', new SymbolSource('base')),
            ]),
        );

        $node = $environment->nodeOfSymbol('derived', null, self::compiler($dialect))->unwrap();

        $this->assertSame(4, $node->evaluate(new Runtime())->unwrap()->unwrap());
        $this->assertSame(1, $counter->evaluations, 'both references read one memoized slot');
    }

    #[Test]
    public function a_cyclic_definition_is_reported_with_its_chain(): void
    {
        $environment = new TypeEnvironment(
            definitions: new Definitions([
                'a' => new InfixExpression(new SymbolSource('b'), '+', new StaticSource(1)),
                'b' => new InfixExpression(new SymbolSource('a'), '+', new StaticSource(1)),
            ]),
        );

        $result = $environment->nodeOfSymbol('a', null, self::compiler());

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('Cyclic symbol definition: a → b → a.', $result->unwrapErr()->describe());
    }

    #[Test]
    public function completed_compilations_leave_no_trace_in_the_cycle_chain(): void
    {
        $environment = new TypeEnvironment(
            definitions: new Definitions([
                'x' => new StaticSource(1),
                'a' => new SymbolSource('a'),
            ]),
        );

        $this->assertTrue($environment->nodeOfSymbol('x', null, self::compiler())->isOk());

        $cycle = $environment->nodeOfSymbol('a', null, self::compiler());

        $this->assertSame('Cyclic symbol definition: a → a.', $cycle->unwrapErr()->message);
    }

    #[Test]
    public function a_record_declaration_never_answers_a_namespaced_symbol(): void
    {
        // Exact keys only, mirroring runtime lookup: a record-typed
        // declaration of customer types the symbol customer, not the
        // symbol customer.turnover — reaching a field is member access.
        $environment = new TypeEnvironment(declarations: [
            'customer' => new \Superscript\Axiom\Types\RecordType(['turnover' => new NumberType()]),
        ]);

        $result = $environment->nodeOfSymbol('turnover', 'customer', self::compiler());

        $this->assertStringContainsString('Unbound symbol [customer.turnover]', $result->unwrapErr()->describe());
    }

    #[Test]
    public function an_unbound_symbol_is_an_error(): void
    {
        $environment = new TypeEnvironment();

        $result = $environment->nodeOfSymbol('ghost', 'customer', self::compiler());

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('Unbound symbol [customer.ghost]', $result->unwrapErr()->message);
    }
}
