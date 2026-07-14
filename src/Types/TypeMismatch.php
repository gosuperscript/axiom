<?php

declare(strict_types=1);

namespace Superscript\Axiom\Types;

/**
 * The negative verdict of a type relation: a message plus a nested cause
 * chain. Relations have exactly two outcomes — Ok, or Err(TypeMismatch);
 * there is no boolean channel.
 *
 * A mismatch is $dead when the operation is runtime-tolerated but
 * statically meaningless — a comparison or membership test that can never
 * hold. Dead mismatches flag probable author bugs; ordinary mismatches
 * flag operations the runtime does not support. The agreement harness
 * exempts dead refusals from its anti-shadowing law for exactly this
 * distinction.
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
