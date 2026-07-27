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
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Tests\Fixtures\CountingSource;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\OpaqueType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\UnionType;

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
        $this->assertSame([
            'version' => 1,
            'boundary' => 'Coerce',
            'declarations' => [],
            'root' => [
                'path' => '$',
                'source' => InfixExpression::class,
                'extension' => 'axiom.core',
                'returns' => 'Number',
                'operators' => [[
                    'path' => '$.operators[0]',
                    'kind' => 'infix',
                    'operator' => 'legacy-plus',
                    'operands' => ['Number', 'String'],
                    'returns' => 'Number',
                    'rule' => [
                        'identifier' => 'catalogue.legacy-string-addition',
                        'implementation' => InfixOperatorRule::class,
                        'extension' => 'catalogue.compatibility',
                    ],
                ]],
                'children' => [
                    [
                        'role' => 'left',
                        'node' => [
                            'path' => '$.children[0].node',
                            'source' => CountingSource::class,
                            'extension' => 'catalogue.compatibility',
                            'returns' => 'Number',
                            'operators' => [],
                            'children' => [],
                        ],
                    ],
                    [
                        'role' => 'right',
                        'node' => [
                            'path' => '$.children[1].node',
                            'source' => StaticSource::class,
                            'extension' => 'axiom.core',
                            'returns' => 'String',
                            'operators' => [],
                            'children' => [],
                        ],
                    ],
                ],
            ],
        ], $export);

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
        $analysis = new CompilationAnalysis(
            new CompilationNode(StaticSource::class, new LiteralType('private'), 'axiom.core'),
            [
                'boolean' => new LiteralType(true),
                'integer' => new LiteralType(7),
                'float' => new LiteralType(2.5),
                'string' => new LiteralType('private'),
                'option' => new OptionType(new LiteralType('private')),
                'union' => new UnionType(new LiteralType('private'), new LiteralType(7)),
                'list' => new ListType(new LiteralType('private'), 2, 4),
                'dict' => new DictType(new LiteralType('private')),
                'record' => new RecordType(['field' => new LiteralType('private')]),
                'opaque' => new OpaqueType('Example', ['parameter' => new LiteralType('private')]),
            ],
            \Superscript\Axiom\Boundary::Assert,
        );

        $redacted = json_encode($analysis, JSON_THROW_ON_ERROR);
        $revealedExport = $analysis->toArray(revealLiterals: true);
        $revealed = json_encode($revealedExport, JSON_THROW_ON_ERROR);

        $this->assertSame([
            'boolean' => 'Boolean',
            'integer' => 'Number',
            'float' => 'Number',
            'string' => 'String',
            'option' => 'String?',
            'union' => 'String | Number',
            'list' => 'List<String, 2..4>',
            'dict' => 'Dict<String>',
            'record' => '{field: String}',
            'opaque' => 'Example<parameter: String>',
        ], $analysis->toArray()['declarations']);
        $this->assertSame([
            'boolean' => 'true',
            'integer' => '7',
            'float' => '2.5',
            'string' => "'private'",
            'option' => "'private'?",
            'union' => "'private' | 7",
            'list' => "List<'private', 2..4>",
            'dict' => "Dict<'private'>",
            'record' => "{field: 'private'}",
            'opaque' => "Example<parameter: 'private'>",
        ], $revealedExport['declarations']);
        $this->assertStringNotContainsString('private value', $redacted);
        $this->assertStringNotContainsString('private', $redacted);
        $this->assertStringContainsString('private', $revealed);
        $this->assertSame('String', $analysis->root->toArray()['returns']);

        $selection = new OperatorSelection(
            'prefix',
            'inspect',
            [new LiteralType('private')],
            new LiteralType('private'),
            new OperatorRuleProvenance('inspect', self::class, 'test'),
        );

        $this->assertSame(['String'], $selection->toArray('$')['operands']);
        $this->assertSame('String', $selection->toArray('$')['returns']);
    }

    #[Test]
    public function flat_operator_view_walks_every_nested_node_in_deterministic_order(): void
    {
        $rule = new OperatorRuleProvenance('test.rule', self::class, 'test');
        $rootSelection = new OperatorSelection('infix', '+', [new NumberType(), new NumberType()], new NumberType(), $rule);
        $childSelection = new OperatorSelection('prefix', '-', [new NumberType()], new NumberType(), $rule);
        $analysis = new CompilationAnalysis(
            new CompilationNode(
                InfixExpression::class,
                new NumberType(),
                'axiom.core',
                [new CompilationChild(new CompilationNode(
                    StaticSource::class,
                    new NumberType(),
                    'axiom.core',
                    operators: [$childSelection],
                ), 'left')],
                [$rootSelection],
            ),
            [],
            \Superscript\Axiom\Boundary::Coerce,
        );

        $operators = $analysis->operators();

        $this->assertCount(2, $operators);
        $this->assertSame('$.operators[0]', $operators[0]->path);
        $this->assertSame('$', $operators[0]->sourcePath);
        $this->assertSame('$.children[0].node.operators[0]', $operators[1]->path);
        $this->assertSame('$.children[0].node', $operators[1]->sourcePath);
        $this->assertSame($childSelection, $operators[1]->selection);
    }

    #[Test]
    public function provenance_falls_back_to_the_rule_class_and_serializes_every_field(): void
    {
        $rule = new class implements BinaryOperatorRule {
            public function operator(): string
            {
                return 'fallback';
            }

            public function resolve(Type $left, Type $right): OperatorResolution
            {
                return new ResolvedOperation(new NumberType(), fn() => 1);
            }
        };

        $provenance = OperatorRuleProvenance::of($rule, 'catalogue.fallback');

        $this->assertSame([
            'identifier' => $rule::class,
            'implementation' => $rule::class,
            'extension' => 'catalogue.fallback',
        ], $provenance->toArray());
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

    /**
     * A role is how a caller tells one child from another — the naming a
     * consumer maps onto its own vocabulary when it reads types off an
     * analysis. A match compiles two children per arm, and both must be
     * addressable: with every arm's pattern named alike, a two-arm match
     * would report one name twice and the analysis could not say which
     * pattern a type belonged to.
     */
    #[Test]
    public function every_child_of_a_match_is_addressable_by_its_role(): void
    {
        $analysis = (new Expression(
            new MatchExpression(new SymbolSource('n'), [
                new MatchArm(new ExpressionPattern(new StaticSource(5)), new StaticSource('five')),
                new MatchArm(new ExpressionPattern(new StaticSource(10)), new StaticSource('ten')),
                new MatchArm(new WildcardPattern(), new StaticSource('other')),
            ]),
            declarations: ['n' => new NumberType()],
        ))->analyze()->unwrap();

        $roles = array_map(
            fn(CompilationChild $child): ?string => $child->role,
            $analysis->root->children,
        );

        $this->assertSame([
            'subject',
            'arm.0.pattern',
            'arm.0.expression',
            'arm.1.pattern',
            'arm.1.expression',
            'arm.2.expression',
        ], $roles);
        $this->assertSame($roles, array_unique($roles), 'Two children share a role, so neither can be addressed.');
    }
}
