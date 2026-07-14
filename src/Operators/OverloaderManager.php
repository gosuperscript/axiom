<?php

declare(strict_types=1);

namespace Superscript\Axiom\Operators;

use RuntimeException;
use SebastianBergmann\Exporter\Exporter;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Axiom\Types\TypeMismatch;
use Superscript\Axiom\Types\TypeRelations;
use Superscript\Axiom\Types\UnknownType;
use Superscript\Monads\Result\Result;
use Webmozart\Assert\Assert;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/**
 * The dialect is one list: the evaluator dispatches over it (first honest
 * claim wins) and inference resolves over it, so a runtime stack and a
 * hand-maintained parallel registry of static rules cannot drift apart.
 */
class OverloaderManager implements OperatorOverloader
{
    public function __construct(
        /** @var list<OperatorOverloader> */
        private array $overloaders,
    ) {
        Assert::allIsInstanceOf($this->overloaders, OperatorOverloader::class);
    }

    public function supportsOverloading(mixed $left, mixed $right, string $operator): bool
    {
        return (bool) $this->getOverloader($left, $right, $operator);
    }

    /** @return Result<mixed, \Throwable> */
    public function evaluate(mixed $left, mixed $right, string $operator): Result
    {
        if ($overloader = $this->getOverloader($left, $right, $operator)) {
            return $overloader->evaluate($left, $right, $operator);
        }

        return Err(new RuntimeException(sprintf('No overloader found for [%s] %s [%s]', (new Exporter())->export($left), $operator, (new Exporter())->export($right))));
    }

    public function handles(string $operator): bool
    {
        return array_any($this->overloaders, fn(OperatorOverloader $overloader) => $overloader->handles($operator));
    }

    /**
     * Resolution across the stack:
     * 1. collect the verdicts of every member that handles the operator;
     * 2. Oks whose return types all agree → that type;
     * 3. Oks that disagree → Unknown: under the existential contract,
     *    different values of these operand types legitimately route to
     *    different rules, and which one evaluates depends on values
     *    inference cannot see — never the accident of list order;
     * 4. no Oks → the lone handler's mismatch directly, or an aggregate.
     *
     * @return Result<Type, TypeMismatch>
     */
    public function typeOf(string $operator, Type $left, Type $right): Result
    {
        $verdicts = [];
        $mismatches = [];

        foreach ($this->overloaders as $overloader) {
            if (!$overloader->handles($operator)) {
                continue;
            }

            $verdict = $overloader->typeOf($operator, $left, $right);

            if ($verdict->isOk()) {
                $verdicts[] = $verdict->unwrap();
            } else {
                $mismatches[] = $verdict->unwrapErr();
            }
        }

        if ($verdicts === [] && $mismatches === []) {
            return Err(new TypeMismatch(sprintf('Operator [%s] is not supported.', $operator)));
        }

        if ($verdicts === []) {
            return count($mismatches) === 1
                ? Err($mismatches[0])
                : Err(new TypeMismatch(
                    sprintf('No overload of [%s] accepts %s and %s.', $operator, TypeDescriber::describe($left), TypeDescriber::describe($right)),
                    $mismatches,
                ));
        }

        $first = $verdicts[0];

        foreach ($verdicts as $verdict) {
            if (TypeRelations::areEquivalent($first, $verdict)->isErr()) {
                return Ok(new UnknownType());
            }
        }

        return Ok($first);
    }

    private function getOverloader(mixed $left, mixed $right, string $operator): ?OperatorOverloader
    {
        foreach ($this->overloaders as $overloader) {
            if ($overloader->supportsOverloading($left, $right, $operator)) {
                return $overloader;
            }
        }

        return null;
    }
}
