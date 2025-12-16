<?php

declare(strict_types=1);

namespace Superscript\Lookups;

use League\Csv\Reader;
use Superscript\Lookups\Support\Aggregates\Aggregate;
use Superscript\Lookups\Support\Aggregates\AggregateEnum;
use Superscript\Lookups\Support\Aggregates\Average;
use Superscript\Lookups\Support\Aggregates\Count;
use Superscript\Lookups\Support\Aggregates\First;
use Superscript\Lookups\Support\Aggregates\Last;
use Superscript\Lookups\Support\Aggregates\Max;
use Superscript\Lookups\Support\Aggregates\Min;
use Superscript\Lookups\Support\Aggregates\Sum;
use Superscript\Lookups\Support\Filters\Filter;
use Superscript\Monads\Option\Option;
use Superscript\Monads\Result\Err;
use Superscript\Monads\Result\Result;
use Superscript\Schema\Resolvers\Resolver;
use Superscript\Schema\Source;
use Throwable;

use function Psl\Iter\all;
use function Superscript\Monads\Option\None;
use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Ok;

/**
 * @implements Resolver<LookupSource>
 */
final readonly class LookupResolver implements Resolver
{
    public function __construct(
        private Resolver $resolver,
    ) {}

    /**
     * @param  LookupSource  $source
     * @return Result<Option<mixed>, Throwable>
     */
    public function resolve(Source $source): Result
    {
        try {
            // Read and parse the CSV/TSV file
            $reader = Reader::from($source->filePath);
            $reader->setDelimiter($source->delimiter);

            if ($source->hasHeader) {
                $reader->setHeaderOffset(0);
            }

            // Stream through records with memory-efficient processing
            $records = $source->hasHeader ? $reader->getRecords() : $reader->getRecords([]);

            // Initialize aggregate-specific state using value objects
            $aggregateState = $this->createAggregateState($source->aggregate);

            foreach ($records as $record) {
                /** @var array<string, mixed> $record */
                $csvRecord = CsvRecord::from($record);

                if ($this->matchesFilters($csvRecord, $source->filters)) {
                    // Process record immediately with immutable value object
                    $aggregateState = $aggregateState->process($csvRecord, $source->aggregateColumn);

                    // Early exit optimization for 'first' aggregate
                    if ($aggregateState->canEarlyExit()) {
                        break;
                    }
                }
            }

            // Finalize and extract result from aggregate state
            $result = $aggregateState->finalize($source->columns);

            if ($result === null) {
                return Ok(None());
            }

            return Ok(Some($result));
        } catch (Throwable $e) {
            return new Err($e);
        }
    }

    /**
     * Create appropriate aggregate state value object
     */
    private function createAggregateState(AggregateEnum $aggregate): Aggregate
    {
        return match ($aggregate) {
            AggregateEnum::FIRST => First::initial(),
            AggregateEnum::LAST => Last::initial(),
            AggregateEnum::COUNT => Count::initial(),
            AggregateEnum::SUM => Sum::initial(),
            AggregateEnum::AVG => Average::initial(),
            AggregateEnum::MIN => Min::initial(),
            AggregateEnum::MAX => Max::initial(),
        };
    }

    /**
     * @param  list<Filter>  $filters
     */
    private function matchesFilters(CsvRecord $record, array $filters): bool
    {
        return all($filters, fn (Filter $filter) => $filter->matches($record, $this->resolver->resolve($filter->value)->unwrapOr(false)->unwrapOr(false)));
    }
}
