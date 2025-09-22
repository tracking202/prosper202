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

namespace Symfony\Component\Finder\Iterator;

/**
 * DepthRangeFilterIterator limits the directory depth.
 *
 * @author Fabien Potencier <fabien@symfony.com>
 */
class DepthRangeFilterIterator extends \FilterIterator
{
    /**
     * @param \RecursiveIteratorIterator $iterator The Iterator to filter
     * @param int                        $minDepth The min depth
     * @param int                        $maxDepth The max depth
     */
    public function __construct(\RecursiveIteratorIterator $iterator, private readonly int $minDepth = 0, int $maxDepth = PHP_INT_MAX)
    {
        $iterator->setMaxDepth(PHP_INT_MAX === $maxDepth ? -1 : $maxDepth);

        parent::__construct($iterator);
    }

    /**
     * Filters the iterator values.
     *
     * @return bool true if the value should be kept, false otherwise
     */
    public function accept()
    {
        return $this->getInnerIterator()->getDepth() >= $this->minDepth;
    }
}
