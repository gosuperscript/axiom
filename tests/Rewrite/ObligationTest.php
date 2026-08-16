<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Rewrite;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Rewrite\ArrayBindingsCorpus;
use Superscript\Axiom\Rewrite\ObligationVerdict;
use Superscript\Axiom\Rewrite\Preservation;
use Superscript\Axiom\Rewrite\RewriteSite;
use Superscript\Axiom\Rewrite\SourcePath;
use Superscript\Axiom\Rewrite\TypePreservation;
use Superscript\Axiom\Rewrite\VerdictPreservation;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\Coerce;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\UnaryExpression;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\Optional;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\Type;

#[CoversClass(TypePreservation::class)]
#[CoversClass(VerdictPreservation::class)]
#[CoversClass(ObligationVerdict::class)]
#[CoversClass(Preservation::class)]
#[CoversClass(RewriteSite::class)]
#[CoversClass(ArrayBindingsCorpus::class)]
#[UsesNamespace('Superscript\\Axiom')]
final class ObligationTest extends TestCase
{
    /** @param array<string, Type|Optional> $declarations */
    private static function site(Source $before, Source $after, array $declarations = []): RewriteSite
    {
        return new RewriteSite(new Expression($before, declarations: $declarations), SourcePath::root(), $before, $after);
    }

    #[Test]
    public function a_site_compiles_each_subtree_once(): void
    {
        $site = self::site(new StaticSource(1), new StaticSource(2));

        $this->assertSame($site->compileBefore(), $site->compileBefore());
        $this->assertSame($site->compileAfter(), $site->compileAfter());
        $this->assertNotSame($site->compileBefore(), $site->compileAfter());
    }

    #[Test]
    public function a_subtree_compiles_in_the_whole_expressions_scope(): void
    {
        $site = self::site(new SymbolSource('flag'), new SymbolSource('flag'), ['flag' => new BooleanType()]);

        $this->assertTrue($site->compileBefore()->isOk(), 'the declaration the whole expression makes is the one a subtree is compiled against');
    }

