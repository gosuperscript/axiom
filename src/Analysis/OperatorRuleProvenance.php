<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

use InvalidArgumentException;
use Superscript\Axiom\Operators\BinaryOperatorRule;
use Superscript\Axiom\Operators\IdentifiedOperatorRule;
use Superscript\Axiom\Operators\UnaryOperatorRule;

/** Stable, data-only identity for the rule or core intrinsic selected during compilation. */
final readonly class OperatorRuleProvenance
{
    /** @param class-string $implementation */
    public function __construct(
        public string $identifier,
        public string $implementation,
        public ?string $extension,
    ) {}

    public static function of(BinaryOperatorRule|UnaryOperatorRule $rule, ?string $extension): self
    {
        $identifier = $rule instanceof IdentifiedOperatorRule ? $rule->identifier() : $rule::class;

        if ($identifier === '') {
            throw new InvalidArgumentException(sprintf('Operator rule [%s] returned an empty identifier.', $rule::class));
        }

        return new self(
            $identifier,
            $rule::class,
            $extension,
        );
    }

    /** @return array{identifier: string, implementation: class-string, extension: ?string} */
    public function toArray(): array
    {
        return [
            'identifier' => $this->identifier,
            'implementation' => $this->implementation,
            'extension' => $this->extension,
        ];
    }
}
