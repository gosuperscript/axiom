<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Program;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\UnboundSymbols;

#[CoversClass(Expression::class)]
#[UsesClass(\Superscript\Axiom\Input::class)]
#[UsesClass(\Superscript\Axiom\CoreSourceCompilers::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\ConstantNode::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\InfixExpressionCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\StaticSourceCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\SymbolSourceCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilation::class)]
#[CoversClass(Program::class)]
#[UsesClass(UnboundSymbols::class)]
#[UsesClass(\Superscript\Axiom\Bindings::class)]
#[UsesClass(\Superscript\Axiom\CompiledNode::class)]
#[UsesClass(\Superscript\Axiom\CompiledSource::class)]
#[UsesClass(\Superscript\Axiom\BoundOperation::class)]
#[UsesClass(\Superscript\Axiom\SourceEvaluation::class)]
#[UsesClass(\Superscript\Axiom\Exceptions\CompilationAborted::class)]
#[UsesClass(\Superscript\Axiom\Exceptions\EvaluationAborted::class)]
#[UsesClass(\Superscript\Axiom\Runtime::class)]
#[UsesClass(\Superscript\Axiom\DefinitionGraph::class)]
#[UsesClass(Definitions::class)]
#[UsesClass(\Superscript\Axiom\Dialect::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(InfixExpression::class)]
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
#[UsesClass(\Superscript\Axiom\Operators\Coalesce::class)]
#[UsesClass(\Superscript\Axiom\Operators\Equality::class)]
#[UsesClass(\Superscript\Axiom\Operators\Has::class)]
#[UsesClass(\Superscript\Axiom\Operators\In::class)]
#[UsesClass(\Superscript\Axiom\Operators\Intersects::class)]
#[UsesClass(\Superscript\Axiom\Exceptions\BoundaryViolation::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeEnvironment::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeInference::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralType::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Fields\OpaqueFieldRegistry::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[\PHPUnit\Framework\Attributes\UsesNamespace('Superscript\\Axiom\\Analysis')]
#[UsesClass(\Superscript\Axiom\Operators\Connective::class)]
#[UsesClass(\Superscript\Axiom\Types\PresentType::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnsupportedOperation::class)]
#[UsesClass(\Superscript\Axiom\Types\InfixExpressionTyping::class)]
final class ExpressionTest extends TestCase
{
    #[Test]
    public function an_expression_compiles_to_a_callable_program(): void
    {
        $expression = new Expression(
            source: new InfixExpression(
                left: new SymbolSource('a'),
                operator: '+',
                right: new SymbolSource('b'),
            ),
            declarations: [
                'a' => new NumberType(),
                'b' => new NumberType(),
            ],
        );

        $program = $expression->compile()->unwrap();

        $this->assertInstanceOf(Program::class, $program);
        $this->assertInstanceOf(NumberType::class, $program->returns);
        $this->assertSame(5, $program(['a' => 2, 'b' => 3])->unwrap()->unwrap());
    }

    #[Test]
    public function a_compiled_program_reports_the_declared_inputs_it_reads(): void
    {
        $expression = new Expression(
            source: new InfixExpression(
                new SymbolSource('amount'),
                '+',
                new SymbolSource('adjustment'),
            ),
            definitions: new Definitions([
                'adjustment' => new InfixExpression(
                    new SymbolSource('rate'),
                    '*',
                    new SymbolSource('amount'),
                ),
            ]),
            declarations: [
                'amount' => new NumberType(),
                'rate' => new NumberType(),
                'unused' => new NumberType(),
            ],
        );

        $program = $expression->compile()->unwrap();

        $this->assertSame(['amount', 'rate'], $program->references);
    }

    #[Test]
    public function a_program_without_symbol_reads_reports_no_references(): void
    {
        $program = (new Expression(
            new StaticSource(42),
            declarations: ['unused' => new NumberType()],
        ))->compile()->unwrap();

        $this->assertSame([], $program->references);
    }

    #[Test]
    public function an_unbound_symbol_does_not_compile(): void
    {
        // Under value-directed evaluation an unbound symbol quietly
        // resolved to absence; a program that runs has passed the check,
        // and the check refuses it.
        $expression = new Expression(source: new SymbolSource('unknown'));

        $result = $expression->compile();

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('Unbound symbol [unknown]', $result->unwrapErr()->describe());
    }

