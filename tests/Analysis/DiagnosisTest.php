<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Analysis;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Analysis\Diagnosis;
use Superscript\Axiom\Analysis\Diagnostic;
use Superscript\Axiom\Analysis\ErrorRecovery;
use Superscript\Axiom\Analysis\RecoveringCompiler;
use Superscript\Axiom\Analysis\UnreachableEvaluation;
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Program;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\SourceCompilers\AscriptionSourceCompiler;
use Superscript\Axiom\SourceCompilers\MemberAccessSourceCompiler;
use Superscript\Axiom\Sources\Ascription;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\ErrorType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeInference;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\UnknownType;

/**
 * A host source whose compiler judges twice: once about its child, and once
 * about the source itself. The second judgment is configured here rather
 * than derived, so it stands or falls independently of what the child
 * compiled to — which is what makes it a second fault when both are wrong.
 */
final readonly class JudgingSource implements Source
{
    /**
     * @param ?string $refusal What the compiler refuses after compiling the
     *                         child, or null to make no second judgment.
     * @param ?string $blaming The node the refusal names. Null leaves it
     *                         unlocated, so the compiler's own node is
     *                         stamped on it, as on any refusal.
     */
    public function __construct(
        public Source $source,
        public ?string $refusal = null,
        public ?string $blaming = null,
    ) {}
}

/** @internal The dialect contribution that compiles a {@see JudgingSource}. */
final class JudgingExtension extends Extension
{
    public function sourceCompilers(): array
    {
        return [JudgingSource::class => $this->compileJudging(...)];
    }

    private function compileJudging(JudgingSource $source, SourceCompilation $compilation): CompiledSource
    {
        $inner = $compilation->child($source->source, 'source');

        if ($source->refusal !== null) {
            $compilation->reject(new TypeMismatch($source->refusal, path: $source->blaming));
        }

        return $inner;
    }
}

/**
 * Error-tolerant compilation: what a broken expression tells you, and the
 * line between telling you and pretending to have compiled.
 */
#[CoversClass(Diagnosis::class)]
#[CoversClass(Diagnostic::class)]
#[CoversClass(RecoveringCompiler::class)]
#[CoversClass(ErrorRecovery::class)]
#[CoversClass(UnreachableEvaluation::class)]
#[CoversClass(TypeInference::class)]
#[CoversClass(Expression::class)]
#[CoversClass(SourceCompilation::class)]
#[CoversClass(CompiledSource::class)]
#[CoversClass(AscriptionSourceCompiler::class)]
#[CoversClass(MemberAccessSourceCompiler::class)]
#[UsesNamespace('Superscript\\Axiom')]
final class DiagnosisTest extends TestCase
{
    /**
     * @param array<string, Type> $declarations
     */
    private static function diagnose(Source $source, array $declarations = [], ?Definitions $definitions = null): Diagnosis
    {
        return new Expression($source, $definitions ?? new Definitions(), declarations: $declarations)->diagnose();
    }

    /** Diagnose against a dialect that knows {@see JudgingSource}. */
    private static function diagnoseJudging(Source $source): Diagnosis
    {
        return new Expression($source, dialect: Dialect::core()->with(new JudgingExtension()))->diagnose();
    }

    /** @return list<string> */
    private static function messages(Diagnosis $diagnosis): array
    {
        return array_map(fn(Diagnostic $diagnostic) => $diagnostic->message, $diagnosis->diagnostics);
    }

    /** `turnover > 1000 && postcode == 'SW1'`, with the left symbol swappable. */
    private static function gate(string $left): Source
    {
        return new InfixExpression(
            new InfixExpression(new SymbolSource($left), '>', new StaticSource(1000)),
            '&&',
            new InfixExpression(new SymbolSource('postcode'), '==', new StaticSource('SW1')),
        );
    }

    #[Test]
    public function a_sound_expression_is_certified(): void
    {
        $diagnosis = self::diagnose(self::gate('turnover'), [
            'turnover' => new NumberType(),
            'postcode' => new StringType(),
        ]);

        $this->assertSame([], $diagnosis->diagnostics);
        $this->assertSame(['turnover', 'postcode'], $diagnosis->references);
        $this->assertTrue($diagnosis->program()->isOk());
        $this->assertInstanceOf(Program::class, $diagnosis->program()->unwrap());
    }

