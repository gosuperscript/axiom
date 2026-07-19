<?php

declare(strict_types=1);

namespace Superscript\Axiom\Analysis;

use Superscript\Axiom\Types\Type;

/** The typed operator dispatch selected for one compiled source node. */
final readonly class OperatorSelection
{
    /** @param non-empty-list<Type> $operands */
    public function __construct(
        public string $kind,
        public string $operator,
        public array $operands,
        public Type $returns,
        public OperatorRuleProvenance $rule,
    ) {}

    /**
     * @return array{
     *     path: string,
     *     kind: string,
     *     operator: string,
     *     operands: non-empty-list<string>,
     *     returns: string,
     *     rule: array{identifier: string, implementation: class-string, extension: ?string}
     * }
     */
    public function toArray(string $path, bool $revealLiterals = false): array
    {
        return [
            'path' => $path,
            'kind' => $this->kind,
            'operator' => $this->operator,
            'operands' => array_map(
                fn(Type $type): string => AnalysisTypeDescriber::describe($type, $revealLiterals),
                $this->operands,
            ),
            'returns' => AnalysisTypeDescriber::describe($this->returns, $revealLiterals),
            'rule' => $this->rule->toArray(),
        ];
    }
}
