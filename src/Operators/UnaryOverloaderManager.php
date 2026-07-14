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
 * The unary dialect, composed and resolved exactly like the binary one.
 */
class UnaryOverloaderManager implements UnaryOverloader
{
    public function __construct(
        /** @var list<UnaryOverloader> */
        private array $overloaders,
    ) {
        Assert::allIsInstanceOf($this->overloaders, UnaryOverloader::class);
    }

    public static function default(): self
    {
        return new self([
            new NotOverloader(),
            new NegateOverloader(),
        ]);
    }

    public function supportsOverloading(mixed $operand, string $operator): bool
    {
        return (bool) $this->getOverloader($operand, $operator);
    }

    /** @return Result<mixed, \Throwable> */
    public function evaluate(mixed $operand, string $operator): Result
    {
        if ($overloader = $this->getOverloader($operand, $operator)) {
            return $overloader->evaluate($operand, $operator);
        }

        return Err(new RuntimeException(sprintf('No overloader found for %s [%s]', $operator, (new Exporter())->export($operand))));
    }

    public function handles(string $operator): bool
    {
        return array_any($this->overloaders, fn(UnaryOverloader $overloader) => $overloader->handles($operator));
    }

    /**
     * @return Result<Type, TypeMismatch>
     */
    public function typeOf(string $operator, Type $operand): Result
    {
        $verdicts = [];
        $mismatches = [];

        foreach ($this->overloaders as $overloader) {
            if (!$overloader->handles($operator)) {
                continue;
            }

            $verdict = $overloader->typeOf($operator, $operand);

            if ($verdict->isOk()) {
                $verdicts[] = $verdict->unwrap();
            } else {
                $mismatches[] = $verdict->unwrapErr();
            }
        }

        if ($verdicts === [] && $mismatches === []) {
            return Err(new TypeMismatch(sprintf('Unary operator [%s] is not supported.', $operator)));
        }

        if ($verdicts === []) {
            return count($mismatches) === 1
                ? Err($mismatches[0])
                : Err(new TypeMismatch(
                    sprintf('No overload of unary [%s] accepts %s.', $operator, TypeDescriber::describe($operand)),
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

    private function getOverloader(mixed $operand, string $operator): ?UnaryOverloader
    {
        foreach ($this->overloaders as $overloader) {
            if ($overloader->supportsOverloading($operand, $operator)) {
                return $overloader;
            }
        }

        return null;
    }
}
