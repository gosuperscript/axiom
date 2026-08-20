<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Program;
use Superscript\Axiom\ReferencePath;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\TypePattern;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Tests\Fixtures\Money;
use Superscript\Axiom\Tests\Fixtures\MoneyExtension;
use Superscript\Axiom\Tests\Fixtures\MoneyType;
use Superscript\Axiom\Types\LiteralType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\UnionType;

/**
 * Type patterns eliminate a union: an arm matches when the subject value
 * inhabits the pattern's type, the subject reference is narrowed to that
 * member inside the arm, and the pattern proves coverage for every member
 * assignable to it. The recurring subject is a question that is answered,
 * explicitly unanswered ('novalue' is a chosen answer carrying no value),
 * or not answered yet.
 */
#[CoversClass(\Superscript\Axiom\SourceCompilation::class)]
#[CoversClass(\Superscript\Axiom\SourceCompilers\MatchExpressionCompiler::class)]
#[CoversClass(\Superscript\Axiom\Sources\TypePattern::class)]
#[CoversClass(\Superscript\Axiom\Types\TypeEnvironment::class)]
#[CoversClass(\Superscript\Axiom\Types\TypeInference::class)]
#[UsesClass(\Superscript\Axiom\Analysis\CompilationAnalysis::class)]
#[UsesClass(\Superscript\Axiom\Analysis\CompilationChild::class)]
#[UsesClass(\Superscript\Axiom\Analysis\CompilationNode::class)]
#[UsesClass(\Superscript\Axiom\Analysis\CompilationRecorder::class)]
#[UsesClass(\Superscript\Axiom\Analysis\OperatorRuleProvenance::class)]
#[UsesClass(\Superscript\Axiom\Analysis\OperatorSelection::class)]
#[UsesClass(\Superscript\Axiom\Analysis\RecoveringCompiler::class)]
#[UsesClass(\Superscript\Axiom\Analysis\References::class)]
#[UsesClass(\Superscript\Axiom\Bindings::class)]
#[UsesClass(\Superscript\Axiom\BoundOperation::class)]
#[UsesClass(\Superscript\Axiom\CompiledNode::class)]
#[UsesClass(\Superscript\Axiom\CompiledSource::class)]
#[UsesClass(\Superscript\Axiom\CoreSourceCompilers::class)]
#[UsesClass(\Superscript\Axiom\Definitions::class)]
#[UsesClass(\Superscript\Axiom\Dialect::class)]
#[UsesClass(\Superscript\Axiom\Exceptions\CompilationAborted::class)]
#[UsesClass(\Superscript\Axiom\Exceptions\TransformValueException::class)]
#[UsesClass(\Superscript\Axiom\Expression::class)]
#[UsesClass(\Superscript\Axiom\Extension::class)]
#[UsesClass(\Superscript\Axiom\Fields\OpaqueFieldRegistry::class)]
#[UsesClass(\Superscript\Axiom\Operators\BinaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\Coalesce::class)]
#[UsesClass(\Superscript\Axiom\Operators\Connective::class)]
#[UsesClass(\Superscript\Axiom\Operators\Equality::class)]
#[UsesClass(\Superscript\Axiom\Operators\Has::class)]
#[UsesClass(\Superscript\Axiom\Operators\In::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithOperands::class)]
#[UsesClass(\Superscript\Axiom\Operators\InfixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\Intersects::class)]
#[UsesClass(\Superscript\Axiom\Operators\Operator::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRule::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleBuilder::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithOperand::class)]
#[UsesClass(\Superscript\Axiom\Operators\PrefixOperatorRuleWithReturn::class)]
#[UsesClass(\Superscript\Axiom\Operators\ResolvedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnaryOperatorResolver::class)]
#[UsesClass(\Superscript\Axiom\Operators\UnsupportedOperation::class)]
#[UsesClass(\Superscript\Axiom\Operators\ValueEquality::class)]
#[UsesClass(\Superscript\Axiom\Program::class)]
#[UsesClass(\Superscript\Axiom\ReferencePath::class)]
#[UsesClass(\Superscript\Axiom\Runtime::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\ConstantNode::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\InfixExpressionCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\ReferencePathCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\StaticSourceCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceCompilers\SymbolSourceCompiler::class)]
#[UsesClass(\Superscript\Axiom\SourceEvaluation::class)]
#[UsesClass(\Superscript\Axiom\Sources\InfixExpression::class)]
#[UsesClass(\Superscript\Axiom\Sources\LiteralPattern::class)]
#[UsesClass(\Superscript\Axiom\Sources\MatchArm::class)]
#[UsesClass(\Superscript\Axiom\Sources\MatchExpression::class)]
#[UsesClass(\Superscript\Axiom\Sources\StaticSource::class)]
#[UsesClass(\Superscript\Axiom\Sources\SymbolSource::class)]
#[UsesClass(\Superscript\Axiom\Types\BooleanType::class)]
#[UsesClass(\Superscript\Axiom\Types\InfixExpressionTyping::class)]
#[UsesClass(\Superscript\Axiom\Types\ListType::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralType::class)]
#[UsesClass(\Superscript\Axiom\Types\LiteralTypeRegistry::class)]
#[UsesClass(\Superscript\Axiom\Types\NumberType::class)]
#[UsesClass(\Superscript\Axiom\Types\PresentType::class)]
#[UsesClass(\Superscript\Axiom\Types\RecordProperty::class)]
#[UsesClass(\Superscript\Axiom\Types\RecordType::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\BooleanShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\LiteralShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\NumberShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\OpaqueShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\ShapeDomain::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\StringShape::class)]
#[UsesClass(\Superscript\Axiom\Types\Shapes\UnionShape::class)]
#[UsesClass(\Superscript\Axiom\Types\StringType::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeDescriber::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeRelations::class)]
#[UsesClass(\Superscript\Axiom\Types\UnionType::class)]
final class TypePatternTest extends TestCase
{
    private static function answerType(): UnionType
    {
        return new UnionType(new LiteralType('unanswered'), new LiteralType('novalue'), new NumberType());
    }

