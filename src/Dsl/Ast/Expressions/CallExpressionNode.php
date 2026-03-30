<?php

declare(strict_types=1);

namespace Superscript\Axiom\Dsl\Ast\Expressions;

use Superscript\Axiom\Dsl\Ast\Location;

final readonly class CallExpressionNode implements ExprNode
{
    /**
     * @param list<ExprNode> $positionalArgs
     * @param array<string, ExprNode> $namedArgs
     */
    public function __construct(
        public string $callee,
        public array $positionalArgs,
        public array $namedArgs = [],
        public ?Location $loc = null,
    ) {}

    public function toArray(): array
    {
        return [
            'type' => 'CallExpression',
            'callee' => $this->callee,
            'positionalArgs' => array_map(fn(ExprNode $arg) => $arg->toArray(), $this->positionalArgs),
            'namedArgs' => array_map(fn(ExprNode $arg) => $arg->toArray(), $this->namedArgs),
            ...($this->loc ? ['loc' => $this->loc->toArray()] : []),
        ];
    }

    public static function fromArray(array $data): static
    {
        $callee = $data['callee'] ?? '';
        if (!is_string($callee)) {
            throw new \RuntimeException('Expected string for callee');
        }

        $positionalArgs = $data['positionalArgs'] ?? [];
        if (!is_array($positionalArgs)) {
            throw new \RuntimeException('Expected array for positionalArgs');
        }

        $rawNamedArgs = $data['namedArgs'] ?? [];
        if (!is_array($rawNamedArgs)) {
            throw new \RuntimeException('Expected array for namedArgs');
        }

        $loc = $data['loc'] ?? null;

        /** @var list<ExprNode> $posArgNodes */
        $posArgNodes = [];
        foreach ($positionalArgs as $arg) {
            if (!is_array($arg)) {
                throw new \RuntimeException('Expected array for positional arg');
            }
            $posArgNodes[] = ExprNodeFactory::fromArray($arg);
        }

        /** @var array<string, ExprNode> $namedArgNodes */
        $namedArgNodes = [];
        foreach ($rawNamedArgs as $key => $arg) {
            if (!is_string($key) || !is_array($arg)) {
                throw new \RuntimeException('Expected string key and array value for named arg');
            }
            $namedArgNodes[$key] = ExprNodeFactory::fromArray($arg);
        }

        return new self(
            callee: $callee,
            positionalArgs: $posArgNodes,
            namedArgs: $namedArgNodes,
            loc: is_array($loc) ? Location::fromArray($loc) : null,
        );
    }
}
