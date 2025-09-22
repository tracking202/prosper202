<?php
declare(strict_types=1);
/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Finder\Comparator;

/**
 * Comparator.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class Comparator
{
    private $target;
    private $operator = '==';

    /**
     * Gets the target value.
     *
     * @return string The target value
     */
    public function getTarget()
    {
        return $this->target;
    }

    /**
     * Sets the target value.
     *
     * @param string $target The target value
     */
    public function setTarget($target)
    {
        $this->target = $target;
    }

    /**
     * Gets the comparison operator.
     *
     * @return string The operator
     */
    public function getOperator()
    {
        return $this->operator;
    }

    /**
     * Sets the comparison operator.
     *
     * @param string $operator A valid operator
     *
     * @throws \InvalidArgumentException
     */
    public function setOperator($operator)
    {
        if (!$operator) {
            $operator = '==';
        }

        if (!in_array($operator, ['>', '<', '>=', '<=', '==', '!='])) {
            throw new \InvalidArgumentException(sprintf('Invalid operator "%s".', $operator));
        }

        $this->operator = $operator;
    }

    /**
     * Tests against the target.
     *
     * @param mixed $test A test value
     *
     * @return bool
     */
    public function test($test)
    {
        return match ($this->operator) {
            '>' => $test > $this->target,
            '>=' => $test >= $this->target,
            '<' => $test < $this->target,
            '<=' => $test <= $this->target,
            '!=' => $test != $this->target,
            default => $test == $this->target,
        };
    }
}
