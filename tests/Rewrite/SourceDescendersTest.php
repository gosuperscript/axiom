<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Rewrite;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesNamespace;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Extension;
use Superscript\Axiom\Rewrite\SourceDescenders;
use Superscript\Axiom\Rewrite\SourcePath;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Tests\Rewrite\Fixtures\BoxExtension;
use Superscript\Axiom\Tests\Rewrite\Fixtures\BoxSource;

#[CoversClass(SourceDescenders::class)]
#[CoversClass(SourcePath::class)]
#[CoversClass(Extension::class)]
#[UsesNamespace('Superscript\\Axiom')]
final class SourceDescendersTest extends TestCase
{
    #[Test]
    public function an_extension_joins_its_arms_to_the_core_ones(): void
    {
        $descenders = SourceDescenders::core()->with(new BoxExtension());

        $this->assertArrayHasKey(BoxSource::class, $descenders->sources);
        $this->assertArrayHasKey(StaticSource::class, $descenders->sources, 'the core arms are always there');
        $this->assertArrayNotHasKey(BoxSource::class, SourceDescenders::core()->sources, 'joining derives a new registry');
    }

    #[Test]
    public function an_extension_contributes_no_descent_arms_by_default(): void
    {
        $extension = new class extends Extension {};

        $this->assertSame([], $extension->sourceDescenders());
        $this->assertSame(array_keys(SourceDescenders::core()->sources), array_keys(SourceDescenders::core()->with($extension)->sources));
    }

    #[Test]
    public function two_extensions_claiming_one_class_is_a_configuration_error(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Source class [' . BoxSource::class . '] has two descent arms; descent ownership is exact and extension order carries no precedence.');

        SourceDescenders::core()->with(new BoxExtension(), new BoxExtension());
    }

    #[Test]
    public function the_root_of_a_path_is_the_tree_itself(): void
    {
        $this->assertSame('$', SourcePath::root()->describe());
    }

    #[Test]
    public function a_path_segment_cannot_be_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A source path segment names a property and cannot be empty.');

        SourcePath::root()->child('');
    }
}
