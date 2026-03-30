<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Statements;

use Superscript\Axiom\Dsl\Ast\Location;
use Superscript\Axiom\Dsl\Ast\Node;

final readonly class NamespaceDeclarationNode implements StatementNode
{
    /**
     * @param list<Node> $body
     */
    public function __construct(
        public string $name,
        public array $body,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'NamespaceDeclaration',
            'name' => $this->name,
            'body' => array_map(fn(Node $node) => $node->toArray(), $this->body),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $name = $data['name'] ?? '';
        if (!is_string($name)) {
            throw new \RuntimeException('Expected string for name');
        }

        $rawBody = $data['body'] ?? [];
        if (!is_array($rawBody)) {
            throw new \RuntimeException('Expected array for body');
        }

        $loc = $data['loc'] ?? null;

        /** @var list<Node> $bodyNodes */
        $bodyNodes = [];
        foreach ($rawBody as $node) {
            if (!is_array($node)) {
                throw new \RuntimeException('Expected array for body node');
            }
            $bodyNodes[] = NodeFactory::fromArray($node);
        }

        return new self(
            name: $name,
            body: $bodyNodes,
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
