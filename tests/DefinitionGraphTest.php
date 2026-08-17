<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\DefinitionGraph;
use Superscript\Axiom\Definitions;
use Superscript\Axiom\Sources\InfixExpression;
use Superscript\Axiom\Sources\MemberAccessSource;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\UnboundSymbols;

#[CoversClass(DefinitionGraph::class)]
#[UsesClass(Definitions::class)]
#[UsesClass(UnboundSymbols::class)]
#[UsesClass(\Superscript\Axiom\ReferencePath::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\Superscript\Axiom\Sources\InfixExpression::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\Superscript\Axiom\Sources\StaticSource::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(MemberAccessSource::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\Superscript\Axiom\Sources\SymbolSource::class)]
final class DefinitionGraphTest extends TestCase
{
    #[Test]
    public function a_well_founded_graph_has_no_cycles(): void
    {
        // A diamond: a → b, a → c, b → d, c → d. Shared nodes are fine;
        // only a back edge is a cycle.
        $definitions = new Definitions([
            'a' => new InfixExpression(new SymbolSource('b'), '+', new SymbolSource('c')),
            'b' => new SymbolSource('d'),
            'c' => new SymbolSource('d'),
            'd' => new StaticSource(1),
        ]);

        $this->assertSame([], DefinitionGraph::cycles($definitions));
    }

    #[Test]
    public function a_self_cycle_is_reported_by_name(): void
    {
        $definitions = new Definitions(['a' => new SymbolSource('a')]);

        $cycles = DefinitionGraph::cycles($definitions);

        $this->assertCount(1, $cycles);
        $this->assertStringContainsString('Cyclic symbol definition: a → a.', $cycles[0]->describe());
    }

    #[Test]
    public function a_mutual_cycle_is_reported_once(): void
    {
        $definitions = new Definitions([
            'a' => new SymbolSource('b'),
            'b' => new SymbolSource('a'),
        ]);

        $cycles = DefinitionGraph::cycles($definitions);

        $this->assertCount(1, $cycles);
        $this->assertStringContainsString('Cyclic symbol definition: a → b → a.', $cycles[0]->describe());
    }

    #[Test]
    public function a_cycle_among_later_keys_is_still_found(): void
    {
        // The walk covers every definition, not just the first: a cycle
        // that no earlier key reaches must still surface.
        $definitions = new Definitions([
            'ok' => new StaticSource(1),
            'a' => new SymbolSource('b'),
            'b' => new SymbolSource('a'),
        ]);

        $this->assertCount(1, DefinitionGraph::cycles($definitions));
    }

    #[Test]
    public function the_reported_cycle_starts_at_its_entry_not_at_the_walk_root(): void
    {
        // entry → a → b → a: the cycle is a → b → a; entry merely reaches
        // it and must not appear in the report.
        $definitions = new Definitions([
            'entry' => new SymbolSource('a'),
            'a' => new SymbolSource('b'),
            'b' => new SymbolSource('a'),
        ]);

        $cycles = DefinitionGraph::cycles($definitions);

        $this->assertCount(1, $cycles);
        $this->assertStringContainsString('Cyclic symbol definition: a → b → a.', $cycles[0]->describe());
        $this->assertStringNotContainsString('entry', $cycles[0]->describe());
    }

    #[Test]
    public function every_distinct_cycle_is_reported(): void
    {
        // Two independent cycles → two diagnostics: the triage view of a
        // corpus sweep must show the whole damage, not the first hit.
        $definitions = new Definitions([
            'a' => new SymbolSource('a'),
            'b' => new SymbolSource('c'),
            'c' => new SymbolSource('b'),
        ]);

        $this->assertCount(2, DefinitionGraph::cycles($definitions));
    }

    #[Test]
    public function a_cycle_through_structural_member_access_is_a_root_cycle(): void
    {
        $definitions = new Definitions([
            'customer' => new MemberAccessSource(new SymbolSource('customer'), 'rate'),
        ]);

        $cycles = DefinitionGraph::cycles($definitions);

        $this->assertCount(1, $cycles);
        $this->assertStringContainsString('customer → customer', $cycles[0]->describe());
    }

