<?php

declare(strict_types=1);

namespace Superscript\Lookups\Support\Aggregates;

enum AggregateEnum: string
{
    case AVG = 'average';
    case FIRST = 'first';
    case LAST = 'last';
    case COUNT = 'count';
    case SUM = 'sum';
    case MIN = 'min';
    case MAX = 'max';

    /**
     * @return list<AggregateEnum>
     */
    public static function numericAggregates(): array
    {
        return [
            self::SUM,
            self::AVG,
            self::MIN,
            self::MAX,
        ];
    }
}