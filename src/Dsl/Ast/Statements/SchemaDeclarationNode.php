<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Statements;

use Superscript\Axiom\Dsl\Ast\Location;
use Superscript\Axiom\Dsl\Ast\Node;

final readonly class SchemaDeclarationNode implements StatementNode
{
    /**
     * @param list<StatementNode> $members
     */
    public function __construct(
        public string $name,
        public array $members,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'SchemaDeclaration',
            'name' => $this->name,
            'members' => array_map(fn(Node $member) => $member->toArray(), $this->members),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $name = $data['name'] ?? '';
        if (!is_string($name)) {
            throw new \RuntimeException('Expected string for name');
        }

        $rawMembers = $data['members'] ?? [];
        if (!is_array($rawMembers)) {
            throw new \RuntimeException('Expected array for members');
        }

        $loc = $data['loc'] ?? null;

        /** @var list<StatementNode> $memberNodes */
        $memberNodes = [];
        foreach ($rawMembers as $member) {
            if (!is_array($member)) {
                throw new \RuntimeException('Expected array for member');
            }
            $memberNodes[] = NodeFactory::fromArray($member);
        }

        return new self(
            name: $name,
            members: $memberNodes,
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