    #[Test]
    public function equal_certified_types_uphold_type_preservation(): void
    {
        $verdict = (new TypePreservation())->check(self::site(
            new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('flag'))),
            new SymbolSource('flag'),
            ['flag' => new BooleanType()],
        ));

        $this->assertTrue($verdict->checked);
        $this->assertFalse($verdict->broken);
        $this->assertSame('type preservation upheld: both compile to Boolean', $verdict->describe());
    }

    #[Test]
    public function different_certified_types_break_type_preservation(): void
    {
        $verdict = (new TypePreservation())->check(self::site(
            new Coerce(new BooleanType(), new StaticSource(true)),
            new Coerce(new StringType(), new StaticSource('true')),
        ));

        $this->assertTrue($verdict->broken);
        $this->assertSame('type preservation broken: the original compiles to Boolean and the replacement to String', $verdict->describe());
    }

    #[Test]
    public function turning_a_refusal_into_a_program_breaks_type_preservation(): void
    {
        $verdict = (new TypePreservation())->check(self::site(
            new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('count'))),
            new SymbolSource('count'),
            ['count' => new NumberType()],
        ));

        $this->assertTrue($verdict->broken);
        $this->assertStringStartsWith('type preservation broken: the original refuses and the replacement compiles: [!] expects Boolean; got Number.', $verdict->describe());
    }

    #[Test]
    public function turning_a_program_into_a_refusal_breaks_type_preservation(): void
    {
        $verdict = (new TypePreservation())->check(self::site(
            new SymbolSource('count'),
            new UnaryExpression('!', new SymbolSource('count')),
            ['count' => new NumberType()],
        ));

        $this->assertTrue($verdict->broken);
        $this->assertStringStartsWith('type preservation broken: the original compiles and the replacement refuses: [!] expects Boolean; got Number.', $verdict->describe());
    }

    #[Test]
    public function the_same_refusal_on_both_sides_upholds_type_preservation(): void
    {
        $verdict = (new TypePreservation())->check(self::site(
            new Coerce(new NumberType(), new SymbolSource('missing')),
            new Coerce(new StringType(), new SymbolSource('missing')),
        ));

        $this->assertFalse($verdict->broken, 'neither tree can run, and the author is told the same thing either way');
        $this->assertStringStartsWith('type preservation upheld: both refuse:', $verdict->describe());
    }

    #[Test]
    public function two_different_refusals_break_type_preservation(): void
    {
        $verdict = (new TypePreservation())->check(self::site(
            new SymbolSource('missing'),
            new UnaryExpression('!', new SymbolSource('count')),
            ['count' => new NumberType()],
        ));

        $this->assertTrue($verdict->broken, 'a rewrite must not be what changes the diagnostic an author reads');
        $this->assertStringContainsString('both refuse, but differently:', $verdict->describe());
    }

    #[Test]
    public function a_corpus_that_agrees_upholds_verdict_preservation(): void
    {
        $obligation = new VerdictPreservation(new ArrayBindingsCorpus([
            'yes' => ['flag' => true],
            'no' => ['flag' => false],
        ]));

        $verdict = $obligation->check(self::site(
            new UnaryExpression('!', new UnaryExpression('!', new SymbolSource('flag'))),
            new SymbolSource('flag'),
            ['flag' => new BooleanType()],
        ));

        $this->assertFalse($verdict->broken);
        $this->assertSame('verdict preservation upheld: 2 corpus case(s) agree', $verdict->describe());
    }

    #[Test]
    public function absence_answers_alike_and_still_counts(): void
    {
        $obligation = new VerdictPreservation(new ArrayBindingsCorpus(['unanswered' => []]));

        $verdict = $obligation->check(self::site(
            new SymbolSource('roof'),
            new SymbolSource('roof'),
            ['roof' => new Optional(new OptionType(new NumberType()))],
        ));

        $this->assertSame('verdict preservation upheld: 1 corpus case(s) agree', $verdict->describe());
    }

    #[Test]
    public function one_disagreeing_case_breaks_verdict_preservation_and_names_itself(): void
    {
        $obligation = new VerdictPreservation(new ArrayBindingsCorpus([
            'ordinary' => ['divisor' => 2],
            'zero' => ['divisor' => 0],
        ]));

        $verdict = $obligation->check(self::site(
            new InfixExpression(new StaticSource(10), '/', new SymbolSource('divisor')),
            new StaticSource(5),
            ['divisor' => new NumberType()],
        ));

        $this->assertTrue($verdict->broken);
        $this->assertStringContainsString('case [zero]: the original answers error(', $verdict->describe());
        $this->assertStringContainsString('and the replacement value(5)', $verdict->describe());
    }

    #[Test]
    public function a_subtree_that_does_not_compile_leaves_verdicts_unchecked(): void
    {
        $obligation = new VerdictPreservation(new ArrayBindingsCorpus(['any' => []]));

        $verdict = $obligation->check(self::site(new SymbolSource('missing'), new StaticSource(1)));

        $this->assertFalse($verdict->checked);
        $this->assertFalse($verdict->broken);
        $this->assertSame('verdict preservation unchecked: a subtree does not compile, so there is no pair of programs to run', $verdict->describe());
    }

    #[Test]
    public function a_replacement_that_does_not_compile_leaves_verdicts_unchecked(): void
    {
        $obligation = new VerdictPreservation(new ArrayBindingsCorpus(['any' => []]));

        $verdict = $obligation->check(self::site(new StaticSource(1), new SymbolSource('missing')));

        $this->assertFalse($verdict->checked);
    }

    #[Test]
    public function each_obligation_answers_for_one_preservation(): void
    {
        $this->assertSame(Preservation::CertifiedType, (new TypePreservation())->preservation());
        $this->assertSame(Preservation::Verdict, (new VerdictPreservation(new ArrayBindingsCorpus([])))->preservation());
    }
}
