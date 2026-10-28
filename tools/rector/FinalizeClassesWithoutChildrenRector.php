<?php

declare(strict_types=1);

namespace Tools\Rector;

use FilesystemIterator;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Rector\Rector\AbstractRector;
use Throwable;

/**
 * Adds final to classes that nothing in this service extends and that tests do not mock.
 * Rector dropped FinalizeClassesWithoutChildrenRector; this is the same check.
 */
final class FinalizeClassesWithoutChildrenRector extends AbstractRector
{
    /** @var array<string, true>|null */
    private static ?array $extended = null;

    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    /**
     * @param Class_ $node
     */
    public function refactor(Node $node): ?Node
    {
        if ($node->isAnonymous() || $node->isAbstract() || $node->isFinal()) {
            return null;
        }

        $className = $this->getName($node);
        if (! is_string($className) || $className === '') {
            return null;
        }

        $extended = $this->extendedClassNames();
        if (isset($extended[$className])) {
            return null;
        }

        $short = substr(strrchr($className, '\\') ?: '\\'.$className, 1);
        if (isset($extended[$short])) {
            return null;
        }

        $node->flags |= Modifiers::FINAL;

        return $node;
    }

    /**
     * @return array<string, true>
     */
    private function extendedClassNames(): array
    {
        if (self::$extended !== null) {
            return self::$extended;
        }

        self::$extended = [];
        $root = getcwd() ?: '';
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $finder = new NodeFinder();

        foreach (['app', 'bootstrap', 'config', 'database', 'routes', 'tests'] as $dir) {
            $path = $root.'/'.$dir;
            if (! is_dir($path)) {
                continue;
            }

            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            );

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $code = file_get_contents($file->getPathname());
                if ($code === false || (! str_contains($code, 'extends') && ! str_contains($code, '::class'))) {
                    continue;
                }

                try {
                    $stmts = $parser->parse($code);
                } catch (Throwable) {
                    continue;
                }
                if ($stmts === null) {
                    continue;
                }

                $traverser = new NodeTraverser();
                $traverser->addVisitor(new NameResolver());
                $stmts = $traverser->traverse($stmts);

                foreach ($finder->findInstanceOf($stmts, Class_::class) as $class) {
                    if (! $class instanceof Class_ || $class->extends === null) {
                        continue;
                    }
                    $parent = ltrim($class->extends->toString(), '\\');
                    if ($parent !== '') {
                        self::$extended[$parent] = true;
                    }
                }

                foreach ([MethodCall::class, StaticCall::class] as $callType) {
                    foreach ($finder->findInstanceOf($stmts, $callType) as $call) {
                        if (! $call instanceof MethodCall && ! $call instanceof StaticCall) {
                            continue;
                        }
                        if (! $call->name instanceof Identifier || $call->name->toString() !== 'mock') {
                            continue;
                        }
                        $arg = $call->args[0]->value ?? null;
                        if (! $arg instanceof ClassConstFetch || ! $arg->class instanceof Node\Name) {
                            continue;
                        }
                        if (! $arg->name instanceof Identifier || $arg->name->toString() !== 'class') {
                            continue;
                        }
                        $mocked = ltrim($arg->class->toString(), '\\');
                        if ($mocked !== '') {
                            self::$extended[$mocked] = true;
                        }
                    }
                }
            }
        }

        return self::$extended;
    }
}
