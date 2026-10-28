<?php

declare(strict_types=1);

namespace Tools\Rector;

use PhpParser\Node;
use PhpParser\Node\Stmt\Declare_;
use PhpParser\Node\Stmt\Nop;
use Rector\PhpParser\Node\FileNode;
use Rector\Rector\AbstractRector;

/**
 * Adds declare(strict_types=1) to every PHP file.
 * Rector's built-in rule now refuses to do this unless the file is already type-safe.
 */
final class AddDeclareStrictTypesRector extends AbstractRector
{
    public function getNodeTypes(): array
    {
        return [FileNode::class];
    }

    /**
     * @param FileNode $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($this->getFile()->containsHTML()) {
            return null;
        }

        foreach ($node->stmts as $stmt) {
            if (! $stmt instanceof Declare_) {
                continue;
            }
            foreach ($stmt->declares as $declare) {
                if ($declare->key->toString() === 'strict_types') {
                    return null;
                }
            }
        }

        $node->stmts = array_merge(
            [$this->nodeFactory->createDeclaresStrictType(), new Nop()],
            $node->stmts,
        );

        return $node;
    }
}