    /** match limit { Number => limit <op> <value>, _ => false } */
    private static function guarded(Source $subject, Source $comparison): MatchExpression
    {
        return new MatchExpression($subject, [
            new MatchArm(new TypePattern(new NumberType()), $comparison),
            new MatchArm(new WildcardPattern(), new StaticSource(false)),
        ]);
    }

    private static function program(Source $source, Type $limit): Program
    {
        return (new Expression($source, declarations: ['limit' => $limit]))->compile()->unwrap();
    }

    #[Test]
    public function a_type_pattern_narrows_the_subject_so_the_member_rules_judge_the_arm(): void
    {
        $program = self::program(
            self::guarded(
                new ReferencePath('limit'),
                new InfixExpression(new ReferencePath('limit'), '>', new StaticSource(100_000)),
            ),
            self::answerType(),
        );

        $this->assertTrue($program(['limit' => 250_000])->unwrap()->unwrap());
        $this->assertFalse($program(['limit' => 50_000])->unwrap()->unwrap());
        $this->assertFalse($program(['limit' => 'unanswered'])->unwrap()->unwrap());
        $this->assertFalse($program(['limit' => 'novalue'])->unwrap()->unwrap());
    }

    #[Test]
    public function the_boundary_coerces_into_the_union_before_the_match_reads_it(): void
    {
        $program = self::program(
            self::guarded(
                new ReferencePath('limit'),
                new InfixExpression(new ReferencePath('limit'), '>', new StaticSource(100_000)),
            ),
            self::answerType(),
        );

        $this->assertTrue($program(['limit' => '250000'])->unwrap()->unwrap());
    }

