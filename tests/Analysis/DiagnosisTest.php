<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Analysis;

use Closure;
use InvalidArgumentException;
use LogicException;
use ReflectionFunction;
use ReflectionProperty;
use stdClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Analysis\Diagnosis;
use Superscript\Axiom\Analysis\ErrorRecovery;
use Superscript\Axiom\Analysis\CompilationNode;
use Superscript\Axiom\Analysis\RecoveringCompiler;
use Superscript\Axiom\Analysis\References;
use Superscript\Axiom\Analysis\CompilationState;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Dialect;
use Superscript\Axiom\Exceptions\CompilationAborted;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Operators\ResolvedOperation;
use Superscript\Axiom\Program;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;
use Superscript\Axiom\SourceCompilers\AscriptionSourceCompiler;
use Superscript\Axiom\SourceCompilers\MemberAccessSourceCompiler;
use Superscript\Axiom\Sources\Ascription;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\DefaultValue;
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
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeInference;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\UnknownType;

/**
 * A host source compiled the way the plugin guide documents: certify the
 * child's present value, then map it to a native result of a claimed type.
 * The claim is the whole point — it is what a compiler is allowed to say
 * about a child it certified, and what it may not say about one that failed.
 */
final readonly class ClaimingSource implements Source
{
    public function __construct(public Source $source) {}
}

/** @internal The dialect contribution that compiles a {@see ClaimingSource}. */
final class ClaimingExtension extends Extension
{
    public function sourceCompilers(): array
    {
        return [ClaimingSource::class => $this->compileClaiming(...)];
    }

    private function compileClaiming(ClaimingSource $source, SourceCompilation $compilation): CompiledSource
    {
        return $compilation->child($source->source, 'source')
            ->expectPresent(new NumberType())
            ->mapPresent(new NumberType(), fn(int|float $value) => $value * 2);
    }
}

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
 * A host source whose compiler abandons its first child: it catches the
 * child's refusal and compiles the second one in its place. This is the
 * documented catch-the-abort pattern, and the reason a child that refuses
 * must still hold the index it claimed.
 */
final readonly class AbandoningSource implements Source
{
    public function __construct(
        public Source $abandoned,
        public Source $kept,
    ) {}
}

/** @internal The dialect contribution that compiles an {@see AbandoningSource}. */
final class AbandoningExtension extends Extension
{
    public function sourceCompilers(): array
    {
        return [AbandoningSource::class => $this->compileAbandoning(...)];
    }

    private function compileAbandoning(AbandoningSource $source, SourceCompilation $compilation): CompiledSource
    {
        try {
            $compilation->child($source->abandoned, 'abandoned');
        } catch (CompilationAborted) {
            // Whatever the first child refused is this compiler's own
            // business, and it compiles the second child regardless.
        }

        return $compilation->child($source->kept, 'kept');
    }
}

/**
 * A host source whose compiler keeps the compilation capability it was
 * handed, so a test can ask what state the compiler behind it was carrying.
 */
final readonly class CapturingSource implements Source {}

/** @internal The dialect contribution that compiles a {@see CapturingSource}. */
final class CapturingExtension extends Extension
{
    public ?SourceCompilation $captured = null;

    public function sourceCompilers(): array
    {
        return [CapturingSource::class => $this->compileCapturing(...)];
    }

    /** The compiler that ran this compilation, reached through the door it compiles children with. */
    public function compiler(): TypeInference
    {
        $compilation = $this->captured ?? throw new LogicException('Nothing was compiled.');
        $door = new ReflectionProperty(SourceCompilation::class, 'compileNode')->getValue($compilation);

        assert($door instanceof Closure);
        $compiler = new ReflectionFunction($door)->getClosureThis();
        assert($compiler instanceof TypeInference);

        return $compiler;
    }

    private function compileCapturing(CapturingSource $source, SourceCompilation $compilation): CompiledSource
    {
        $this->captured = $compilation;

        return $compilation->produces(new NumberType(), static fn(): int => 1);
    }
}

/**
 * Error-tolerant compilation: what a broken expression tells you, and the
 * line between telling you and pretending to have compiled.
 */
