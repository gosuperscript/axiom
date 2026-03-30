<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast;

use Superscript\Axiom\Dsl\Ast\Statements\NodeFactory;

final readonly class ProgramNode implements Node
{
    /**
     * @param list<Node> $body
     */
    public function __construct(
        public array $body,
        public string $version = '1.0',
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'Program',
            'version' => $this->version,
            'body' => array_map(fn(Node $node) => $node->toArray(), $this->body),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $rawBody = $data['body'] ?? [];
        if (!is_array($rawBody)) {
            throw new \RuntimeException('Expected array for body');
        }

        $version = $data['version'] ?? '1.0';
        if (!is_string($version)) {
            $version = '1.0';
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
            body: $bodyNodes,
            version: $version,
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