    #[Test]
    public function the_bare_comparison_over_the_union_refuses_to_compile(): void
    {
        $compiled = (new Expression(
            new InfixExpression(new ReferencePath('limit'), '>', new StaticSource(100_000)),
            declarations: ['limit' => self::answerType()],
        ))->compile();

        $this->assertTrue($compiled->isErr());
    }

    #[Test]
    public function a_structural_input_path_narrows_as_one_reference(): void
    {
        $program = (new Expression(
            self::guarded(
                new ReferencePath('answers', 'limit'),
                new InfixExpression(new ReferencePath('answers', 'limit'), '>', new StaticSource(100_000)),
            ),
            declarations: ['answers' => new RecordType(['limit' => self::answerType()])],
        ))->compile()->unwrap();

        $this->assertTrue($program(['answers' => ['limit' => 250_000]])->unwrap()->unwrap());
        $this->assertFalse($program(['answers' => ['limit' => 50_000]])->unwrap()->unwrap());
        $this->assertFalse($program(['answers' => ['limit' => 'novalue']])->unwrap()->unwrap());
    }

    #[Test]
    public function a_legacy_symbol_subject_narrows_through_its_reference(): void
    {
        $program = self::program(
            self::guarded(
                new SymbolSource('limit'),
                new InfixExpression(new SymbolSource('limit'), '>', new StaticSource(100_000)),
            ),
            self::answerType(),
        );

        $this->assertTrue($program(['limit' => 250_000])->unwrap()->unwrap());
        $this->assertFalse($program(['limit' => 'unanswered'])->unwrap()->unwrap());
    }

    #[Test]
    public function type_patterns_prove_exhaustiveness_without_a_wildcard(): void
    {
        $program = self::program(
            new MatchExpression(new ReferencePath('limit'), [
                new MatchArm(new LiteralPattern('unanswered'), new StaticSource(false)),
                new MatchArm(new LiteralPattern('novalue'), new StaticSource(false)),
                new MatchArm(
                    new TypePattern(new NumberType()),
                    new InfixExpression(new ReferencePath('limit'), '>', new StaticSource(100_000)),
                ),
            ]),
            self::answerType(),
        );

        $this->assertTrue($program(['limit' => 250_000])->unwrap()->unwrap());
        $this->assertFalse($program(['limit' => 'novalue'])->unwrap()->unwrap());
    }

    #[Test]
    public function a_member_no_arm_claims_is_a_compile_error(): void
    {
        $compiled = (new Expression(
            new MatchExpression(new ReferencePath('limit'), [
                new MatchArm(new LiteralPattern('unanswered'), new StaticSource(false)),
                new MatchArm(new LiteralPattern('novalue'), new StaticSource(false)),
            ]),
            declarations: ['limit' => self::answerType()],
        ))->compile();

        $this->assertTrue($compiled->isErr());
        $this->assertStringContainsString('may not be exhaustive', $compiled->unwrapErr()->describe());
    }

    #[Test]
    public function a_type_pattern_covers_every_member_assignable_to_it(): void
    {
        $program = self::program(
            new MatchExpression(new ReferencePath('limit'), [
                new MatchArm(new TypePattern(new NumberType()), new StaticSource('numeric')),
                new MatchArm(new LiteralPattern('x'), new StaticSource('lettered')),
            ]),
            new UnionType(new LiteralType(5), new LiteralType('x')),
        );

        $this->assertSame('numeric', $program(['limit' => 5])->unwrap()->unwrap());
        $this->assertSame('lettered', $program(['limit' => 'x'])->unwrap()->unwrap());
    }

    #[Test]
    public function an_arm_whose_type_shares_no_values_with_the_subject_refuses(): void
    {
        $compiled = (new Expression(
            new MatchExpression(new ReferencePath('limit'), [
                new MatchArm(new TypePattern(new NumberType()), new StaticSource(true)),
                new MatchArm(new WildcardPattern(), new StaticSource(false)),
            ]),
            declarations: ['limit' => new UnionType(new LiteralType('a'), new LiteralType('b'))],
        ))->compile();

        $this->assertTrue($compiled->isErr());
        $this->assertStringContainsString('can never match', $compiled->unwrapErr()->describe());
    }

