<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast;

final readonly class TypeAnnotationNode implements Node
{
    /**
     * @param list<TypeAnnotationNode> $args
     */
    public function __construct(
        public string $keyword,
        public array $args = [],
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'TypeAnnotation',
            'keyword' => $this->keyword,
            'args' => array_map(fn(self $arg) => $arg->toArray(), $this->args),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $keyword = $data['keyword'] ?? '';
        if (!is_string($keyword)) {
            throw new \RuntimeException('Expected string for keyword');
        }

        $rawArgs = $data['args'] ?? [];
        if (!is_array($rawArgs)) {
            throw new \RuntimeException('Expected array for args');
        }

        $loc = $data['loc'] ?? null;

        /** @var list<TypeAnnotationNode> $argNodes */
        $argNodes = [];
        foreach ($rawArgs as $arg) {
            if (!is_array($arg)) {
                throw new \RuntimeException('Expected array for arg');
            }
            $argNodes[] = self::fromArray($arg);
        }

        return new self(
            keyword: $keyword,
            args: $argNodes,
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
