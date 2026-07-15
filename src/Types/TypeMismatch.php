<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

/**
 * The negative verdict of a type relation: a message plus a nested cause
 * chain. Relations have exactly two outcomes — Ok, or Err(TypeMismatch);
 * there is no boolean channel.
 *
 * A mismatch is $dead when the operation is well-formed but statically
 * meaningless — a comparison or membership test that can never hold. Dead
 * mismatches flag probable author bugs; ordinary mismatches flag
 * operations no rule resolves.
 *
 * A mismatch is $unhandled when a rule refuses an operator that simply is
 * not its own — the refusal says nothing about the operand types, so the
 * composing manager keeps it out of aggregated diagnostics.
 */
final readonly class TypeMismatch
{
    /**
     * @param list<TypeMismatch> $causes
     */
    public function __construct(
        public string $message,
        public array $causes = [],
        public bool $dead = false,
        public bool $unhandled = false,
    ) {}

    public function describe(): string
    {
        return $this->render(0);
    }

    private function render(int $depth): string
    {
        $lines = str_repeat('  ', $depth) . $this->message;

        foreach ($this->causes as $cause) {
            $lines .= "\n" . $cause->render($depth + 1);
        }

        return $lines;
    }
}
