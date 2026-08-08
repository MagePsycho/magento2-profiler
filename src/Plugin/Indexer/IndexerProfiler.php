<?php
/**
 * This file is part of the MagePsycho_Profiler package.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this package
 * to newer versions in the future.
 *
 * @author   Raj KB <rajkb@magepsycho.com>
 * @license  Open Software License (OSL 3.0)
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
declare(strict_types=1);

namespace MagePsycho\Profiler\Plugin\Indexer;

use Magento\Framework\Indexer\IndexerInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times each indexer as "INDEXER:catalog_product_price::reindexAll".
 *
 * `bin/magento indexer:reindex` currently profiles as an undifferentiated wall of SQL. These timers sit
 * directly above those SQL rows and attribute them to an indexer, which is the whole point of profiling
 * a CLI run. The indexer id comes from the subject itself, so cardinality is bounded by the number of
 * indexers on the install.
 */
class IndexerProfiler
{
    private const PREFIX = 'INDEXER';

    /**
     * @var Guard
     */
    private $guard;

    /**
     * @var Timer
     */
    private $timer;

    /**
     * @var TimerId
     */
    private $timerId;

    /**
     * @param Guard $guard
     * @param Timer $timer
     * @param TimerId $timerId
     */
    public function __construct(Guard $guard, Timer $timer, TimerId $timerId)
    {
        $this->guard   = $guard;
        $this->timer   = $timer;
        $this->timerId = $timerId;
    }

    /**
     * @param IndexerInterface $subject
     * @param callable $proceed
     * @return mixed
     */
    public function aroundReindexAll(IndexerInterface $subject, callable $proceed)
    {
        return $this->run($subject, 'reindexAll', $proceed);
    }

    /**
     * @param IndexerInterface $subject
     * @param callable $proceed
     * @param int $id
     * @return mixed
     */
    public function aroundReindexRow(IndexerInterface $subject, callable $proceed, $id)
    {
        return $this->run($subject, 'reindexRow', static function () use ($proceed, $id) {
            return $proceed($id);
        });
    }

    /**
     * @param IndexerInterface $subject
     * @param callable $proceed
     * @param int[] $ids
     * @return mixed
     */
    public function aroundReindexList(IndexerInterface $subject, callable $proceed, $ids)
    {
        return $this->run($subject, 'reindexList', static function () use ($proceed, $ids) {
            return $proceed($ids);
        });
    }

    /**
     * @param IndexerInterface $subject
     * @param string $operation
     * @param callable $callback
     * @return mixed
     */
    private function run(IndexerInterface $subject, string $operation, callable $callback)
    {
        if (!$this->guard->isActive(Settings::AREA_INDEXER)) {
            return $callback();
        }

        $name = (string)$subject->getId();
        if ($name === '') {
            $name = $this->timerId->shortClass(get_class($subject));
        }

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, $name . '::' . $operation),
            $callback
        );
    }
}
