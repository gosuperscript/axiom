<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Rewrite;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Rewrite\ArrayBindingsCorpus;
use Superscript\Axiom\Rewrite\CoreSourceDescenders;
use Superscript\Axiom\Rewrite\ObligationVerdict;
use Superscript\Axiom\Rewrite\VerdictPreservation;
use Superscript\Axiom\Rewrite\Descent;
use Superscript\Axiom\Rewrite\Describes;
use Superscript\Axiom\Rewrite\OpaqueSource;
use Superscript\Axiom\Rewrite\Preservation;
use Superscript\Axiom\Rewrite\RewriteOutcome;
use Superscript\Axiom\Rewrite\RewriteRecord;
use Superscript\Axiom\Rewrite\RewriteReport;
use Superscript\Axiom\Rewrite\Rewriter;
use Superscript\Axiom\Rewrite\RewriteRun;
use Superscript\Axiom\Rewrite\RewriteWalk;
use Superscript\Axiom\Rewrite\Rules\RemoveDoubleNegation;
use Superscript\Axiom\Rewrite\SourceDescenders;
use Superscript\Axiom\Rewrite\SourcePath;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\Ascription;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\DefaultValue;
use Superscript\Axiom\Sources\ExpressionPattern;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Tests\Fixtures\HostValueSource;
use Superscript\Axiom\Tests\Rewrite\Fixtures\BoxExtension;
use Superscript\Axiom\Tests\Rewrite\Fixtures\BoxSource;
use Superscript\Axiom\Tests\Rewrite\Fixtures\StubRule;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\StringType;

#[CoversClass(Rewriter::class)]
#[CoversClass(RewriteWalk::class)]
#[CoversClass(RewriteRun::class)]
#[CoversClass(RewriteReport::class)]
#[CoversClass(RewriteRecord::class)]
#[CoversClass(OpaqueSource::class)]
#[CoversClass(Describes::class)]
#[CoversClass(Descent::class)]
#[CoversClass(CoreSourceDescenders::class)]
#[CoversClass(SourceDescenders::class)]
#[CoversClass(SourcePath::class)]
#[UsesNamespace('Superscript\\Axiom')]
final class RewriterTest extends TestCase
{
    /** @param list<\Superscript\Axiom\Rewrite\RewriteRule> $rules */
    private static function rewrite(Source $source, array $rules, RecordType|array $declarations = [], ?SourceDescenders $descenders = null): RewriteRun
    {
        return (new Rewriter($rules, $descenders))->rewrite(new Expression($source, declarations: $declarations));
    }

    private static function replaces(string $identifier, string $class, Source $replacement): StubRule
    {
        /** @var non-empty-list<class-string<Source>> $visits */
        $visits = [$class];

        return new StubRule($identifier, $visits, static fn(Source $source): Source => $replacement);
    }

    #[Test]
    public function an_untouched_tree_comes_back_as_the_same_instance(): void
    {
        $source = new InfixExpression(new SymbolSource('a'), '&&', new SymbolSource('b'));

        $run = self::rewrite($source, [], ['a' => new BooleanType(), 'b' => new BooleanType()]);

        $this->assertSame($source, $run->source, 'nothing fired, so nothing was rebuilt');
        $this->assertFalse($run->changed);
        $this->assertSame('no rewrites, nothing opaque', $run->report->describe());
    }

