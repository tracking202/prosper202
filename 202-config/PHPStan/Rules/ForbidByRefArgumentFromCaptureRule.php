<?php

declare(strict_types=1);

namespace Prosper202\PHPStan\Rules;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ParametersAcceptorSelector;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Forbids handing a captured variable to a by-reference parameter from inside
 * an arrow function, or a closure that captured it by value, when nothing in
 * that body reads it again.
 *
 * CLAUDE.md #8: assumed closure, reference, and capture semantics. An arrow
 * function captures every outer variable it names by value, and a closure's
 * plain `use ($x)` does the same. A callee that reports through a by-reference
 * parameter therefore writes the function's own copy, which is discarded when
 * it returns: the caller's variable never changes, and nothing says so.
 *
 * It shipped in UpdateController::cpc():
 *
 *     $ownership = [];
 *     $labels = $this->guard(fn () => CpcUpdate::labels($conn, $values, $userId, $ownership));
 *     if ($ownership !== []) { refuse }
 *
 * labels() reports a foreign id through `array &$errors`. The refusal landed
 * in the arrow function's copy, $ownership stayed empty, and a CPC request
 * naming another account's campaign was answered 200 — found by the instance
 * test, not by reading. ForbidArrowFnByRefCaptureRule covers the mirror image
 * (an arrow function reading a variable an outer closure shares by
 * reference); this is the write half.
 *
 * What is reported: an argument that is a captured variable (or an element of
 * one) in a by-reference position of a call the rule can resolve — a
 * function, a static or instance method, a constructor — where the variable
 * appears nowhere else in the function's body. A body that reads the copy
 * after the call (`fn () => preg_match($re, $s, $m) ? $m[1] : null`) uses
 * the write locally and is left alone, as is a variable the outer scope does
 * not define (the call creates a local), a call whose callee cannot be
 * resolved, and a by-reference closure capture (`use (&$x)`), where the write
 * does reach the caller.
 *
 * @implements Rule<Node\FunctionLike>
 */
final class ForbidByRefArgumentFromCaptureRule implements Rule
{
    public function __construct(private readonly ReflectionProvider $reflectionProvider)
    {
    }

    public function getNodeType(): string
    {
        return Node\FunctionLike::class;
    }