    #[Test]
    public function a_certified_program_from_a_diagnosis_runs(): void
    {
        $program = self::diagnose(
            new InfixExpression(new SymbolSource('turnover'), '>', new StaticSource(1000)),
            ['turnover' => new NumberType()],
        )->program()->unwrap();

        $this->assertTrue($program(['turnover' => 2000])->unwrap()->unwrap());
    }

    #[Test]
    public function an_unbound_symbol_is_one_diagnostic_and_still_a_reference(): void
    {
        $diagnosis = self::diagnose(new SymbolSource('mystery'));

        $this->assertSame([
            'Unbound symbol [mystery]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
        ], self::messages($diagnosis));
        $this->assertSame(['mystery'], $diagnosis->references);
        $this->assertInstanceOf(ErrorType::class, $diagnosis->returns);
    }

    #[Test]
    public function a_broken_operand_does_not_cascade_and_its_sibling_is_still_checked(): void
    {
        $diagnosis = self::diagnose(self::gate('mystery'), ['postcode' => new StringType()]);

        // One fault: `>` and `&&` both absorb the ErrorType silently, and the
        // right-hand comparison is compiled on its own merits.
        $this->assertCount(1, $diagnosis->diagnostics);
        $this->assertStringStartsWith('Unbound symbol [mystery]', $diagnosis->diagnostics[0]->message);
        $this->assertSame(['mystery', 'postcode'], $diagnosis->references);
    }

    #[Test]
    public function an_operation_over_a_broken_operand_is_itself_broken(): void
    {
        // Not merely "no second diagnostic": the operation must not resolve.
        // ErrorType is Never-shaped and Never is admissible everywhere, so a
        // rule asked about it would answer — `mystery > 1000` would certify
        // as Boolean and a fault would compile away into a sound-looking type.
        foreach ([
            'left' => new InfixExpression(new SymbolSource('mystery'), '>', new StaticSource(1000)),
            'right' => new InfixExpression(new StaticSource(1000), '>', new SymbolSource('mystery')),
            'prefix' => new UnaryExpression('!', new SymbolSource('mystery')),
        ] as $position => $source) {
            $diagnosis = self::diagnose($source);

            $this->assertCount(1, $diagnosis->diagnostics, $position);
            $this->assertStringStartsWith('Unbound symbol [mystery]', $diagnosis->diagnostics[0]->message, $position);
            $this->assertInstanceOf(ErrorType::class, $diagnosis->returns, $position);
        }
    }

    #[Test]
    public function a_sound_sibling_of_a_broken_operand_is_still_checked(): void
    {
        $diagnosis = self::diagnose(new InfixExpression(
            new UnaryExpression('!', new SymbolSource('mystery')),
            '&&',
            new SymbolSource('accepted'),
        ), ['accepted' => new BooleanType()]);

        $this->assertCount(1, $diagnosis->diagnostics);
        $this->assertStringStartsWith('Unbound symbol [mystery]', $diagnosis->diagnostics[0]->message);
        $this->assertSame(['mystery', 'accepted'], $diagnosis->references);
    }

    #[Test]
    public function two_independent_faults_are_two_diagnostics(): void
    {
        $diagnosis = self::diagnose(new InfixExpression(
            new InfixExpression(new SymbolSource('mystery'), '>', new StaticSource(1000)),
            '&&',
            new InfixExpression(new SymbolSource('enigma'), '==', new StaticSource('SW1')),
        ));

        $this->assertSame([
            'Unbound symbol [mystery]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
            'Unbound symbol [enigma]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
        ], self::messages($diagnosis));
        $this->assertSame(['mystery', 'enigma'], $diagnosis->references);
    }

    #[Test]
    public function an_operation_no_rule_resolves_over_two_sound_operands_is_reported(): void
    {
        $diagnosis = self::diagnose(
            new InfixExpression(new SymbolSource('turnover'), '+', new SymbolSource('postcode')),
            ['turnover' => new NumberType(), 'postcode' => new StringType()],
        );

        $this->assertSame(['[+] expects Number and Number; got Number and String.'], self::messages($diagnosis));
        $this->assertSame('$', $diagnosis->diagnostics[0]->path);
        $this->assertSame(['turnover', 'postcode'], $diagnosis->references);
    }

