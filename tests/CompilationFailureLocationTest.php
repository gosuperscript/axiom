<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Analysis\CompilationRecorder;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\LiteralPattern;
use Superscript\Axiom\Sources\MatchArm;
use Superscript\Axiom\Sources\MatchExpression;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\WildcardPattern;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;
use Superscript\Axiom\Types\TypeMismatch;

/**
 * The failure channel's answer to "which node?", and its agreement with the
 * success channel: a refusal reports the node that made it in the same path
 * language {@see \Superscript\Axiom\Analysis\CompilationAnalysis} uses for the
 * nodes that compiled.
 */
#[CoversClass(TypeMismatch::class)]
#[CoversClass(CompilationRecorder::class)]
#[\PHPUnit\Framework\Attributes\UsesNamespace('Superscript\\Axiom')]
final class CompilationFailureLocationTest extends TestCase
{
    /** `(name + 1) * 2` — the inner `+` is the node with no valid reading. */
    private static function nestedProduct(): Source
    {
        return new InfixExpression(
            new InfixExpression(new SymbolSource('name'), '+', new StaticSource(1)),
            '*',
            new StaticSource(2),
        );
    }

    /** The path the successful analysis of $expression gives its $index'th node, depth first. */
    private static function analysisPaths(Expression $expression): array
    {
        $paths = [];
        $walk = function (array $node) use (&$walk, &$paths): void {
            $paths[] = $node['path'];

            foreach ($node['children'] as $child) {
                $walk($child['node']);
            }
        };
        $walk($expression->analyze()->unwrap()->toArray()['root']);

        return $paths;
    }

    #[Test]
    public function a_refusal_names_its_node_in_the_same_language_a_success_uses(): void
    {
        $source = self::nestedProduct();

        // `name` is a String, so the inner `+` has no overload.
        $failure = (new Expression($source, declarations: ['name' => new StringType()]))
            ->compile()
            ->unwrapErr();

        // The identical tree, typed so every node compiles.
        $compiles = new Expression($source, declarations: ['name' => new NumberType()]);

        $this->assertSame('[+] expects Number and Number; got String and 1.', $failure->message);
        $this->assertSame('$.children[0].node', $failure->path);
        $this->assertContains($failure->path, self::analysisPaths($compiles));
    }

    #[Test]
    public function a_claim_about_types_rather_than_nodes_is_not_located(): void
    {
        $failure = (new Expression(self::nestedProduct(), declarations: ['name' => new StringType()]))
            ->compile()
            ->unwrapErr();

        // The operator was given two types, not two nodes: nothing at that
        // level knows which node produced the String.
        $this->assertSame('String is not assignable to Number.', $failure->causes[0]->message);
        $this->assertNull($failure->causes[0]->path);
    }

    #[Test]
    public function the_path_names_the_deepest_node_that_refused(): void
    {
        $failure = (new Expression(
            new InfixExpression(self::nestedProduct(), '-', new StaticSource(3)),
            declarations: ['name' => new StringType()],
        ))->compile()->unwrapErr();

        $this->assertSame('$.children[0].node.children[0].node', $failure->path);
    }

    #[Test]
    public function a_wrapped_refusal_carries_one_path_per_level(): void
    {
        // The match compiler adds context with within(), so the chain has two
        // levels: the match node, then the arm body that refused.
        $failure = (new Expression(
            new MatchExpression(new SymbolSource('flag'), [
                new MatchArm(new LiteralPattern(true), new StaticSource(1)),
                new MatchArm(new WildcardPattern(), new InfixExpression(new StaticSource('a'), '+', new StaticSource(1))),
            ]),
            declarations: ['flag' => new BooleanType()],
        ))->compile()->unwrapErr();

        $this->assertSame('Match arm 1 cannot be typed.', $failure->message);
        $this->assertSame('$', $failure->path);
        $this->assertSame('$.children[2].node', $failure->causes[0]->path);
        $this->assertNull($failure->causes[0]->causes[0]->path);
    }

    #[Test]
    public function a_definition_body_is_located_at_the_edge_that_referenced_it(): void
    {
        $body = fn(mixed $left) => new InfixExpression(new StaticSource($left), '*', new StaticSource(2));
        $source = new InfixExpression(new SymbolSource('rate'), '+', new StaticSource(1));

        $failure = (new Expression($source, definitions: new Definitions(['rate' => $body('x')])))
            ->compile()
            ->unwrapErr();

        $compiles = new Expression($source, definitions: new Definitions(['rate' => $body(3)]));

        $this->assertSame('$.children[0].node.children[0].node', $failure->path);
        $this->assertContains($failure->path, self::analysisPaths($compiles));
    }

    #[Test]
    public function an_unbound_symbol_is_located_where_it_is_referenced(): void
    {
        $failure = (new Expression(new InfixExpression(new StaticSource(1), '+', new SymbolSource('nope'))))
            ->compile()
            ->unwrapErr();

        $this->assertStringStartsWith('Unbound symbol [nope]', $failure->message);
        $this->assertSame('$.children[1].node', $failure->path);
    }

    #[Test]
    public function a_source_no_compiler_claims_is_located_where_it_sits(): void
    {
        $unregistered = new class implements Source {
            public function children(): iterable
            {
                return [];
            }
        };

        $root = (new Expression($unregistered))->compile()->unwrapErr();
        $nested = (new Expression(new InfixExpression($unregistered, '+', new StaticSource(1))))
            ->compile()
            ->unwrapErr();

        $this->assertSame('$', $root->path);
        $this->assertSame('$.children[0].node', $nested->path);
    }

    #[Test]
    public function a_refusal_about_the_whole_program_is_not_located(): void
    {
        // A definition cycle is a property of the definition graph, refused
        // before any node is walked: there is no position to blame.
        $failure = (new Expression(
            new SymbolSource('a'),
            definitions: new Definitions(['a' => new SymbolSource('b'), 'b' => new SymbolSource('a')]),
        ))->compile()->unwrapErr();

        $this->assertSame('The definition graph is not well-founded; evaluation would recurse without terminating.', $failure->message);
        $this->assertNull($failure->path);
        $this->assertNull($failure->causes[0]->path);
    }

    #[Test]
    public function locating_a_verdict_keeps_the_first_path_and_every_other_field(): void
    {
        $cause = new TypeMismatch('Because.');
        $mismatch = new TypeMismatch('No.', [$cause], dead: true);

        $located = $mismatch->at('$.children[3].node');

        $this->assertSame('$.children[3].node', $located->path);
        $this->assertSame('No.', $located->message);
        $this->assertSame([$cause], $located->causes);
        $this->assertTrue($located->dead);
        $this->assertSame($located, $located->at('$'), 'the first location wins, and re-locating is a no-op');
    }
}