    /**
     * @param Node\FunctionLike $node
     * @return list<\PHPStan\Rules\IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node instanceof Node\Expr\ArrowFunction) {
            $own = [];
            foreach ($node->params as $param) {
                if ($param->var instanceof Node\Expr\Variable && is_string($param->var->name)) {
                    $own[$param->var->name] = true;
                }
            }
            // Whatever the arrow function names that the enclosing scope
            // defines is captured, by value.
            $captured = static fn (string $name): bool => !isset($own[$name]) && $name !== 'this'
                && $scope->hasVariableType($name)->yes();
            $body = [$node->expr];
            $what = 'Arrow function';
        } elseif ($node instanceof Node\Expr\Closure) {
            $byValue = [];
            foreach ($node->uses as $use) {
                if (!$use->byRef && $use->var instanceof Node\Expr\Variable && is_string($use->var->name)) {
                    $byValue[$use->var->name] = true;
                }
            }
            if ($byValue === []) {
                return [];
            }
            $captured = static fn (string $name): bool => isset($byValue[$name]);
            $body = $node->stmts;
            $what = 'Closure';
        } else {
            return [];
        }

        $finder = new NodeFinder();
        $occurrences = [];
        foreach ($finder->findInstanceOf($body, Node\Expr\Variable::class) as $variable) {
            /** @var Node\Expr\Variable $variable */
            if (is_string($variable->name)) {
                $occurrences[$variable->name] = ($occurrences[$variable->name] ?? 0) + 1;
            }
        }

        $errors = [];
        foreach ($this->ownCalls($body) as $call) {
            $acceptor = $this->parametersOf($call, $scope);
            if ($acceptor === null) {
                continue;
            }
            foreach ($this->byRefArguments($call, $acceptor) as [$arg, $parameter]) {
                $root = $arg->value;
                while ($root instanceof Node\Expr\ArrayDimFetch) {
                    $root = $root->var;
                }
                if (!$root instanceof Node\Expr\Variable || !is_string($root->name)) {
                    continue;
                }
                $name = $root->name;
                if (!$captured($name) || ($occurrences[$name] ?? 0) > 1) {
                    continue;
                }
                $errors[] = RuleErrorBuilder::message(sprintf(
                    '%s passes $%s, which it captured by value, to by-reference parameter $%s: the callee writes the '
                    . 'function\'s own copy, which is discarded, so the caller\'s $%s never changes. Make the call '
                    . 'outside the function, or capture it by reference (use (&$%s)). (CLAUDE.md #8)',
                    $what,
                    $name,
                    $parameter->getName(),
                    $name,
                    $name
                ))
                    ->identifier('prosper202.byRefArgumentFromCapture')
                    ->line($call->getStartLine())
                    ->build();
            }
        }

        return $errors;
    }

    /**
     * The calls in this function's own body; those inside a nested closure
     * or arrow function are that function's, and visited with it.
     *
     * @param array<Node> $nodes
     * @return list<Node\Expr\CallLike>
     */
    private function ownCalls(array $nodes): array
    {
        $calls = [];
        foreach ($nodes as $node) {
            if ($node instanceof Node\FunctionLike) {
                continue;
            }
            if ($node instanceof Node\Expr\CallLike) {
                $calls[] = $node;
            }
            foreach ($node->getSubNodeNames() as $name) {
                $sub = $node->$name;
                if ($sub instanceof Node) {
                    array_push($calls, ...$this->ownCalls([$sub]));
                } elseif (is_array($sub)) {
                    array_push($calls, ...$this->ownCalls(array_filter($sub, static fn ($item): bool => $item instanceof Node)));
                }
            }
        }
        return $calls;
    }

    private function parametersOf(Node\Expr\CallLike $call, Scope $scope): ?ParametersAcceptor
    {
        if ($call->isFirstClassCallable()) {
            return null;
        }
        $variants = null;
        if ($call instanceof Node\Expr\FuncCall && $call->name instanceof Node\Name) {
            if ($this->reflectionProvider->hasFunction($call->name, $scope)) {
                $variants = $this->reflectionProvider->getFunction($call->name, $scope)->getVariants();
            }
        } elseif (($call instanceof Node\Expr\StaticCall || $call instanceof Node\Expr\New_) && $call->class instanceof Node\Name) {
            $class = $scope->resolveName($call->class);
            if ($this->reflectionProvider->hasClass($class)) {
                $reflection = $this->reflectionProvider->getClass($class);
                if ($call instanceof Node\Expr\New_) {
                    $variants = $reflection->hasConstructor() ? $reflection->getConstructor()->getVariants() : null;
                } elseif ($call->name instanceof Node\Identifier && $reflection->hasMethod($call->name->toString())) {
                    $variants = $reflection->getMethod($call->name->toString(), $scope)->getVariants();
                }
            }
        } elseif (($call instanceof Node\Expr\MethodCall || $call instanceof Node\Expr\NullsafeMethodCall) && $call->name instanceof Node\Identifier) {
            $type = $scope->getType($call->var);
            if ($type->hasMethod($call->name->toString())->yes()) {
                $variants = $type->getMethod($call->name->toString(), $scope)->getVariants();
            }
        }
        if ($variants === null || $variants === []) {
            return null;
        }
        return count($variants) === 1 ? $variants[0] : ParametersAcceptorSelector::selectFromArgs($scope, $call->getArgs(), $variants);
    }

    /**
     * The arguments in a by-reference position, with their parameter.
     *
     * @return list<array{0: Node\Arg, 1: ParameterReflection}>
     */
    private function byRefArguments(Node\Expr\CallLike $call, ParametersAcceptor $acceptor): array
    {
        if ($call->isFirstClassCallable()) {
            return []; // `f(...)` passes nothing: its one argument is a placeholder
        }
        $parameters = $acceptor->getParameters();
        $byName = [];
        foreach ($parameters as $parameter) {
            $byName[$parameter->getName()] = $parameter;
        }
        $last = $parameters === [] ? null : $parameters[count($parameters) - 1];

        $out = [];
        foreach ($call->getArgs() as $position => $arg) {
            if ($arg->unpack) {
                break;
            }
            if ($arg->name !== null) {
                $parameter = $byName[$arg->name->toString()] ?? null;
            } else {
                $parameter = $parameters[$position] ?? ($last !== null && $last->isVariadic() ? $last : null);
            }
            if ($parameter !== null && $parameter->passedByReference()->yes()) {
                $out[] = [$arg, $parameter];
            }
        }
        return $out;
    }
}
