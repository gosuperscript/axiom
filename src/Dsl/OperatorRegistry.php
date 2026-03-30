<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl;

use RuntimeException;

final class OperatorRegistry
{
    /** @var array<string, OperatorEntry> */
    private array $operators = [];

    public function register(
        string $symbol,
        int $precedence,
        Associativity $associativity,
        OperatorPosition $position = OperatorPosition::Infix,
        bool $isKeyword = false,
    ): void {
        if (isset($this->operators[$symbol]) && $this->operators[$symbol]->precedence !== $precedence) {
            throw new RuntimeException("Operator '{$symbol}' is already registered at precedence {$this->operators[$symbol]->precedence}, cannot re-register at {$precedence}.");
        }

        $this->operators[$symbol] = new OperatorEntry($symbol, $precedence, $associativity, $position, $isKeyword);
    }

    public function get(string $symbol): ?OperatorEntry
    {
        return $this->operators[$symbol] ?? null;
    }

    public function isOperator(string $symbol): bool
    {
        return isset($this->operators[$symbol]);
    }

    public function isKeywordOperator(string $symbol): bool
    {
        return isset($this->operators[$symbol]) && $this->operators[$symbol]->isKeyword;
    }

    /**
     * @return list<OperatorEntry>
     */
    public function byPrecedence(): array
    {
        $entries = $this->operators;

        usort($entries, fn(OperatorEntry $a, OperatorEntry $b) => $a->precedence <=> $b->precedence);

        return $entries;
    }

    /**
     * @return array<string, OperatorEntry>
     */
    public function all(): array
    {
        return $this->operators;
    }
}
