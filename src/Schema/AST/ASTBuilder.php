<?php

namespace Nuwave\Lighthouse\Schema\AST;

use GraphQL\Language\AST\Node;
use GraphQL\Language\AST\NodeList;
use GraphQL\Language\AST\TypeExtensionNode;
use GraphQL\Language\AST\FieldDefinitionNode;
use Nuwave\Lighthouse\Schema\DirectiveRegistry;
use GraphQL\Language\AST\InputValueDefinitionNode;
use GraphQL\Language\AST\ObjectTypeDefinitionNode;
use Nuwave\Lighthouse\Support\Contracts\ArgManipulator;
use Nuwave\Lighthouse\Support\Contracts\NodeManipulator;
use Nuwave\Lighthouse\Support\Contracts\FieldManipulator;
use Nuwave\Lighthouse\Schema\Extensions\ExtensionRegistry;

class ASTBuilder
{
    /**
     * @param string $schema
     *
     * @return DocumentAST
     */
    public static function generate(string $schema): DocumentAST
    {
        $document = DocumentAST::fromSource($schema);

        // Node manipulators may be defined on type extensions
        $document = self::applyNodeManipulators($document);
        // After they have been applied, we can safely merge them
        $document = self::mergeTypeExtensions($document);

        $document = self::applyFieldManipulators($document);
        $document = self::applyArgManipulators($document);

        $document = self::addPaginationInfoTypes($document);
        $document = resolve(ExtensionRegistry::class)->manipulate($document);

        return $document;
    }

    /**
     * @param DocumentAST $document
     *
     * @return DocumentAST
     */
    protected static function applyNodeManipulators(DocumentAST $document): DocumentAST
    {
        $originalDocument = $document;

        return $document->typeExtensionDefinitions()
            // This is just temporarily merged together
            ->concat($document->typeDefinitions())
            ->reduce(function (DocumentAST $document, Node $node) use (
                $originalDocument
            ) {
                $nodeManipulators = resolve(DirectiveRegistry::class)->nodeManipulators($node);

                return $nodeManipulators->reduce(function (DocumentAST $document, NodeManipulator $nodeManipulator) use (
                    $originalDocument,
                    $node
                ) {
                    return $nodeManipulator->manipulateSchema($node, $document, $originalDocument);
                }, $document);
            }, $document);
    }
  
    /**
     * @param DocumentAST $document
     *
     * @return DocumentAST
     */
    protected static function mergeTypeExtensions(DocumentAST $document): DocumentAST
    {
        $document->objectTypeDefinitions()->each(function (ObjectTypeDefinitionNode $objectType) use ($document) {
            $name = $objectType->name->value;

            $document->typeExtensionDefinitions($name)->reduce(function (
                ObjectTypeDefinitionNode $relatedObjectType,
                TypeExtensionNode $typeExtension
            ) {
                /** @var NodeList $fields */
                $fields = $relatedObjectType->fields;
                $relatedObjectType->fields = $fields->merge($typeExtension->fields);

                return $relatedObjectType;
            }, $objectType);

            // Modify the original document by overwriting the definition with the merged one
            $document->setDefinition($objectType);
        });

        return $document;
    }

    /**
     * @param DocumentAST $document
     *
     * @return DocumentAST
     */
    protected static function applyFieldManipulators(DocumentAST $document): DocumentAST
    {
        $originalDocument = $document;

        return $document->objectTypeDefinitions()->reduce(function (
            DocumentAST $document,
            ObjectTypeDefinitionNode $objectType
        ) use ($originalDocument) {
            return collect($objectType->fields)->reduce(function (
                DocumentAST $document,
                FieldDefinitionNode $fieldDefinition
            ) use ($objectType, $originalDocument) {
                $fieldManipulators = resolve(DirectiveRegistry::class)->fieldManipulators($fieldDefinition);

                return $fieldManipulators->reduce(function (
                    DocumentAST $document,
                    FieldManipulator $fieldManipulator
                ) use ($fieldDefinition, $objectType, $originalDocument) {
                    return $fieldManipulator->manipulateSchema($fieldDefinition, $objectType, $document,
                        $originalDocument);
                }, $document);
            }, $document);
        }, $document);
    }

    /**
     * @param DocumentAST $document
     *
     * @return DocumentAST
     */
    protected static function applyArgManipulators(DocumentAST $document): DocumentAST
    {
        $originalDocument = $document;

        return $document->objectTypeDefinitions()->reduce(
            function (DocumentAST $document, ObjectTypeDefinitionNode $parentType) use ($originalDocument) {
                return collect($parentType->fields)->reduce(
                    function (DocumentAST $document, FieldDefinitionNode $parentField) use (
                        $parentType,
                        $originalDocument
                    ) {
                        return collect($parentField->arguments)->reduce(
                            function (DocumentAST $document, InputValueDefinitionNode $argDefinition) use (
                                $parentType,
                                $parentField,
                                $originalDocument
                            ) {
                                $argManipulators = resolve(DirectiveRegistry::class)->argManipulators($argDefinition);

                                return $argManipulators->reduce(
                                    function (DocumentAST $document, ArgManipulator $argManipulator) use (
                                        $argDefinition,
                                        $parentField,
                                        $parentType,
                                        $originalDocument
                                    ) {
                                        return $argManipulator->manipulateSchema($argDefinition, $parentField,
                                            $parentType, $document, $originalDocument);
                                    }, $document);
                            }, $document);
                    }, $document);
            }, $document);
    }

    /**
     * @param DocumentAST $document
     *
     * @throws \Nuwave\Lighthouse\Exceptions\DocumentASTException
     * @throws \Nuwave\Lighthouse\Exceptions\ParseException
     *
     * @return DocumentAST
     */
    protected static function addPaginationInfoTypes(DocumentAST $document): DocumentAST
    {
        $paginatorInfo = PartialParser::objectTypeDefinition('
        type PaginatorInfo {
          "Total count of available items in the page."
          count: Int!
        
          "Current pagination page."
          currentPage: Int!
        
          "Index of first item in the current page."
          firstItem: Int!
        
          "If collection has more pages."
          hasMorePages: Boolean!
        
          "Index of last item in the current page."
          lastItem: Int!
        
          "Last page number of the collection."
          lastPage: Int!
        
          "Number of items per page in the collection."
          perPage: Int!
        
          "Total items available in the collection."
          total: Int!
        }
        ');
        $document->setDefinition($paginatorInfo);

        $pageInfo = PartialParser::objectTypeDefinition('
        type PageInfo {
          "When paginating forwards, are there more items?"
          hasNextPage: Boolean!
        
          "When paginating backwards, are there more items?"
          hasPreviousPage: Boolean!
        
          "When paginating backwards, the cursor to continue."
          startCursor: String
        
          "When paginating forwards, the cursor to continue."
          endCursor: String
        
          "Total number of node in connection."
          total: Int
        
          "Count of nodes in current request."
          count: Int
        
          "Current page of request."
          currentPage: Int
        
          "Last page in connection."
          lastPage: Int
        }
        ');
        $document->setDefinition($pageInfo);

        return $document;
    }
}
