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

/**
 * The shared admission boundary for programs and compiled scopes. Every root
 * and nested record path the compiled body reads is projected from the
 * declaration record; unread data is stripped before the selected policy
 * coerces or asserts what remains.
 *
 * Key presence and value absence are independent. A property is required
 * unless its declaration is wrapped in {@see \Superscript\Axiom\Types\Optional};
 * an {@see OptionType} permits a supplied value to read as absent but does not
 * permit its key to be omitted. Thus `Option<T>` still requires its key, while
 * `Optional(T)` permits omission but rejects a supplied absent value.
 *
 * Rejections aggregate once per root input, in projected first-read order. A
 * direct record failure refines that root to the first faulty nested path; a
 * later rejection for the same root cannot be added. Missing-only calls are
 * {@see MissingRequiredInput}; any malformed supplied value makes the whole
 * call {@see InadmissibleBinding}, so a fault dominates absence.
 *
 * Dynamic collection elements are values rather than declared input paths.
 * List and dictionary types retain their element context in the failure but
 * downgrade nested record violations before they reach this boundary, so they
 * remain faults on the collection's root input. Union types likewise replace
 * rejected member details with their own conversion failure when no member
 * admits a value; a nested missing property under a union is therefore a root
 * fault rather than a missing declared path.
 *
 * @internal Constructed only from a compiler-certified read set.
 */
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
        // Keyed by root input: each input is answered for once, and the first
        // nested path that explains its rejection remains the named one.
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

            // A supplied representation that reads as absent is malformed
            // unless the value type admits absence. When it does, null enters
            // the overlay so evaluation can distinguish that answer from an
            // omitted required key, which never reaches the overlay.
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

    /**
     * Strip unread nested properties before strict membership is asserted,
     * just as admission strips unread roots. RecordType remains exact; this
     * slices one compiled body's runtime signature before applying that type.
     */
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