    #[Test]
    public function call_and_invoke_agree_on_the_program(): void
    {
        $program = (new Expression(source: new StaticSource(42)))->compile()->unwrap();

        $this->assertSame(42, $program()->unwrap()->unwrap());
        $this->assertSame(42, $program->call()->unwrap()->unwrap());
    }

    #[Test]
    public function infer_is_the_type_of_the_compiled_program(): void
    {
        $expression = new Expression(source: new StaticSource(42));

        $this->assertInstanceOf(\Superscript\Axiom\Types\LiteralType::class, $expression->infer()->unwrap());
    }

    #[Test]
    public function check_is_compile_plus_assignability(): void
    {
        $gate = new Expression(
            source: new InfixExpression(new SymbolSource('turnover'), '>', new StaticSource(500000)),
            declarations: ['turnover' => new NumberType()],
        );

        $this->assertTrue($gate->check(new BooleanType())->isOk());
        $this->assertStringContainsString(
            'is not assignable to',
            $gate->check(new NumberType())->unwrapErr()->describe(),
        );
    }

    #[Test]
    public function a_cyclic_definition_graph_does_not_compile(): void
    {
        $expression = new Expression(
            source: new SymbolSource('a'),
            definitions: new Definitions([
                'a' => new InfixExpression(new SymbolSource('b'), '+', new StaticSource(1)),
                'b' => new InfixExpression(new SymbolSource('a'), '+', new StaticSource(1)),
            ]),
        );

        $result = $expression->compile();

        $this->assertTrue($result->isErr());
        $this->assertStringContainsString('not well-founded', $result->unwrapErr()->describe());
        $this->assertStringContainsString('a → b → a', $result->unwrapErr()->describe());
    }

    #[Test]
    public function parameters_lists_free_variables_not_covered_by_definitions(): void
    {
        $expression = new Expression(
            source: new InfixExpression(
                left: new SymbolSource('PI'),
                operator: '*',
                right: new SymbolSource('radius'),
            ),
            definitions: new Definitions(['PI' => new StaticSource(3.14)]),
        );

        $this->assertSame(['radius'], $expression->parameters());
    }

    #[Test]
    public function parameters_lists_every_uncovered_free_variable(): void
    {
        $expression = new Expression(
            source: new InfixExpression(
                left: new SymbolSource('height'),
                operator: '*',
                right: new SymbolSource('width'),
            ),
        );

        $this->assertSame(['height', 'width'], $expression->parameters());
    }

    #[Test]
    public function parameters_renders_namespaced_symbols_with_dot(): void
    {
        $expression = new Expression(
            source: new InfixExpression(
                left: new SymbolSource('claims', 'quote'),
                operator: '>',
                right: new StaticSource(2),
            ),
        );

        $this->assertSame(['quote.claims'], $expression->parameters());
    }

    #[Test]
    public function a_symbol_cannot_be_both_declared_and_defined(): void
    {
        // Disjoint namespaces: a symbol is a parameter or a derived value,
        // never both — shadowing is unrepresentable, not licensed.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[x] is both declared and defined');

        new Expression(
            source: new SymbolSource('x'),
            definitions: new Definitions(['x' => new StaticSource(1)]),
            declarations: ['x' => new NumberType()],
        );
    }

    #[Test]
    public function an_undeclared_binding_is_stripped_and_cannot_shadow_a_definition(): void
    {
        $program = (new Expression(
            source: new SymbolSource('x'),
            definitions: new Definitions(['x' => new StaticSource(1)]),
        ))->compile()->unwrap();

        // The binding is not in the signature, so it never enters: the
        // definition evaluates as if the caller had passed nothing.
        $this->assertSame(1, $program(['x' => 'oops'])->unwrap()->unwrap());
    }

    #[Test]
    public function with_definitions_swaps_the_definitions(): void
    {
        $expression = new Expression(source: new SymbolSource('x'));

        $this->assertTrue($expression->compile()->isErr());

        $swapped = $expression->withDefinitions(new Definitions(['x' => new StaticSource(7)]));

        $this->assertSame(7, $swapped->compile()->unwrap()->call()->unwrap()->unwrap());
        $this->assertTrue($expression->compile()->isErr(), 'original is unchanged');
    }

}
