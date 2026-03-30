<?php

declare(strict_types=1);

namespace Superscript\Axiom\Tests\Dsl;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use Superscript\Axiom\Dsl\Ast\Expressions\CoercionExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\DictLiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ExprNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ExprNodeFactory;
use Superscript\Axiom\Dsl\Ast\Expressions\IdentifierNode;
use Superscript\Axiom\Dsl\Ast\Expressions\IndexExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\InfixExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\ListLiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\LiteralNode;
use Superscript\Axiom\Dsl\Ast\Expressions\MemberExpressionNode;
use Superscript\Axiom\Dsl\Ast\Expressions\UnaryExpressionNode;
use Superscript\Axiom\Dsl\Ast\Location;
use Superscript\Axiom\Dsl\Ast\ProgramNode;
use Superscript\Axiom\Dsl\Ast\Statements\NamespaceDeclarationNode;
use Superscript\Axiom\Dsl\Ast\Statements\NodeFactory;
use Superscript\Axiom\Dsl\Ast\Statements\SymbolDeclarationNode;
use Superscript\Axiom\Dsl\Ast\TypeAnnotationNode;
use Superscript\Axiom\Dsl\Associativity;
use Superscript\Axiom\Dsl\Compiler\CompilationResult;
use Superscript\Axiom\Dsl\Compiler\Compiler;
use Superscript\Axiom\Dsl\CoreDslPlugin;
use Superscript\Axiom\Dsl\DslLiteralExtension;
use Superscript\Axiom\Dsl\FunctionEntry;
use Superscript\Axiom\Dsl\FunctionParam;
use Superscript\Axiom\Dsl\FunctionRegistry;
use Superscript\Axiom\Dsl\Lexer\Lexer;
use Superscript\Axiom\Dsl\Lexer\Token;
use Superscript\Axiom\Dsl\Lexer\TokenType;
use Superscript\Axiom\Dsl\OperatorEntry;
use Superscript\Axiom\Dsl\OperatorPosition;
use Superscript\Axiom\Dsl\OperatorRegistry;
use Superscript\Axiom\Dsl\Parser\Parser;
use Superscript\Axiom\Dsl\Parser\TokenStream;
use Superscript\Axiom\Dsl\PrettyPrinter\PrettyPrinter;
use Superscript\Axiom\Dsl\TypeRegistry;
use Superscript\Axiom\Operators\BinaryOverloader;
use Superscript\Axiom\Operators\ComparisonOverloader;
use Superscript\Axiom\Operators\DefaultOverloader;
use Superscript\Axiom\Operators\HasOverloader;
use Superscript\Axiom\Operators\InOverloader;
use Superscript\Axiom\Operators\IntersectsOverloader;
use Superscript\Axiom\Operators\LogicalOverloader;
use Superscript\Axiom\Operators\NullOverloader;
use Superscript\Axiom\Patterns\LiteralMatcher;
use Superscript\Axiom\Patterns\WildcardMatcher;
use Superscript\Axiom\Source;
use Superscript\Axiom\Sources\StaticSource;
use Superscript\Axiom\Sources\SymbolSource;
use Superscript\Axiom\Sources\TypeDefinition;
use Superscript\Axiom\SymbolRegistry;
use Superscript\Axiom\Types\BooleanType;
use Superscript\Axiom\Types\DictType;
use Superscript\Axiom\Types\ListType;
use Superscript\Axiom\Types\NumberType;
use Superscript\Axiom\Types\StringType;

/**
 * Tests the DslLiteralExtension hooks in Parser, Compiler, and PrettyPrinter.
 */