    #[Test]
    public function a_literal_arm_beside_a_domain_member_asks_inhabitation_not_equality(): void
    {
        $program = (new Expression(
            new MatchExpression(new ReferencePath('limit'), [
                new MatchArm(new LiteralPattern('novalue'), new StaticSource(true)),
                new MatchArm(new WildcardPattern(), new StaticSource(false)),
            ]),
            declarations: ['limit' => new UnionType(new LiteralType('novalue'), new MoneyType('GBP'))],
        ))->compile()->unwrap();

        $this->assertTrue($program(['limit' => 'novalue'])->unwrap()->unwrap());
        $this->assertFalse($program(['limit' => new Money(1_000, 'GBP')])->unwrap()->unwrap());
    }

    #[Test]
    public function a_domain_member_narrows_to_its_own_package_rules(): void
    {
        $program = (new Expression(
            new MatchExpression(new ReferencePath('limit'), [
                new MatchArm(
                    new TypePattern(new MoneyType('GBP')),
                    new InfixExpression(new ReferencePath('limit'), '==', new StaticSource(new Money(10_000_000, 'GBP'))),
                ),
                new MatchArm(new WildcardPattern(), new StaticSource(false)),
            ]),
            dialect: Dialect::core()->with(new MoneyExtension(['GBP'])),
            declarations: ['limit' => new UnionType(new LiteralType('novalue'), new MoneyType('GBP'))],
        ))->compile()->unwrap();

        $this->assertTrue($program(['limit' => new Money(10_000_000, 'GBP')])->unwrap()->unwrap());
        $this->assertFalse($program(['limit' => new Money(5_000_000, 'GBP')])->unwrap()->unwrap());
        $this->assertFalse($program(['limit' => 'novalue'])->unwrap()->unwrap());
    }

    #[Test]
    public function a_subject_without_a_reference_still_selects_by_type(): void
    {
        $program = (new Expression(
            new MatchExpression(new StaticSource(5), [
                new MatchArm(new TypePattern(new NumberType()), new StaticSource('numeric')),
                new MatchArm(new WildcardPattern(), new StaticSource('other')),
            ]),
        ))->compile()->unwrap();

        $this->assertSame('numeric', $program([])->unwrap()->unwrap());
    }

    #[Test]
    public function a_narrowed_arm_can_return_the_member_itself(): void
    {
        // (limit ?? 0) + 100, spelled as its elimination:
        // match limit { Number => limit, _ => 0 } + 100
        $program = self::program(
            new InfixExpression(
                new MatchExpression(new ReferencePath('limit'), [
                    new MatchArm(new TypePattern(new NumberType()), new ReferencePath('limit')),
                    new MatchArm(new WildcardPattern(), new StaticSource(0)),
                ]),
                '+',
                new StaticSource(100),
            ),
            self::answerType(),
        );

        $this->assertSame(250_100, $program(['limit' => 250_000])->unwrap()->unwrap());
        $this->assertSame(100, $program(['limit' => 'unanswered'])->unwrap()->unwrap());
        $this->assertSame(100, $program(['limit' => 'novalue'])->unwrap()->unwrap());
    }

    #[Test]
    public function an_array_literal_arm_still_compares_by_value_equality(): void
    {
        $program = (new Expression(
            new MatchExpression(new StaticSource([1, 2]), [
                new MatchArm(new LiteralPattern([1, 2]), new StaticSource('pair')),
                new MatchArm(new WildcardPattern(), new StaticSource('other')),
            ]),
        ))->compile()->unwrap();

        $this->assertSame('pair', $program([])->unwrap()->unwrap());
    }

    #[Test]
    public function a_type_pattern_describes_as_its_type(): void
    {
        $pattern = new TypePattern(new NumberType());

        $this->assertSame(TypeDescriber::describe(new NumberType()), $pattern->describe());
    }
}
