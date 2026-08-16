<?php

declare(strict_types=1);

namespace Superscript\Axiom\Rewrite;

use SebastianBergmann\Exporter\Exporter;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Result;
use Throwable;

/**
 * Run both programs over every case of a host corpus and demand the same
 * answer each time. An answer is the whole invocation result — an error, an
 * absent value, or a present value — because those are three different things
 * to a host and a rewrite that trades one for another has changed the program.
 *
 * The first disagreement stops the sweep and names the case: one counterexample
 * refuses the rewrite, and the rest of the corpus cannot un-refuse it.
 *
 * Evidence, not proof. A corpus that never exercises a branch says nothing
 * about it, which is why type preservation is checked independently and
 * always.
 */
final readonly class VerdictPreservation implements Obligation
{
    public function __construct(private BindingsCorpus $corpus) {}

    public function preservation(): Preservation
    {
        return Preservation::Verdict;
    }

    public function check(RewriteSite $site): ObligationVerdict
    {
        $before = $site->compileBefore();
        $after = $site->compileAfter();

        if ($before->isErr() || $after->isErr()) {
            return ObligationVerdict::unchecked($this->preservation(), 'a subtree does not compile, so there is no pair of programs to run');
        }

        $original = $before->unwrap();
        $replacement = $after->unwrap();
        $cases = 0;

        foreach ($this->corpus->cases() as $label => $bindings) {
            $cases++;
            $answered = self::answer($original($bindings));
            $answers = self::answer($replacement($bindings));

            if ($answered !== $answers) {
                return ObligationVerdict::broken($this->preservation(), sprintf(
                    'case [%s]: the original answers %s and the replacement %s',
                    $label,
                    $answered,
                    $answers,
                ));
            }
        }

        return ObligationVerdict::upheld($this->preservation(), sprintf('%d corpus case(s) agree', $cases));
    }

    /**
     * @param Result<Option<mixed>, Throwable> $result
     */
    private static function answer(Result $result): string
    {
        /** @var string */
        return $result->mapOrElse(
            fn(Throwable $error): string => sprintf('error(%s)', $error->getMessage()),
            fn(Option $value): string => $value->mapOr('absent', fn(mixed $present): string => sprintf('value(%s)', (new Exporter())->shortenedExport($present))),
        );
    }
}
