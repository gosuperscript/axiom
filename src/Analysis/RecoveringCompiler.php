<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\DefinitionGraph;
use Superscript\Axiom\Expression;
use Superscript\Axiom\Program;
use Superscript\Axiom\Types\TypeEnvironment;
use Superscript\Axiom\Types\TypeInference;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;

/**
 * Both faces of compiling an {@see Expression}: certify it, or find out
 * everything wrong with it. One walk answers both.
 *
 * ## Why there are attempts
 *
 * The compiler stops at the first refusal, and the refusal it reports is
 * built by unwinding: the node that refused stamps its path, and every
 * ancestor that added context (`SourceCompilation::within()`) wraps the
 * cause on the way out. That unwinding is what makes the message good — and
 * it is also what discards the rest of the tree. So this class does not try
 * to keep compiling past a failure. It compiles again.
 *
 * Each attempt is an ordinary compilation. When one refuses, the node it
 * names — the deepest path in the refusal's cause chain, the node that
 * actually made it — is **quarantined**: the next attempt hands that node
 * back as {@see \Superscript\Axiom\Types\ErrorType} without visiting it, and
 * so gets past it to whatever else is wrong. Attempts stop when one
 * succeeds, which is guaranteed: every attempt quarantines a node no earlier
 * attempt had, and a quarantined node cannot refuse.
 *
 * ## What the retry does *not* do: report twice
 *
 * ErrorType absorbs. An operation over one resolves to ErrorType with no
 * rule lookup and no refusal, so `mystery > 1000 && postcode == 'SW1'` costs
 * exactly one diagnostic — the unbound `mystery` — while the right-hand
 * comparison is still fully checked and `postcode` is still collected as a
 * reference. Where a node refuses on its own account instead of absorbing
 * (member access needs a record, and ErrorType is not one), the refusal is
 * recognised by position: a node with a quarantined node below it is
 * repeating a failure already reported, so it is quarantined silently.
 * One fault, one diagnostic — see {@see Diagnosis} for what that costs.
 *
 * ## Definition cycles
 *
 * A cycle is a property of the graph, not of a node, so it is diagnosed
 * before any attempt and every name on a cycle is *poisoned*: it resolves to
 * ErrorType, reported once, and never descended into.
 */
final readonly class RecoveringCompiler
{
    private const string NotWellFounded = 'The definition graph is not well-founded; evaluation would recurse without terminating.';

    public function __construct(private Expression $expression) {}

    /**
     * One attempt, certified. The refusal, if any, is the first diagnostic
     * {@see diagnose()} would collect.
     *
     * @return Result<Program, TypeMismatch>
     */
    public function compile(): Result
    {
        $cycles = DefinitionGraph::cycles($this->expression->definitions);

        if ($cycles !== []) {
            return Err(new TypeMismatch(self::NotWellFounded, $cycles));
        }

        return $this->attempt(new ErrorRecovery())->map(fn(CompiledNode $node) => $this->program($node));
    }

    /** Attempts until one succeeds, collecting what each refused. */
    public function diagnose(): Diagnosis
    {
        $definitions = $this->expression->definitions;
        $cycles = DefinitionGraph::cycles($definitions);
        $recovery = new ErrorRecovery(DefinitionGraph::cyclicKeys($definitions));
        $diagnostics = [];

        if ($cycles !== []) {
            $diagnostics[] = new Diagnostic(new TypeMismatch(self::NotWellFounded, $cycles));
        }

        while (true) {
            $attempt = $this->attempt($recovery);

            if ($attempt->isOk()) {
                return new Diagnosis(
                    $diagnostics,
                    $recovery->references(),
                    $attempt->unwrap(),
                    $this->expression->declarations,
                    $this->expression->boundary,
                );
            }

            $refusal = $attempt->unwrapErr();
            $node = self::deepestPath($refusal) ?? '$';

            if (!$recovery->quarantinedBelow($node)) {
                $diagnostics[] = new Diagnostic($refusal);
            }

            $recovery->quarantine($node);
        }
    }

    /** @return Result<CompiledNode, TypeMismatch> */
    private function attempt(ErrorRecovery $recovery): Result
    {
        $dialect = $this->expression->dialect;

        return new TypeInference(
            $dialect->operators(),
            $dialect->unaryOperators(),
            $dialect->literals(),
            $dialect->sourceCompilers(),
            $dialect->sourceCompilerExtensions(),
            $dialect->opaqueFields(),
            $recovery,
        )->compile(
            $this->expression->source,
            new TypeEnvironment($this->expression->definitions, $this->expression->declarations, $recovery),
        );
    }

    private function program(CompiledNode $node): Program
    {
        return new Program($node, $this->expression->declarations, $this->expression->boundary);
    }

    /**
     * The node that actually refused. A refusal travels up through the
     * ancestors that add context, each stamping its own path onto a fresh
     * wrapper, so the chain reads outermost-first and the last path in it is
     * the deepest node — the one to set aside. Null when nothing in the chain
     * is about a node at all.
     */
    private static function deepestPath(TypeMismatch $mismatch): ?string
    {
        $deepest = $mismatch->path;

        foreach ($mismatch->causes as $cause) {
            $deepest = self::deepestPath($cause) ?? $deepest;
        }

        return $deepest;
    }
}