    #[Test]
    public function untouched_siblings_keep_their_identity_when_one_child_is_rewritten(): void
    {
        $left = new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('a')));
        $right = new SymbolSource('b');
        $source = new InfixExpression($left, '&&', $right);

        $run = self::rewrite($source, [new RemoveDoubleNegation()], ['a' => new BooleanType(), 'b' => new BooleanType()]);

        $this->assertInstanceOf(InfixExpression::class, $run->source);
        $this->assertNotSame($source, $run->source);
        $this->assertSame($right, $run->source->right, 'the untouched subtree is the very same instance');
        $this->assertSame('a && b', $run->source->describe());
        $this->assertTrue($run->changed);
    }

    #[Test]
    public function bottom_up_application_collapses_a_nest_in_one_pass(): void
    {
        $source = new UnaryExpression('!', new UnaryExpression('!', new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('a')))));

        $run = self::rewrite($source, [new RemoveDoubleNegation()], ['a' => new BooleanType()]);

        $this->assertSame('a', $run->source->describe());
        $this->assertCount(2, $run->report->applied());
    }

    #[Test]
    public function a_rewritten_expression_carries_the_original_scope(): void
    {
        $source = new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('a')));

        $run = self::rewrite($source, [new RemoveDoubleNegation()], ['a' => new BooleanType()]);
        $program = $run->expression()->compile()->unwrap();

        $this->assertTrue($program(['a' => true])->unwrap()->unwrap());
        $this->assertSame($run->original->declarations, $run->expression()->declarations);
    }

    #[Test]
    public function a_site_is_reported_with_its_path_before_and_after(): void
    {
        $source = new InfixExpression(new SymbolSource('a'), '&&', new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('b'))));

        $run = self::rewrite($source, [new RemoveDoubleNegation()], ['a' => new BooleanType(), 'b' => new BooleanType()]);
        $applied = $run->report->applied();

        $this->assertCount(1, $applied);
        $this->assertSame(RewriteOutcome::Applied, $applied[0]->outcome);
        $this->assertSame('$.right', $applied[0]->path);
        $this->assertSame('axiom.rewrite.remove-double-negation', $applied[0]->rule);
        $this->assertSame('!!b', $applied[0]->before);
        $this->assertSame('b', $applied[0]->after);
        $this->assertSame(
            "applied axiom.rewrite.remove-double-negation at \$.right: !!b => b"
            . "\n  type preservation upheld: both compile to Boolean"
            . "\n  verdict preservation unchecked: the run was given no oracle for this claim",
            $run->report->describe(),
        );
    }

    #[Test]
    public function a_broken_obligation_refuses_that_site_and_reports_it(): void
    {
        $source = new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('count')));

        $run = self::rewrite($source, [new RemoveDoubleNegation()], ['count' => new NumberType()]);

        $this->assertSame($source, $run->source, 'a refused rewrite leaves the tree exactly as it was');
        $this->assertFalse($run->changed);
        $this->assertSame([], $run->report->applied());

        $refused = $run->report->refused();
        $this->assertCount(1, $refused);
        $this->assertSame('$', $refused[0]->path);
        $this->assertStringContainsString('type preservation broken: the original refuses and the replacement compiles', $refused[0]->describe());
        $this->assertStringContainsString('verdict preservation unchecked: the run was given no oracle for this claim', $refused[0]->describe());
    }

    #[Test]
    public function a_refused_rule_lets_the_next_rule_take_the_site(): void
    {
        $source = new SymbolSource('a');
        $unsound = self::replaces('unsound', SymbolSource::class, new Coerce(new StringType(), new StaticSource('a string where a boolean was')));
        $sound = self::replaces('sound', SymbolSource::class, new Coerce(new BooleanType(), new StaticSource(true)));

        $run = self::rewrite($source, [$unsound, $sound], ['a' => new BooleanType()]);

        $this->assertSame([RewriteOutcome::Refused, RewriteOutcome::Applied], array_map(fn(RewriteRecord $record): RewriteOutcome => $record->outcome, $run->report->records));
        $this->assertSame(['unsound'], array_map(fn(RewriteRecord $record): string => $record->rule, $run->report->refused()));
        $this->assertSame(['sound'], array_map(fn(RewriteRecord $record): string => $record->rule, $run->report->applied()));
        $this->assertSame('true (as boolean)', Describes::node($run->source));
    }

    #[Test]
    public function the_first_sound_rewrite_ends_the_visit(): void
    {
        $first = self::replaces('first', SymbolSource::class, new Coerce(new BooleanType(), new StaticSource(true)));
        $second = self::replaces('second', SymbolSource::class, new Coerce(new BooleanType(), new StaticSource(false)));

        $run = self::rewrite(new SymbolSource('a'), [$first, $second], ['a' => new BooleanType()]);

        $this->assertCount(1, $run->report->records);
        $this->assertSame('first', $run->report->applied()[0]->rule);
    }

    #[Test]
    public function a_rule_that_offers_nothing_is_passed_over(): void
    {
        /** @var non-empty-list<class-string<Source>> $visits */
        $visits = [SymbolSource::class];
        $silent = new StubRule('silent', $visits, static fn(Source $source): ?Source => null);
        $source = new SymbolSource('a');

        $run = self::rewrite($source, [$silent], ['a' => new BooleanType()]);

        $this->assertSame($source, $run->source);
        $this->assertSame([], $run->report->records);
    }

    #[Test]
    public function a_rule_is_only_offered_the_exact_classes_it_visits(): void
    {
        $seen = [];
        /** @var non-empty-list<class-string<Source>> $visits */
        $visits = [SymbolSource::class];
        $spy = new StubRule('spy', $visits, static function (Source $source) use (&$seen): ?Source {
            $seen[] = $source::class;

            return null;
        });

        self::rewrite(new InfixExpression(new SymbolSource('a'), '&&', new StaticSource(true)), [$spy], ['a' => new BooleanType()]);

        $this->assertSame([SymbolSource::class], $seen);
    }

    #[Test]
    public function a_claim_the_rule_repeats_is_judged_once_and_the_rest_still_judged(): void
    {
        /** @var non-empty-list<class-string<Source>> $visits */
        $visits = [SymbolSource::class];
        $rule = new StubRule(
            'repeats',
            $visits,
            static fn(Source $source): Source => new Coerce(new BooleanType(), new StaticSource(true)),
            [Preservation::CertifiedType, Preservation::Verdict],
        );

        $run = self::rewrite(new SymbolSource('a'), [$rule], ['a' => new BooleanType()]);

        $this->assertSame(
            ['type preservation upheld: both compile to Boolean', 'verdict preservation unchecked: the run was given no oracle for this claim'],
            array_map(fn(ObligationVerdict $verdict): string => $verdict->describe(), $run->report->applied()[0]->verdicts),
        );
    }

    #[Test]
    public function a_rule_that_offers_nothing_lets_the_next_rule_take_the_site(): void
    {
        /** @var non-empty-list<class-string<Source>> $visits */
        $visits = [SymbolSource::class];
        $silent = new StubRule('silent', $visits, static fn(Source $source): ?Source => null);
        $sound = self::replaces('sound', SymbolSource::class, new Coerce(new BooleanType(), new StaticSource(true)));

        $run = self::rewrite(new SymbolSource('a'), [$silent, $sound], ['a' => new BooleanType()]);

        $this->assertSame(['sound'], array_map(fn(RewriteRecord $record): string => $record->rule, $run->report->applied()));
    }

    #[Test]
    public function an_applied_site_and_a_refused_one_are_reported_side_by_side(): void
    {
        $source = new InfixExpression(
            new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('flag'))),
            '&&',
            new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('count'))),
        );

        $run = self::rewrite($source, [new RemoveDoubleNegation()], ['flag' => new BooleanType(), 'count' => new NumberType()]);

        $this->assertSame(['$.left'], array_map(fn(RewriteRecord $record): string => $record->path, $run->report->applied()));
        $this->assertSame(['$.right'], array_map(fn(RewriteRecord $record): string => $record->path, $run->report->refused()));
    }

    #[Test]
    public function an_unregistered_host_shape_is_an_opaque_leaf_the_run_reports(): void
    {
        $hidden = new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('a')));
        $source = new InfixExpression(new SymbolSource('a'), '&&', new HostValueSource(new BooleanType(), $hidden));

        $run = self::rewrite($source, [new RemoveDoubleNegation()], ['a' => new BooleanType()]);

        $this->assertSame($source, $run->source, 'an opaque node is never descended and never rewritten');
        $this->assertSame([], $run->report->applied());
        $this->assertCount(1, $run->report->opaque);
        $this->assertSame('$.right', $run->report->opaque[0]->path);
        $this->assertSame(HostValueSource::class, $run->report->opaque[0]->class);
        $this->assertSame('HostValueSource', $run->report->opaque[0]->describe, 'a host node that cannot spell itself is named by its class');
        $this->assertStringContainsString('opaque ' . HostValueSource::class . ' at $.right: HostValueSource', $run->report->describe());
    }

    #[Test]
    public function a_registered_host_shape_is_descended_and_rebuilt(): void
    {
        $descenders = SourceDescenders::core()->with(new BoxExtension());
        $source = new BoxSource(new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('a'))));

        $run = self::rewrite($source, [new RemoveDoubleNegation()], ['a' => new BooleanType()], $descenders);

        $this->assertInstanceOf(BoxSource::class, $run->source);
        $this->assertSame('box(a)', $run->source->describe());
        $this->assertSame('$.inner', $run->report->applied()[0]->path);
        $this->assertSame([], $run->report->opaque);
    }

    #[Test]
    public function a_registered_host_shape_shares_structure_when_nothing_moved(): void
    {
        $descenders = SourceDescenders::core()->with(new BoxExtension());
        $source = new BoxSource(new SymbolSource('a'));

        $run = self::rewrite($source, [new RemoveDoubleNegation()], ['a' => new BooleanType()], $descenders);

        $this->assertSame($source, $run->source);
    }

    #[Test]
    public function every_core_shape_is_descended_through(): void
    {
        $doubled = new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('flag')));
        $source = new MatchExpression(
            subject: new Coerce(new StringType(), new MemberAccessSource(new SymbolSource('customer'), 'name')),
            arms: [
                new MatchArm(new LiteralPattern('ada'), new Ascription(new BooleanType(), $doubled)),
                new MatchArm(new ExpressionPattern(new DefaultValue(new StaticSource('bob'), 'bob')), new StaticSource(false)),
                new MatchArm(new WildcardPattern(), new InfixExpression($doubled, '||', new StaticSource(false))),
            ],
        );

        $run = self::rewrite($source, [new RemoveDoubleNegation()], [
            'flag' => new BooleanType(),
            'customer' => new RecordType(['name' => new StringType()]),
        ]);

        $this->assertSame([
            '$.arms[0].expression.source',
            '$.arms[2].expression.left',
        ], array_map(fn(RewriteRecord $record): string => $record->path, $run->report->applied()));
        $this->assertSame('match customer.name (as string) { \'ada\' => flag (is boolean), \'bob\' ?? \'bob\' => false, _ => flag || false }', $run->source->describe());
    }

    /**
     * A rewrite under a node that only wraps its child — a coercion, an
     * authored default, a member access — must rebuild the wrapper around the
     * new child and keep everything else about it.
     */
    #[Test]
    public function a_wrapping_shape_rebuilds_around_a_rewritten_child(): void
    {
        $alias = self::replaces('alias', SymbolSource::class, new SymbolSource('b'));
        $record = new RecordType(['name' => new StringType()]);

        $coerce = self::rewrite(new Coerce(new StringType(), new SymbolSource('a')), [$alias], ['a' => new StringType(), 'b' => new StringType()]);
        $default = self::rewrite(new DefaultValue(new SymbolSource('a'), 0), [$alias], ['a' => new OptionType(new NumberType()), 'b' => new OptionType(new NumberType())]);
        $member = self::rewrite(new MemberAccessSource(new SymbolSource('a'), 'name'), [$alias], ['a' => $record, 'b' => $record]);

        $this->assertSame('b (as string)', Describes::node($coerce->source));
        $this->assertSame('b ?? 0', Describes::node($default->source));
        $this->assertSame('b.name', Describes::node($member->source));
    }

    #[Test]
    public function a_match_arm_and_its_pattern_keep_their_identity_when_nothing_moved(): void
    {
        $arm = new MatchArm(new ExpressionPattern(new StaticSource('ada')), new StaticSource(1));
        $source = new MatchExpression(new SymbolSource('name'), [$arm, new MatchArm(new WildcardPattern(), new StaticSource(0))]);

        $run = self::rewrite($source, [new RemoveDoubleNegation()], ['name' => new StringType()]);

        $this->assertSame($source, $run->source);
    }

    #[Test]
    public function a_rewrite_inside_an_expression_pattern_rebuilds_the_arm(): void
    {
        $source = new MatchExpression(
            subject: new SymbolSource('flag'),
            arms: [
                new MatchArm(new ExpressionPattern(new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('flag')))), new StaticSource(1)),
                new MatchArm(new WildcardPattern(), new StaticSource(0)),
            ],
        );

        $run = self::rewrite($source, [new RemoveDoubleNegation()], ['flag' => new BooleanType()]);

        $this->assertSame('match flag { flag => 1, _ => 0 }', $run->source->describe());
        $this->assertSame('$.arms[0].pattern.source', $run->report->applied()[0]->path);
    }

    #[Test]
    public function an_unknown_pattern_shape_is_opaque_too(): void
    {
        $pattern = new class implements \Superscript\Axiom\Sources\MatchPattern {};
        $source = new MatchExpression(new SymbolSource('flag'), [new MatchArm($pattern, new StaticSource(1))]);

        $run = self::rewrite($source, [new RemoveDoubleNegation()], ['flag' => new BooleanType()]);

        $this->assertSame($source, $run->source);
        $this->assertCount(1, $run->report->opaque);
        $this->assertSame('$.arms[0].pattern', $run->report->opaque[0]->path);
    }

    #[Test]
    public function a_path_segment_names_a_property(): void
    {
        $this->assertSame('$.left.operand', SourcePath::root()->child('left')->child('operand')->describe());
    }

    #[Test]
    public function an_oracle_the_run_is_given_answers_the_claim_it_discharges(): void
    {
        $rewriter = new Rewriter(
            [new RemoveDoubleNegation()],
            obligations: [new VerdictPreservation(new ArrayBindingsCorpus(['yes' => ['a' => true]]))],
        );

        $run = $rewriter->rewrite(new Expression(
            new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('a'))),
            declarations: ['a' => new BooleanType()],
        ));

        $this->assertSame(
            ['type preservation upheld: both compile to Boolean', 'verdict preservation upheld: 1 corpus case(s) agree'],
            array_map(fn(ObligationVerdict $verdict): string => $verdict->describe(), $run->report->applied()[0]->verdicts),
        );
    }
}
