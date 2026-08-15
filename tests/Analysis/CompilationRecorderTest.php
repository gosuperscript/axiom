<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Analysis;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Analysis\CompilationRecorder;
use Superscript\Axiom\Analysis\References;

/**
 * A node's reads arrive one child at a time — every child of a source hands
 * up what it read as it finishes — so what the recorder holds is the union of
 * every batch it was given, in the order the names were first met.
 */
#[CoversClass(CompilationRecorder::class)]
#[CoversClass(References::class)]
final class CompilationRecorderTest extends TestCase
{
    #[Test]
    public function reads_accumulate_across_the_batches_they_arrive_in(): void
    {
        $recorder = new CompilationRecorder();

        $recorder->recordReferences(['turnover']);
        $recorder->recordReferences(['postcode', 'turnover']);

        $this->assertSame(['turnover', 'postcode'], $recorder->references());
    }

    #[Test]
    public function a_node_that_read_nothing_reports_nothing(): void
    {
        $recorder = new CompilationRecorder();

        $recorder->recordReferences([]);

        $this->assertSame([], $recorder->references());
    }
}
