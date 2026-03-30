<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Expressions;

use Superscript\Axiom\Dsl\Ast\Location;

final readonly class DictLiteralNode implements ExprNode
{
    /**
     * @param list<array{key: ExprNode, value: ExprNode}> $entries
     */
    public function __construct(
        public array $entries,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'DictLiteral',
            'entries' => array_map(fn(array $entry) => [
                'key' => $entry['key']->toArray(),
                'value' => $entry['value']->toArray(),
            ], $this->entries),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $entries = $data['entries'] ?? [];
        if (!is_array($entries)) {
            throw new \RuntimeException('Expected array for entries');
        }

        $loc = $data['loc'] ?? null;

        /** @var list<array{key: ExprNode, value: ExprNode}> $entryNodes */
        $entryNodes = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new \RuntimeException('Expected array for entry');
            }
            $keyData = $entry['key'] ?? [];
            $valueData = $entry['value'] ?? [];
            if (!is_array($keyData) || !is_array($valueData)) {
                throw new \RuntimeException('Expected arrays for key and value');
            }
            $entryNodes[] = [
                'key' => ExprNodeFactory::fromArray($keyData),
                'value' => ExprNodeFactory::fromArray($valueData),
            ];
        }

        return new self(
            entries: $entryNodes,
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