#[CoversClass(Parser::class)]
#[CoversClass(Compiler::class)]
#[CoversClass(PrettyPrinter::class)]
#[UsesClass(Lexer::class)]
#[UsesClass(Token::class)]
#[UsesClass(TokenType::class)]
#[UsesClass(TokenStream::class)]
#[UsesClass(CompilationResult::class)]
#[UsesClass(OperatorRegistry::class)]
#[UsesClass(OperatorEntry::class)]
#[UsesClass(Associativity::class)]
#[UsesClass(OperatorPosition::class)]
#[UsesClass(TypeRegistry::class)]
#[UsesClass(CoreDslPlugin::class)]
#[UsesClass(FunctionRegistry::class)]
#[UsesClass(FunctionEntry::class)]
#[UsesClass(FunctionParam::class)]
#[UsesClass(ProgramNode::class)]
#[UsesClass(SymbolDeclarationNode::class)]
#[UsesClass(NamespaceDeclarationNode::class)]
#[UsesClass(LiteralNode::class)]
#[UsesClass(IdentifierNode::class)]
#[UsesClass(InfixExpressionNode::class)]
#[UsesClass(UnaryExpressionNode::class)]
#[UsesClass(MemberExpressionNode::class)]
#[UsesClass(IndexExpressionNode::class)]
#[UsesClass(CoercionExpressionNode::class)]
#[UsesClass(ListLiteralNode::class)]
#[UsesClass(DictLiteralNode::class)]
#[UsesClass(TypeAnnotationNode::class)]
#[UsesClass(Location::class)]
#[UsesClass(ExprNodeFactory::class)]
#[UsesClass(NodeFactory::class)]
#[UsesClass(StaticSource::class)]
#[UsesClass(SymbolSource::class)]
#[UsesClass(TypeDefinition::class)]
#[UsesClass(SymbolRegistry::class)]
#[UsesClass(DefaultOverloader::class)]
#[UsesClass(NullOverloader::class)]
#[UsesClass(BinaryOverloader::class)]
#[UsesClass(ComparisonOverloader::class)]
#[UsesClass(HasOverloader::class)]
#[UsesClass(InOverloader::class)]
#[UsesClass(LogicalOverloader::class)]
#[UsesClass(IntersectsOverloader::class)]
#[UsesClass(WildcardMatcher::class)]
#[UsesClass(LiteralMatcher::class)]
#[UsesClass(NumberType::class)]
#[UsesClass(StringType::class)]
#[UsesClass(BooleanType::class)]
#[UsesClass(ListType::class)]
#[UsesClass(DictType::class)]
class DslLiteralExtensionTest extends TestCase
{
    #[Test]
    public function it_uses_literal_extensions_in_parser_compiler_and_printer(): void
    {
        $operatorRegistry = new OperatorRegistry();
        $typeRegistry = new TypeRegistry();
        $plugin = new CoreDslPlugin();
        $plugin->operators($operatorRegistry);
        $plugin->types($typeRegistry);

        // Create a test literal extension that matches 'special' identifier
        $extension = new class implements DslLiteralExtension {
            public function canParse(Token $current, TokenStream $stream): bool
            {
                return $current->type === TokenType::Ident && $current->value === 'special';
            }

            public function parse(Parser $parser): ExprNode
            {
                $parser->getStream()->advance(); // consume 'special'

                return new LiteralNode('SPECIAL_VALUE', 'special');
            }

            public function compile(ExprNode $node, Compiler $compiler): Source
            {
                return new StaticSource('compiled_special');
            }

            public function handles(ExprNode $node): bool
            {
                return $node instanceof LiteralNode && $node->raw === 'special';
            }

            public function prettyPrint(ExprNode $node, PrettyPrinter $printer, int $parentPrecedence): string
            {
                return 'special';
            }
        };

        $lexer = new Lexer($operatorRegistry);
        $parser = new Parser($operatorRegistry, [$extension]);
        $compiler = new Compiler($typeRegistry, [$extension]);
        $printer = new PrettyPrinter($operatorRegistry, [$extension]);

        // Test parsing
        $tokens = $lexer->tokenize('x: number = special');
        $program = $parser->parse($tokens);
        $stmt = $program->body[0];
        $this->assertInstanceOf(SymbolDeclarationNode::class, $stmt);
        $this->assertInstanceOf(LiteralNode::class, $stmt->expression);
        $this->assertSame('SPECIAL_VALUE', $stmt->expression->value);

        // Test compiling
        $result = $compiler->compileProgram($program);
        $source = $result->symbols->get('x')->unwrap();
        $this->assertInstanceOf(TypeDefinition::class, $source);
        $this->assertInstanceOf(StaticSource::class, $source->source);
        $this->assertSame('compiled_special', $source->source->value);

        // Test pretty printing
        $output = $printer->print($program);
        $this->assertSame('x: number = special', $output);
    }
}