    #[Test]
    public function a_fault_inside_a_definition_is_reported_at_the_edge_that_referenced_it(): void
    {
        $diagnosis = self::diagnose(
            new InfixExpression(new SymbolSource('threshold'), '>', new StaticSource(10)),
            ['turnover' => new NumberType()],
            new Definitions([
                'threshold' => new InfixExpression(new SymbolSource('turnover'), '+', new SymbolSource('missing_rate')),
            ]),
        );

        $this->assertSame([
            'Unbound symbol [missing_rate]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
        ], self::messages($diagnosis));
        $this->assertSame('$.children[0].node.children[0].node.children[1].node', $diagnosis->diagnostics[0]->path);
        $this->assertSame(['turnover', 'missing_rate'], $diagnosis->references);
    }

    #[Test]
    public function references_reach_through_a_sound_definition(): void
    {
        $diagnosis = self::diagnose(
            new InfixExpression(new SymbolSource('threshold'), '>', new StaticSource(10)),
            ['turnover' => new NumberType()],
            new Definitions([
                'threshold' => new InfixExpression(new SymbolSource('turnover'), '+', new StaticSource(5)),
            ]),
        );

        $this->assertSame([], $diagnosis->diagnostics);
        $this->assertSame(['turnover'], $diagnosis->references);
        $this->assertSame($diagnosis->references, $diagnosis->program()->unwrap()->references);
    }

    #[Test]
    public function a_definition_cycle_is_diagnosed_once_and_the_walk_terminates(): void
    {
        $diagnosis = self::diagnose(
            new InfixExpression(new SymbolSource('a'), '+', new SymbolSource('turnover')),
            ['turnover' => new NumberType()],
            new Definitions([
                'a' => new InfixExpression(new SymbolSource('b'), '+', new StaticSource(1)),
                'b' => new InfixExpression(new SymbolSource('a'), '+', new StaticSource(1)),
            ]),
        );

        $this->assertSame([
            'The definition graph is not well-founded; evaluation would recurse without terminating.',
        ], self::messages($diagnosis));
        $this->assertNull($diagnosis->diagnostics[0]->path);
        $this->assertSame('Cyclic symbol definition: a → b → a.', $diagnosis->diagnostics[0]->mismatch->causes[0]->message);

        // The cyclic name is still a dependency of this expression, and the
        // sound operand beside it was still checked and collected.
        $this->assertSame(['a', 'turnover'], $diagnosis->references);
        $this->assertTrue($diagnosis->program()->isErr());
    }

    #[Test]
    public function a_name_on_an_overlapping_cycle_is_never_descended_into(): void
    {
        // a → b, b → a, a → c, c → b. Two cycles overlap and c lies on
        // a → c → b → a, so reading c must stop at c — descending into its
        // body would follow the cycle and report a name further along it.
        $diagnosis = self::diagnose(
            new SymbolSource('c'),
            definitions: new Definitions([
                'a' => new InfixExpression(new SymbolSource('b'), '+', new SymbolSource('c')),
                'b' => new SymbolSource('a'),
                'c' => new SymbolSource('b'),
            ]),
        );

        $this->assertSame(['c'], $diagnosis->references);
        $this->assertInstanceOf(ErrorType::class, $diagnosis->returns);
    }

    #[Test]
    public function a_definition_that_merely_depends_on_a_cycle_is_poisoned_where_it_reads_one(): void
    {
        // dependant is not on the cycle, so it is compiled like any other
        // definition; its ErrorType comes from the poisoned name it reads.
        $diagnosis = self::diagnose(
            new SymbolSource('dependant'),
            definitions: new Definitions([
                'dependant' => new InfixExpression(new SymbolSource('a'), '+', new StaticSource(1)),
                'a' => new SymbolSource('b'),
                'b' => new SymbolSource('a'),
            ]),
        );

        $this->assertSame([
            'The definition graph is not well-founded; evaluation would recurse without terminating.',
        ], self::messages($diagnosis));
        $this->assertSame(['a'], $diagnosis->references);
        $this->assertInstanceOf(ErrorType::class, $diagnosis->returns);
    }

    #[Test]
    public function a_broken_match_arm_leaves_its_siblings_checked_and_the_type_recoverable(): void
    {
        $diagnosis = self::diagnose(
            new MatchExpression(new SymbolSource('band'), [
                new MatchArm(new LiteralPattern('a'), new InfixExpression(new SymbolSource('unknown_rate'), '+', new StaticSource(1))),
                new MatchArm(new WildcardPattern(), new InfixExpression(new SymbolSource('turnover'), '+', new StaticSource(2))),
            ]),
            ['band' => new StringType(), 'turnover' => new NumberType()],
        );

        $this->assertCount(1, $diagnosis->diagnostics);
        $this->assertSame('Match arm 0 cannot be typed.', $diagnosis->diagnostics[0]->message);
        $this->assertSame(['band', 'unknown_rate', 'turnover'], $diagnosis->references);

        // The surviving arm still types the match: ErrorType drops out of the
        // union of arm types.
        $this->assertInstanceOf(NumberType::class, $diagnosis->returns);
    }

