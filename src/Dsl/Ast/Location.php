<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast;

final readonly class Location
{
    public function __construct(
        public int $startLine,
        public int $startCol,
        public int $endLine,
        public int $endCol,
    ) {}

    /**
     * @return array{startLine: int, startCol: int, endLine: int, endCol: int}
     */
    public function toArray(): array
    {
        return [
            'startLine' => $this->startLine,
            'startCol' => $this->startCol,
            'endLine' => $this->endLine,
            'endCol' => $this->endCol,
        ];
    }

    /**
     * @param mixed[] $data
     */
    public static function fromArray(array $data): static
    {
        $startLine = $data['startLine'];
        $startCol = $data['startCol'];
        $endLine = $data['endLine'];
        $endCol = $data['endCol'];

        if (!is_int($startLine) || !is_int($startCol) || !is_int($endLine) || !is_int($endCol)) {
            throw new \RuntimeException('Location expects integer values for startLine, startCol, endLine, endCol');
        }

        return new self(
            startLine: $startLine,
            startCol: $startCol,
            endLine: $endLine,
            endCol: $endCol,
        );
    }
}
