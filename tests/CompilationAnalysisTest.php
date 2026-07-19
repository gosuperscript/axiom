<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Analysis\AnalysisTypeDescriber;
use Superscript\Axiom\Analysis\CompilationAnalysis;
use Superscript\Axiom\Analysis\CompilationChild;
use Superscript\Axiom\Analysis\CompilationNode;
use Superscript\Axiom\Analysis\CompilationRecorder;
use Superscript\Axiom\Analysis\LocatedOperatorSelection;
use Superscript\Axiom\Analysis\OperatorRuleProvenance;
use Superscript\Axiom\Analysis\OperatorSelection;
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Operators\InfixOperatorRule;
use Superscript\Axiom\Operators\BinaryOperatorRule;
use Superscript\Axiom\Operators\IdentifiedOperatorRule;
use Superscript\Axiom\Operators\Operator;
use Superscript\Axiom\Operators\OperatorResolution;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Program;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Tests\Fixtures\CountingSource;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;

#[CoversClass(CompilationAnalysis::class)]
#[CoversClass(CompilationChild::class)]
#[CoversClass(CompilationNode::class)]
#[CoversClass(CompilationRecorder::class)]
#[CoversClass(LocatedOperatorSelection::class)]
#[CoversClass(OperatorRuleProvenance::class)]
#[CoversClass(OperatorSelection::class)]
#[CoversClass(AnalysisTypeDescriber::class)]
#[\PHPUnit\Framework\Attributes\UsesNamespace('Superscript\\Axiom')]
#[CoversClass(Expression::class)]
#[UsesClass(Program::class)]
#[UsesClass(Dialect::class)]
#[UsesClass(Extension::class)]
#[UsesClass(SourceCompilation::class)]
#[UsesClass(CompiledSource::class)]
#[UsesClass(InfixExpression::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(Operator::class)]
#[UsesClass(InfixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\ResolvedOperation::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralType::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeReifier::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeInference::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeEnvironment::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\CoreSourceCompilers::class)]
#[UsesClass(\Superscript\Axiom\CompiledNode::class)]
#[UsesClass(\Superscript\Axiom\BoundOperation::class)]
#[UsesClass(\Superscript\Axiom\DefinitionGraph::class)]
#[UsesClass(\Superscript\Axiom\Definitions::class)]
#[UsesClass(\Superscript\Axiom\UnboundSymbols::class)]
#[UsesClass(\Superscript\Axiom\SourceEvaluation::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\ConstantNode::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\InfixExpressionCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\StaticSourceCompiler::class)]
#[UsesClass(\Superscript\Axiom\Operators\BinaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOperatorResolver::class)]
final class CompilationAnalysisTest extends TestCase
{
    #[Test]
    public function analysis_exposes_the_certified_source_tree_and_selected_rule(): void
    {
        $extension = new class extends Extension {
            public function identifier(): string
            {
                return 'catalogue.compatibility';
            }

            public function operators(): array
            {
                return [
                    Operator::infix('legacy-plus')
                        ->identifiedBy('catalogue.legacy-string-addition')
                        ->takes(new NumberType(), new StringType())
                        ->returns(new NumberType())
                        ->evaluatesWith(fn(int|float $left, string $right): int|float => $left + (float) $right),
                ];
            }

            public function sourceCompilers(): array
            {
                return [
                    CountingSource::class => static fn(CountingSource $source, SourceCompilation $compilation): CompiledSource => $compilation
                        ->constant(new NumberType(), $source->value),
                ];
            }
        };

        $expression = new Expression(
            new InfixExpression(
                new CountingSource(2),
                'legacy-plus',
                new StaticSource('2.5'),
            ),
            dialect: Dialect::core()->with($extension),
        );

        $program = $expression->compile()->unwrap();
        $analysis = $program->analysis;
        $export = $analysis->toArray();

        $this->assertSame(4.5, $program()->unwrap()->unwrap());
        $this->assertSame($analysis, $program->analysis);
        $this->assertSame('Coerce', $export['boundary']);
        $this->assertSame(InfixExpression::class, $export['root']['source']);
        $this->assertSame('axiom.core', $export['root']['extension']);
        $this->assertSame('Number', $export['root']['returns']);
        $this->assertSame('left', $export['root']['children'][0]['role']);
        $this->assertSame(CountingSource::class, $export['root']['children'][0]['node']['source']);
        $this->assertSame('catalogue.compatibility', $export['root']['children'][0]['node']['extension']);
        $this->assertSame('right', $export['root']['children'][1]['role']);
        $this->assertSame('String', $export['root']['children'][1]['node']['returns']);

        $operator = $analysis->operators()[0];

        $this->assertInstanceOf(LocatedOperatorSelection::class, $operator);
        $this->assertSame('$.operators[0]', $operator->path);
        $this->assertSame('$', $operator->sourcePath);
        $this->assertSame('legacy-plus', $operator->selection->operator);
        $this->assertSame('catalogue.legacy-string-addition', $operator->selection->rule->identifier);
        $this->assertSame(InfixOperatorRule::class, $operator->selection->rule->implementation);
        $this->assertSame('catalogue.compatibility', $operator->selection->rule->extension);
        $this->assertSame($analysis->toArray(), $expression->analyze()->unwrap()->toArray());
    }

    #[Test]
    public function serializable_export_redacts_literals_unless_the_caller_explicitly_reveals_them(): void
    {
        $analysis = (new Expression(new StaticSource([
            'public_field' => ['private value'],
        ])))->analyze()->unwrap();

        $redacted = json_encode($analysis, JSON_THROW_ON_ERROR);
        $revealed = json_encode($analysis->toArray(revealLiterals: true), JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('private value', $redacted);
        $this->assertStringContainsString('private value', $revealed);
    }

    #[Test]
    public function an_identified_rule_must_return_a_non_empty_identity(): void
    {
        $rule = new class implements BinaryOperatorRule, IdentifiedOperatorRule {
            public function identifier(): string
            {
                return '';
            }

            public function operator(): string
            {
                return '+';
            }

            public function resolve(Type $left, Type $right): OperatorResolution
            {
                return new ResolvedOperation(new NumberType(), fn() => 1);
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('returned an empty identifier');

        OperatorRuleProvenance::of($rule, 'test');
    }
}