    #[Test]
    public function a_broken_match_subject_does_not_gag_the_arms(): void
    {
        $diagnosis = self::diagnose(
            new MatchExpression(new SymbolSource('no_such_subject'), [
                new MatchArm(new LiteralPattern('a'), new InfixExpression(new SymbolSource('also_missing'), '+', new StaticSource(1))),
                new MatchArm(new WildcardPattern(), new StaticSource(2)),
            ]),
        );

        $this->assertSame([
            'The match subject cannot be typed.',
            'Match arm 0 cannot be typed.',
        ], self::messages($diagnosis));
        $this->assertSame(['no_such_subject', 'also_missing'], $diagnosis->references);
    }

    #[Test]
    public function a_refusal_an_error_type_answers_for_is_reported_once_the_fault_below_it_is_fixed(): void
    {
        // Non-exhaustive over a String subject: normally refused.
        $sound = self::diagnose(
            new MatchExpression(new SymbolSource('band'), [new MatchArm(new LiteralPattern('a'), new StaticSource(1))]),
            ['band' => new StringType()],
        );

        $this->assertSame(['This match over String may not be exhaustive, and an unmatched subject is a runtime error; add a wildcard arm.'], self::messages($sound));

        // The same match over a subject that failed reports only the subject:
        // ErrorType is Never, and any set of patterns covers Never. The
        // exhaustiveness refusal surfaces when the subject is declared.
        $broken = self::diagnose(
            new MatchExpression(new SymbolSource('band'), [new MatchArm(new LiteralPattern('a'), new StaticSource(1))]),
        );

        $this->assertSame(['The match subject cannot be typed.'], self::messages($broken));
    }

    #[Test]
    public function a_node_that_refuses_because_of_a_fault_below_it_is_not_a_second_diagnostic(): void
    {
        // Member access needs a structural claim and a failed source makes
        // none, so it absorbs rather than blaming `.premium` for `quote`.
        $diagnosis = self::diagnose(new MemberAccessSource(new SymbolSource('quote'), 'premium'));

        $this->assertSame([
            'Unbound symbol [quote]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
        ], self::messages($diagnosis));
        $this->assertInstanceOf(ErrorType::class, $diagnosis->returns);
    }

    #[Test]
    public function an_ascription_over_a_failed_source_claims_nothing_rather_than_refusing(): void
    {
        // The claim is checked by overlap, and a failed source inhabits
        // nothing — so overlap would refuse for a reason that is the fault
        // below, not a false claim.
        $diagnosis = self::diagnose(new Ascription(new NumberType(), new SymbolSource('quote')));

        $this->assertSame([
            'Unbound symbol [quote]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
        ], self::messages($diagnosis));
        $this->assertInstanceOf(ErrorType::class, $diagnosis->returns);
    }

    #[Test]
    public function a_judgment_over_a_child_that_compiled_is_still_made(): void
    {
        // Absorption is for failed children only: the ascription's claim is
        // checked and the field it promises is certified.
        $diagnosis = self::diagnose(
            new MemberAccessSource(
                new Ascription(new RecordType(['premium' => new NumberType()]), new SymbolSource('quote')),
                'premium',
            ),
            ['quote' => new UnknownType()],
        );

        $this->assertSame([], $diagnosis->diagnostics);
        $this->assertInstanceOf(NumberType::class, $diagnosis->returns);
    }

    #[Test]
    public function a_fault_beside_a_broken_child_is_its_own_diagnostic(): void
    {
        // The host compiler compiles its child and then judges itself. The
        // second judgment says nothing about the child's type, so silencing
        // it because a node below was quarantined would lose a real fault.
        $diagnosis = self::diagnoseJudging(
            new JudgingSource(new SymbolSource('mystery'), refusal: 'This source is not configured.'),
        );

        $this->assertSame([
            'Unbound symbol [mystery]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
            'This source is not configured.',
        ], self::messages($diagnosis));
        $this->assertSame(['$.children[0].node', '$'], array_map(
            fn(Diagnostic $diagnostic) => $diagnostic->path,
            $diagnosis->diagnostics,
        ));
    }

