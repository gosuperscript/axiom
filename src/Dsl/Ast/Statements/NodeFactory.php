<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Statements;

use RuntimeException;
use Superscript\Axiom\Dsl\Ast\Node;

final class NodeFactory
{
    /**
     * @param mixed[] $data
     */
    public static function fromArray(array $data): Node
    {
        $type = $data['type'] ?? null;
        if (!is_string($type)) {
            throw new RuntimeException('Expected string for type');
        }

        return match ($type) {
            'SymbolDeclaration' => SymbolDeclarationNode::fromArray($data),
            'NamespaceDeclaration' => NamespaceDeclarationNode::fromArray($data),
            'SchemaDeclaration' => SchemaDeclarationNode::fromArray($data),
            'InputDeclaration' => InputDeclarationNode::fromArray($data),
            'AssertStatement' => AssertStatementNode::fromArray($data),
            default => throw new RuntimeException("Unknown statement node type: {$type}"),
        };
    }
}