    #[Test]
    public function a_well_founded_graph_has_no_cyclic_names(): void
    {
        $definitions = new Definitions([
            'a' => new SymbolSource('b'),
            'b' => new StaticSource(1),
        ]);

        $this->assertSame([], DefinitionGraph::cyclicKeys($definitions));
    }

    #[Test]
    public function every_name_on_a_cycle_is_named_once(): void
    {
        // Two cycles sharing 'a': a → b → a and a → c → a. Every name a
        // caller must refuse to descend into appears exactly once, and 'd',
        // which is on neither, appears not at all.
        $definitions = new Definitions([
            'a' => new InfixExpression(new SymbolSource('b'), '+', new SymbolSource('c')),
            'b' => new SymbolSource('a'),
            'c' => new SymbolSource('a'),
            'd' => new StaticSource(1),
        ]);

        $this->assertSame(['a', 'b', 'c'], DefinitionGraph::cyclicKeys($definitions));
    }

    #[Test]
    public function a_name_reachable_only_through_an_overlapping_cycle_is_still_named(): void
    {
        // a → b, b → a, a → c, c → b. Two cycles overlap: a → b → a and
        // a → c → b → a. A walk that stops at names an earlier branch
        // finished never reads c as cyclic, but descending into c does not
        // terminate either — all three names are one strongly connected
        // component.
        $definitions = new Definitions([
            'a' => new InfixExpression(new SymbolSource('b'), '+', new SymbolSource('c')),
            'b' => new SymbolSource('a'),
            'c' => new SymbolSource('b'),
        ]);

        $this->assertSame(['a', 'b', 'c'], DefinitionGraph::cyclicKeys($definitions));
    }

    #[Test]
    public function two_disjoint_cycles_name_every_member_of_both(): void
    {
        // 'sound' sits on neither and is walked first, so a walk that gave
        // up at the first name it cleared would clear the cycles with it.
        $definitions = new Definitions([
            'sound' => new StaticSource(1),
            'a' => new SymbolSource('b'),
            'b' => new SymbolSource('a'),
            'c' => new SymbolSource('d'),
            'd' => new SymbolSource('c'),
        ]);

        $this->assertSame(['a', 'b', 'c', 'd'], DefinitionGraph::cyclicKeys($definitions));
    }

    #[Test]
    public function a_name_that_merely_depends_on_a_cycle_is_not_on_one(): void
    {
        // dependant → a → b → a. Following dependant's edges terminates —
        // it reaches the cycle but is not part of it, and it takes
        // its failure from the poisoned name it references, not from here.
        $definitions = new Definitions([
            'dependant' => new SymbolSource('a'),
            'a' => new SymbolSource('b'),
            'b' => new SymbolSource('a'),
        ]);

        $this->assertSame(['a', 'b'], DefinitionGraph::cyclicKeys($definitions));
    }

    #[Test]
    public function a_self_reference_is_a_cycle_of_one_name(): void
    {
        $definitions = new Definitions([
            'a' => new SymbolSource('a'),
            'b' => new StaticSource(1),
        ]);

        $this->assertSame(['a'], DefinitionGraph::cyclicKeys($definitions));
    }

    #[Test]
    public function a_name_referenced_twice_is_one_edge(): void
    {
        // a + a is one edge to a, and a is on no cycle.
        $definitions = new Definitions([
            'total' => new InfixExpression(new SymbolSource('a'), '+', new SymbolSource('a')),
            'a' => new StaticSource(1),
        ]);

        $this->assertSame([], DefinitionGraph::cyclicKeys($definitions));
    }

    #[Test]
    public function references_to_parameters_are_leaves_not_edges(): void
    {
        // turnover is not defined — it is a parameter, satisfied by a
        // binding. The walk must not treat it as a missing node.
        $definitions = new Definitions([
            'assessment' => new InfixExpression(new SymbolSource('turnover'), '>', new StaticSource(100)),
        ]);

        $this->assertSame([], DefinitionGraph::cycles($definitions));
        $this->assertSame([], DefinitionGraph::cyclicKeys($definitions));
    }
}
