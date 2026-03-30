<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Expressions;

use Superscript\Axiom\Dsl\Ast\Location;

final readonly class MatchExpressionNode implements ExprNode
{
    /**
     * @param list<MatchArmNode> $arms
     */
    public function __construct(
        public ?ExprNode $subject,
        public array $arms,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'MatchExpression',
            'subject' => $this->subject?->toArray(),
            'arms' => array_map(fn(MatchArmNode $arm) => $arm->toArray(), $this->arms),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $subject = $data['subject'] ?? null;
        $arms = $data['arms'] ?? [];
        $loc = $data['loc'] ?? null;

        if (!is_array($arms)) {
            throw new \RuntimeException('Expected array for arms');
        }

        /** @var list<MatchArmNode> $armNodes */
        $armNodes = [];
        foreach ($arms as $arm) {
            if (!is_array($arm)) {
                throw new \RuntimeException('Expected array for arm');
            }
            $armNodes[] = MatchArmNode::fromArray($arm);
        }

        return new self(
            subject: is_array($subject) ? ExprNodeFactory::fromArray($subject) : null,
            arms: $armNodes,
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
