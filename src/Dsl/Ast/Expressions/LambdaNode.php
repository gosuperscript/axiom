<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Expressions;

use Superscript\Axiom\Dsl\Ast\Location;

final readonly class LambdaNode implements ExprNode
{
    /**
     * @param list<string> $params
     */
    public function __construct(
        public array $params,
        public ExprNode $body,
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'Lambda',
            'params' => $this->params,
            'body' => $this->body->toArray(),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $params = $data['params'] ?? [];
        if (!is_array($params)) {
            throw new \RuntimeException('Expected array for params');
        }

        $body = $data['body'] ?? [];
        if (!is_array($body)) {
            throw new \RuntimeException('Expected array for body');
        }

        $loc = $data['loc'] ?? null;

        /** @var list<string> $paramList */
        $paramList = array_values($params);

        return new self(
            params: $paramList,
            body: ExprNodeFactory::fromArray($body),
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
