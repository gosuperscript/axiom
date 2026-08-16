<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Rewrite\Fixtures;

use Superscript\Axiom\CompiledSource;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Rewrite\Descent;
use Superscript\Axiom\Source;
use Superscript\Axiom\SourceCompilation;

/** One package, both hooks: how a box compiles, and how a rewrite reaches through it. */
final class BoxExtension extends Extension
{
    public function sourceCompilers(): array
    {
        return [
            BoxSource::class => $this->compile(...),
        ];
    }

    public function sourceDescenders(): array
    {
        return [
            BoxSource::class => $this->descend(...),
        ];
    }

    private function compile(BoxSource $source, SourceCompilation $compilation): CompiledSource
    {
        return $compilation->child($source->inner, 'inner');
    }

    private function descend(BoxSource $source, Descent $descent): Source
    {
        $inner = $descent->child($source->inner, 'inner');

        return $inner === $source->inner ? $source : new BoxSource($inner);
    }
}