#[CoversClass(Diagnosis::class)]
#[CoversClass(RecoveringCompiler::class)]
#[CoversClass(ErrorRecovery::class)]
#[CoversClass(References::class)]
#[CoversClass(TypeInference::class)]
#[CoversClass(CompilationNode::class)]
#[CoversClass(CompiledNode::class)]
#[CoversClass(ResolvedOperation::class)]
#[CoversClass(Expression::class)]
#[CoversClass(SourceCompilation::class)]
#[CoversClass(CompiledSource::class)]
#[CoversClass(AscriptionSourceCompiler::class)]
#[CoversClass(MemberAccessSourceCompiler::class)]
#[CoversClass(\Superscript\Axiom\SourceCompilers\AdmissionNode::class)]
#[CoversClass(\Superscript\Axiom\SourceCompilers\CoerceSourceCompiler::class)]
#[CoversClass(\Superscript\Axiom\SourceCompilers\DefaultValueSourceCompiler::class)]
#[CoversClass(\Superscript\Axiom\CompiledSources::class)]
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

    /** Diagnose against a dialect that knows {@see ClaimingSource}. */
    private static function diagnoseClaiming(Source $source): Diagnosis
    {
        return new Expression($source, dialect: Dialect::core()->with(new ClaimingExtension()))->diagnose();
    }

    /** Diagnose against a dialect that knows {@see JudgingSource}. */
    private static function diagnoseJudging(Source $source): Diagnosis
    {
        return new Expression($source, dialect: Dialect::core()->with(new JudgingExtension()))->diagnose();
    }

    /**
     * Diagnose against a dialect that knows {@see AbandoningSource}.
     *
     * @param array<string, Type> $declarations
     */
    private static function diagnoseAbandoning(Source $source, array $declarations = []): Diagnosis
    {
        return new Expression(
            $source,
            dialect: Dialect::core()->with(new AbandoningExtension()),
            declarations: $declarations,
        )->diagnose();
    }

    /** @return list<string> */
    private static function messages(Diagnosis $diagnosis): array
    {
        return array_map(fn(TypeMismatch $diagnostic) => $diagnostic->message, $diagnosis->diagnostics);
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
        $this->assertNull($diagnosis->returns);
    }

    #[Test]
    public function a_broken_operand_does_not_cascade_and_its_sibling_is_still_checked(): void
    {
        $diagnosis = self::diagnose(self::gate('mystery'), ['postcode' => new StringType()]);

        // One fault: `>` and `&&` both absorb the failed operand silently,
        // and the right-hand comparison is compiled on its own merits.
        $this->assertCount(1, $diagnosis->diagnostics);
        $this->assertStringStartsWith('Unbound symbol [mystery]', $diagnosis->diagnostics[0]->message);
        $this->assertSame(['mystery', 'postcode'], $diagnosis->references);
    }

    #[Test]
    public function an_operation_over_a_broken_operand_is_itself_broken(): void
    {
        // Not merely "no second diagnostic": the operation must not resolve.
        // A failed operand has no type to bind a rule from, and inventing one
        // — anything a rule would answer about — would certify `mystery > 1000`
        // as Boolean and compile a fault away into a sound-looking type.
        foreach ([
            'left' => new InfixExpression(new SymbolSource('mystery'), '>', new StaticSource(1000)),
            'right' => new InfixExpression(new StaticSource(1000), '>', new SymbolSource('mystery')),
            'prefix' => new UnaryExpression('!', new SymbolSource('mystery')),
        ] as $position => $source) {
            $diagnosis = self::diagnose($source);

            $this->assertCount(1, $diagnosis->diagnostics, $position);
            $this->assertStringStartsWith('Unbound symbol [mystery]', $diagnosis->diagnostics[0]->message, $position);
            $this->assertNull($diagnosis->returns, $position);
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
            'Cyclic symbol definition: a → b → a.',
        ], self::messages($diagnosis));
        $this->assertNotNull($diagnosis->diagnostics[0]->path);

        // The cyclic name is still a dependency of this expression, and the
        // sound operand beside it was still checked and collected.
        $this->assertSame(['a', 'turnover'], $diagnosis->references);
        $this->assertTrue($diagnosis->program()->isErr());
    }

    #[Test]
    public function overlapping_cycles_are_each_reported_where_a_reference_closes_them(): void
    {
        // a → b + c, b → a, c → b. Reading c descends c → b → a and meets
        // both of a's operands: b closes b → a → b, and c closes
        // c → b → a → c. Each reference that closes a cycle is its own
        // fault, and the walk terminates once both are set aside.
        $diagnosis = self::diagnose(
            new SymbolSource('c'),
            definitions: new Definitions([
                'a' => new InfixExpression(new SymbolSource('b'), '+', new SymbolSource('c')),
                'b' => new SymbolSource('a'),
                'c' => new SymbolSource('b'),
            ]),
        );

        $this->assertSame([
            'Cyclic symbol definition: b → a → b.',
            'Cyclic symbol definition: c → b → a → c.',
        ], self::messages($diagnosis));
        $this->assertSame(['b', 'c'], $diagnosis->references);
        $this->assertNull($diagnosis->returns);
    }

    #[Test]
    public function a_definition_that_merely_depends_on_a_cycle_fails_where_it_reads_one(): void
    {
        // dependant is not on the cycle, so the chain the refusal names
        // starts at the cycle — a → b → a — not at the definition that
        // merely led the descent to it.
        $diagnosis = self::diagnose(
            new SymbolSource('dependant'),
            definitions: new Definitions([
                'dependant' => new InfixExpression(new SymbolSource('a'), '+', new StaticSource(1)),
                'a' => new SymbolSource('b'),
                'b' => new SymbolSource('a'),
            ]),
        );

        $this->assertSame([
            'Cyclic symbol definition: a → b → a.',
        ], self::messages($diagnosis));
        $this->assertSame(['a'], $diagnosis->references);
        $this->assertNull($diagnosis->returns);
    }

    #[Test]
    public function a_pure_cycle_reports_the_name_that_closed_it_as_a_read(): void
    {
        // Nothing can ever answer for a cyclic name, but the expression
        // still depends on it — reported like an unbound name is.
        $diagnosis = self::diagnose(
            new SymbolSource('a'),
            definitions: new Definitions(['a' => new SymbolSource('b'), 'b' => new SymbolSource('a')]),
        );

        $this->assertSame(['a'], $diagnosis->references);
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

        // The surviving arm still types the match: the compiler's mark for
        // the arm it gave up on drops out of the union of arm types. A
        // diagnostic and a sound root type therefore co-occur — the root
        // itself compiled, and only a root that did not is typeless.
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
        // a subject with no type is put to no coverage question at all. The
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
        $this->assertNull($diagnosis->returns);
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
        $this->assertNull($diagnosis->returns);
    }

    #[Test]
    public function a_compiler_that_maps_a_failed_child_claims_nothing_about_the_result(): void
    {
        // expectPresent() passes a failed child through rather than judging
        // it. Nothing but absorption at the door that follows keeps the
        // claimed NumberType off a subtree that never compiled.
        $diagnosis = self::diagnoseClaiming(new ClaimingSource(new SymbolSource('mystery')));

        $this->assertSame([
            'Unbound symbol [mystery]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
        ], self::messages($diagnosis));
        $this->assertNull($diagnosis->returns);
        $this->assertTrue($diagnosis->program()->isErr());
    }

    #[Test]
    public function a_compiler_that_maps_a_child_that_compiled_still_claims_its_type(): void
    {
        // Absorption is for failed children only: the same compiler over a
        // sound child certifies, claims Number, and runs.
        $diagnosis = self::diagnoseClaiming(new ClaimingSource(new StaticSource(21)));

        $this->assertSame([], $diagnosis->diagnostics);
        $this->assertInstanceOf(NumberType::class, $diagnosis->returns);
        $this->assertSame(42, $diagnosis->program()->unwrap()()->unwrap()->unwrap());
    }

    #[Test]
    public function a_coercion_over_a_failed_source_claims_nothing_rather_than_admitting(): void
    {
        // Coercion is admission policy, not a type judgment, so it makes no
        // judgment that could absorb — the bridge itself must not claim the
        // coerced type over a source that never compiled.
        $diagnosis = self::diagnose(new Coerce(new NumberType(), new SymbolSource('quote')));

        $this->assertSame([
            'Unbound symbol [quote]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
        ], self::messages($diagnosis));
        $this->assertNull($diagnosis->returns);
    }

    #[Test]
    public function a_default_over_a_failed_source_claims_nothing_rather_than_deciding(): void
    {
        // Whether a default is needed is a question about what the source
        // promises, and a failed source promises nothing.
        $diagnosis = self::diagnose(new DefaultValue(new SymbolSource('quote'), 0));

        $this->assertSame([
            'Unbound symbol [quote]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
        ], self::messages($diagnosis));
        $this->assertNull($diagnosis->returns);
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
            fn(TypeMismatch $diagnostic) => $diagnostic->path,
            $diagnosis->diagnostics,
        ));
    }

    #[Test]
    public function a_refusal_naming_a_node_already_set_aside_is_the_same_fault_again(): void
    {
        // This compiler blames its child by path. Once that node is
        // quarantined it compiles to a failed node and never refuses, so the
        // refusal arriving a second time is the one already reported — and
        // diagnosis must record it once and still terminate.
        $diagnosis = self::diagnoseJudging(new JudgingSource(
            new StaticSource(1),
            refusal: 'This source may not be a constant.',
            blaming: '$.children[0].node',
        ));

        $this->assertSame(['This source may not be a constant.'], self::messages($diagnosis));
        $this->assertSame('$.children[0].node', $diagnosis->diagnostics[0]->path);
        $this->assertNull($diagnosis->returns);
    }

    #[Test]
    public function a_child_a_compiler_abandons_still_holds_its_position_across_attempts(): void
    {
        // The first child refuses and its compiler carries on without it, so
        // the second child is the third node compiled but the second child
        // recorded. Its own fault is met at `children[1]` in the attempt that
        // reports it, and the next attempt — which sets that node aside and
        // meets the first child's refusal again — must address the same node
        // by the same path, or the fault is reported twice.
        $diagnosis = self::diagnoseAbandoning(
            new AbandoningSource(
                new SymbolSource('mystery'),
                new InfixExpression(new SymbolSource('turnover'), '+', new SymbolSource('postcode')),
            ),
            ['turnover' => new NumberType(), 'postcode' => new StringType()],
        );

        $this->assertSame(['[+] expects Number and Number; got Number and String.'], self::messages($diagnosis));
        $this->assertSame('$.children[1].node', $diagnosis->diagnostics[0]->path);
        $this->assertSame(['mystery', 'turnover', 'postcode'], $diagnosis->references);
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
     * attempt runs it with recovery that has nothing quarantined.
     * This pins the two to one verdict per failure kind, absorbing
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
            $this->assertSame($refusal->describe(), $first->describe(), $name);
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


    /**
     * The two shapes a diagnosis comes in, and the only two ways to build
     * one. Construction is private, so nothing can assemble a verdict with
     * nothing behind it — a missing program and an empty diagnostics list,
     * which {@see Diagnosis::program()} would answer with `Err([])` against
     * a return type promising at least one refusal.
     */
    #[Test]
    public function no_construction_path_reports_nothing_without_a_program(): void
    {
        $this->assertTrue(new ReflectionMethod(Diagnosis::class, '__construct')->isPrivate());

        // A real throw, not an assert(): production runs with assertions
        // compiled out, and this invariant holds there too.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('reports what stands in the way of one, and this one reports nothing');

        Diagnosis::refused([], [], new NumberType());
    }

    #[Test]
    public function a_certified_diagnosis_reads_its_type_and_references_from_the_program(): void
    {
        // Nothing is carried alongside the program: the program already
        // answers for what it returns and what it reads, and two copies
        // could disagree.
        $reader = new Expression(
            new SymbolSource('turnover'),
            declarations: ['turnover' => new NumberType()],
        )->compile()->unwrap();
        $diagnosis = Diagnosis::certified($reader);

        $this->assertSame([], $diagnosis->diagnostics);
        $this->assertSame(['turnover'], $diagnosis->references);
        $this->assertSame($reader->returns, $diagnosis->returns);
        $this->assertSame($reader, $diagnosis->program()->unwrap());

        // An expression that reads nothing reports nothing read.
        $this->assertSame([], Diagnosis::certified(
            new Expression(new StaticSource(1))->compile()->unwrap(),
        )->references);
    }

    #[Test]
    public function a_refused_diagnosis_never_carries_a_program(): void
    {
        // A root that recovered exposes its real type beside the refusal —
        // and still no program, because the expression is refused.
        $refused = Diagnosis::refused([new TypeMismatch('Refused.')], [], new NumberType());

        $this->assertInstanceOf(NumberType::class, $refused->returns);
        $this->assertTrue($refused->program()->isErr());
        $this->assertCount(1, $refused->program()->unwrapErr());
    }

    /**
     * What certification does not pay for. Recovery state is a diagnosis's
     * own: an ordinary compilation builds none, so it carries no quarantine
     * to consult at every node and no reference set to accumulate into
     * across attempts it never makes.
     */
    #[Test]
    public function an_ordinary_compilation_carries_no_recovery_state(): void
    {
        $extension = new CapturingExtension();
        $expression = new Expression(new CapturingSource(), dialect: Dialect::core()->with($extension));

        $expression->compile()->unwrap();
        $this->assertNull(new ReflectionProperty(TypeInference::class, 'recovery')->getValue($extension->compiler()));

        // The same walk under diagnose() carries one, which is the whole of
        // the difference between the two.
        $expression->diagnose();
        $this->assertInstanceOf(ErrorRecovery::class, new ReflectionProperty(TypeInference::class, 'recovery')->getValue($extension->compiler()));
    }

    #[Test]
    public function a_diagnostic_carries_the_node_it_names(): void
    {
        $located = self::diagnose(new SymbolSource('mystery'))->diagnostics[0];

        $this->assertSame('$', $located->path);
        $this->assertSame(
            'Unbound symbol [mystery]; declare its type, or declare it Unknown explicitly if this scope tolerates unknown symbols.',
            $located->describe(),
        );

        // Even a definition cycle — a fact about the graph — is located: at
        // the reference that closed it.
        $cycle = self::diagnose(
            new SymbolSource('a'),
            definitions: new Definitions(['a' => new SymbolSource('a')]),
        )->diagnostics[0];

        $this->assertSame('$.children[0].node', $cycle->path);
        $this->assertSame('Cyclic symbol definition: a → a.', $cycle->message);
    }

    #[Test]
    public function a_position_a_compiler_abandoned_renders_as_abandoned(): void
    {
        // The literal registry cannot type a stdClass, so Coerce catches its
        // child's refusal and compiles the value verbatim. Nothing is
        // reported and the program is certified — and the analysis renders
        // the position that child would have held as what it is, without
        // claiming a type or a compiler was ever settled there.
        $diagnosis = self::diagnose(new Coerce(new NumberType(), new StaticSource(new stdClass())));

        $this->assertSame([], $diagnosis->diagnostics);

        $root = $diagnosis->program()->unwrap()->analysis->toArray()['root'];

        $this->assertSame([
            'path' => '$.children[0].node',
            'source' => StaticSource::class,
            'abandoned' => true,
        ], $root['children'][0]['node']);
    }

    #[Test]
    public function an_abandoned_position_is_not_a_failure(): void
    {
        // It is not part of the program, so it does not stand in the way of
        // certifying one: the parent compiled without it.
        $abandoned = CompilationNode::abandoned(StaticSource::class);

        $this->assertSame(CompilationState::Abandoned, $abandoned->state);
        $this->assertFalse($abandoned->containsFailure);
    }

    /**
     * The two things a certified node answers for and an abandoned position
     * does not.
     *
     * @return iterable<string, array{string, callable(CompilationNode): mixed}>
     */
    public static function claimsAnAbandonedPositionNeverMade(): iterable
    {
        yield 'return type' => ['return type', static fn(CompilationNode $node) => $node->returns];
        yield 'owning compiler' => ['owning compiler', static fn(CompilationNode $node) => $node->extension];
    }

    #[Test]
    #[DataProvider('claimsAnAbandonedPositionNeverMade')]
    public function an_abandoned_position_claims_nothing_a_compilation_would(string $missing, callable $read): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(sprintf('its parent compiled without it, so it has no %s.', $missing));

        $read(CompilationNode::abandoned(StaticSource::class));
    }

    #[Test]
    public function a_node_that_failed_has_no_evaluation_to_run(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('A node that failed to compile has no evaluation; this program was never certified.');

        CompiledNode::failed()->evaluate(new Runtime());
    }
}
