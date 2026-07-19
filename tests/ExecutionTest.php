<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests;

use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Superscript\Axiom\Bindings;
use Superscript\Axiom\CompiledNode;
use Superscript\Axiom\Execution\Annotated;
use Superscript\Axiom\Execution\Entered;
use Superscript\Axiom\Execution\Event;
use Superscript\Axiom\Execution\Exited;
use Superscript\Axiom\Execution\Node;
use Superscript\Axiom\Execution\Threw;
use Superscript\Axiom\Program;
use Superscript\Axiom\Runtime;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Tests\Fixtures\SpyObserver;
use Superscript\Axiom\Types\NumberType;
use Throwable;

use function Superscript\Monads\Option\Some;
use function Superscript\Monads\Result\Err;
use function Superscript\Monads\Result\Ok;

#[CoversClass(CompiledNode::class)]
#[CoversClass(Runtime::class)]
#[CoversClass(Node::class)]
#[CoversClass(Entered::class)]
#[CoversClass(Annotated::class)]
#[CoversClass(Exited::class)]
#[CoversClass(Threw::class)]
#[UsesClass(Program::class)]
#[UsesClass(Bindings::class)]
#[\PHPUnit\Framework\Attributes\UsesNamespace('Superscript\\Axiom\\Analysis')]
final class ExecutionTest extends TestCase
{
    #[Test]
    public function it_reports_an_ordered_nested_evaluation(): void
    {
        $type = new NumberType();
        $observer = new SpyObserver();

        $child = new CompiledNode(
            $type,
            function (Runtime $runtime) {
                $runtime->annotate('label', 'child');

                return Ok(Some(2));
            },
            'host.ChildSource',
        );
        $root = new CompiledNode(
            $type,
            function (Runtime $runtime) use ($child) {
                $result = $child->evaluate($runtime);
                $runtime->annotate('label', 'root');

                return $result;
            },
            'host.RootSource',
        );

        $result = $root->evaluate(new Runtime(observer: $observer));

        $this->assertSame(2, $result->unwrap()->unwrap());
        $this->assertSame(
            [Entered::class, Entered::class, Annotated::class, Exited::class, Annotated::class, Exited::class],
            array_map(static fn(Event $event): string => $event::class, $observer->events),
        );
        $this->assertSame(
            ['host.RootSource', 'host.ChildSource', 'host.ChildSource', 'host.ChildSource', 'host.RootSource', 'host.RootSource'],
            array_map(static fn(Event $event): string => $event->node->sourceType, $observer->events),
        );
        $this->assertSame($result, $observer->events[5]->result);
    }

    #[Test]
    public function it_reports_a_result_error_as_a_normal_exit(): void
    {
        $failure = new RuntimeException('not resolved');
        $observer = new SpyObserver();
        $node = new CompiledNode(new NumberType(), fn() => Err($failure));

        $result = $node->evaluate(new Runtime(observer: $observer));

        $this->assertSame($failure, $result->unwrapErr());
        $this->assertInstanceOf(Entered::class, $observer->events[0]);
        $this->assertInstanceOf(Exited::class, $observer->events[1]);
        $this->assertSame($result, $observer->events[1]->result);
    }

    #[Test]
    public function it_reports_a_thrown_exception_and_restores_the_scope(): void
    {
        $failure = new RuntimeException('host failure');
        $observer = new SpyObserver();
        $runtime = new Runtime(observer: $observer);
        $node = new CompiledNode(new NumberType(), fn() => throw $failure);

        try {
            $node->evaluate($runtime);
            $this->fail('The host exception should be rethrown.');
        } catch (Throwable $thrown) {
            $this->assertSame($failure, $thrown);
        }

        $this->assertInstanceOf(Entered::class, $observer->events[0]);
        $this->assertInstanceOf(Threw::class, $observer->events[1]);
        $this->assertSame($failure, $observer->events[1]->exception);

        $this->expectException(LogicException::class);
        $runtime->annotate('outside', true);
    }

    #[Test]
    public function an_observer_belongs_to_one_invocation(): void
    {
        $type = new NumberType();
        $program = new Program(new CompiledNode(
            $type,
            fn() => Ok(Some(42)),
            StaticSource::class,
        ));
        $observer = new SpyObserver();

        $program(observer: $observer);
        $eventCount = count($observer->events);
        $program();

        $this->assertGreaterThan(0, $eventCount);
        $this->assertCount($eventCount, $observer->events);
        $this->assertSame(StaticSource::class, $observer->events[0]->node->sourceType);
        $this->assertSame($type, $observer->events[0]->node->returns);
    }

    #[Test]
    public function annotation_is_a_no_op_without_an_observer(): void
    {
        $runtime = new Runtime();

        $runtime->annotate('ignored', true);

        $this->addToAssertionCount(1);
    }
}
