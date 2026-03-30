<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Statements;

use RuntimeException;

final class NodeFactory
{
    /**
     * @param mixed[] $data
     */
    public static function fromArray(array $data): StatementNode
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
