<?php

declare(strict_types=1);

namespace Superscript\Axiom;

use Superscript\Axiom\Exceptions\BoundaryViolation;
use Superscript\Axiom\Exceptions\InadmissibleBinding;
use Superscript\Axiom\Exceptions\MissingRequiredInput;
use Superscript\Axiom\Exceptions\RejectedBinding;
use Superscript\Axiom\Exceptions\RecordPropertyViolation;
use Superscript\Axiom\Types\OptionType;
use Superscript\Axiom\Types\RecordType;
use Superscript\Axiom\Types\Shapes\OptionShape;
use Superscript\Axiom\Types\Type;
use Superscript\Axiom\Types\TypeDescriber;
use Superscript\Monads\Result\Result;

use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

/** @internal The shared admission boundary for programs and compiled scopes. */
final readonly class InputBoundary
{
    private RecordType $inputs;

    /** @param list<ReferencePath> $references */
    public function __construct(
        RecordType $declarations,
        array $references,
        private Boundary $policy,
    ) {
        $this->inputs = $declarations->project($references);
    }

    /**
     * @param array<string, mixed> $raw
     * @return Result<Bindings, BoundaryViolation>
     */
    public function admit(array $raw): Result
    {
        $rejections = [];
        $overlay = [];
        $fault = false;

        foreach ($this->inputs->properties as $key => $property) {
            $type = $property->type;

            if (!array_key_exists($key, $raw)) {
                if (!$property->optional) {
                    $rejections[$key] = new RejectedBinding($key, sprintf('required input [%s] is missing', $key));
                }

                continue;
            }

            $value = $raw[$key];
            $admitted = match ($this->policy) {
                Boundary::Coerce => $type->coerce($value),
                Boundary::Assert => $type->assert(self::declaredSlice($type, $value)),
            };

            if ($admitted->isErr()) {
                $failure = $admitted->unwrapErr();

                if ($failure instanceof RecordPropertyViolation) {
                    $input = implode('.', [$key, ...$failure->path]);
                    $rejections[$key] = new RejectedBinding($input, $failure->missing
                        ? sprintf('required input [%s] is missing', $input)
                        : sprintf('binding [%s]: %s', $input, $failure->detail));
                    $fault = $fault || !$failure->missing;
                } else {
                    $rejections[$key] = new RejectedBinding($key, sprintf('binding [%s]: %s', $key, $failure->getMessage()));
                    $fault = true;
                }

                continue;
            }

            if ($admitted->unwrap()->isNone() && !$type->shape() instanceof OptionShape) {
                $rejections[$key] = new RejectedBinding($key, sprintf('binding [%s] reads as missing, but %s is required', $key, TypeDescriber::describe($type)));
                $fault = true;

                continue;
            }

            $overlay[$key] = $admitted->unwrap()->unwrapOr(null);
        }

        if ($rejections !== []) {
            return Err($fault
                ? new InadmissibleBinding(array_values($rejections))
                : new MissingRequiredInput(array_values($rejections)));
        }

        return Ok(new Bindings($overlay));
    }

    /** Strip unread nested properties before strict membership is asserted. */
    private static function declaredSlice(Type $type, mixed $value): mixed
    {
        while ($type instanceof OptionType) {
            $type = $type->inner;
        }

        if (!$type instanceof RecordType || !is_array($value)) {
            return $value;
        }

        $slice = [];

        foreach ($type->properties as $name => $property) {
            if (array_key_exists($name, $value)) {
                $slice[$name] = self::declaredSlice($property->type, $value[$name]);
            }
        }

        return $slice;
    }
}
