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
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\UnboundSymbols;

#[CoversClass(DefinitionGraph::class)]
#[UsesClass(Definitions::class)]
#[UsesClass(UnboundSymbols::class)]
#[UsesClass(\Superscript\Axiom\Types\TypeMismatch::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\Superscript\Axiom\Sources\InfixExpression::class)]
#[\PHPUnit\Framework\Attributes\UsesClass(\Superscript\Axiom\Sources\StaticSource::class)]
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
    public function a_namespaced_cycle_is_walked_through_dotted_keys(): void
    {
        $definitions = new Definitions([
            'customer' => ['rate' => new SymbolSource('base')],
            'base' => new SymbolSource('rate', 'customer'),
        ]);

        $cycles = DefinitionGraph::cycles($definitions);

        $this->assertCount(1, $cycles);
        $this->assertStringContainsString('customer.rate → base → customer.rate', $cycles[0]->describe());
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
    }
}