    #[Test]
    public function a_refusal_naming_a_node_already_set_aside_is_the_same_fault_again(): void
    {
        // This compiler blames its child by path. Once that node is
        // quarantined it compiles to ErrorType and never refuses, so the
        // refusal arriving a second time is the one already reported — and
        // diagnosis must record it once and still terminate.
        $diagnosis = self::diagnoseJudging(new JudgingSource(
            new StaticSource(1),
            refusal: 'This source may not be a constant.',
            blaming: '$.children[0].node',
        ));

        $this->assertSame(['This source may not be a constant.'], self::messages($diagnosis));
        $this->assertSame('$.children[0].node', $diagnosis->diagnostics[0]->path);
        $this->assertInstanceOf(ErrorType::class, $diagnosis->returns);
    }

    #[Test]
    public function compile_certifies_what_a_diagnosis_certifies(): void
    {
        $expression = new Expression(self::gate('turnover'), declarations: [
            'turnover' => new NumberType(),
            'postcode' => new StringType(),
        ]);

        $program = $expression->compile()->unwrap();

        $this->assertSame($expression->diagnose()->references, $program->references);
        $this->assertTrue($program(['turnover' => 2000, 'postcode' => 'SW1'])->unwrap()->unwrap());
    }

    /**
     * compile() runs the walk with no recovery state; diagnose()'s first
     * attempt runs it with recovery that has nothing quarantined and nothing
     * poisoned. This pins the two to one verdict per failure kind, absorbing
     * judgment sites included.
     */
    #[Test]
    public function the_first_diagnostic_is_the_refusal_compile_returns(): void
    {
        $expressions = [
            'unbound' => new Expression(self::gate('mystery'), declarations: ['postcode' => new StringType()]),
            'operator' => new Expression(
                new InfixExpression(new SymbolSource('turnover'), '+', new SymbolSource('postcode')),
                declarations: ['turnover' => new NumberType(), 'postcode' => new StringType()],
            ),
            'wrapped' => new Expression(
                new MatchExpression(new SymbolSource('band'), [
                    new MatchArm(new LiteralPattern('a'), new InfixExpression(new StaticSource('x'), '+', new StaticSource(1))),
                    new MatchArm(new WildcardPattern(), new StaticSource(2)),
                ]),
                declarations: ['band' => new StringType()],
            ),
            'cycle' => new Expression(
                new SymbolSource('a'),
                new Definitions([
                    'a' => new InfixExpression(new SymbolSource('b'), '+', new StaticSource(1)),
                    'b' => new InfixExpression(new SymbolSource('a'), '+', new StaticSource(1)),
                ]),
            ),
            'member' => new Expression(new MemberAccessSource(new SymbolSource('quote'), 'premium')),
            'ascription' => new Expression(new Ascription(new NumberType(), new SymbolSource('quote'))),
        ];

        foreach ($expressions as $name => $expression) {
            $refusal = $expression->compile()->unwrapErr();
            $first = $expression->diagnose()->diagnostics[0];

            $this->assertSame($refusal->message, $first->message, $name);
            $this->assertSame($refusal->path, $first->path, $name);
            $this->assertSame($refusal->describe(), $first->mismatch->describe(), $name);
        }
    }

    #[Test]
    public function a_diagnosis_certifies_exactly_when_it_reports_nothing(): void
    {
        $sound = self::diagnose(new StaticSource(1));
        $broken = self::diagnose(new SymbolSource('mystery'));

        $this->assertTrue($sound->program()->isOk());
        $this->assertSame([], $sound->diagnostics);

        $this->assertTrue($broken->program()->isErr());
        $this->assertSame($broken->diagnostics, $broken->program()->unwrapErr());
    }

    #[Test]
    public function a_diagnostic_describes_itself_with_the_node_it_names(): void
    {
        $located = self::diagnose(new SymbolSource('mystery'))->diagnostics[0];

        $this->assertSame(
            '[$] Unbound symbol [mystery]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
            $located->describe(),
        );

        $unlocated = self::diagnose(
            new SymbolSource('a'),
            definitions: new Definitions(['a' => new SymbolSource('a')]),
        )->diagnostics[0];

        $this->assertStringStartsWith('The definition graph is not well-founded', $unlocated->describe());
    }

    #[Test]
    public function an_evaluation_that_never_certified_refuses_to_run(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A node that failed to compile has no evaluation; this program was never certified.');

        UnreachableEvaluation::refuse();
    }
}
