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

namespace MagePsycho\Profiler\Plugin\Image;

use Magento\Framework\Image\Adapter\AdapterInterface;
use MagePsycho\Profiler\Model\Instrumentation\Guard;
use MagePsycho\Profiler\Model\Instrumentation\Settings;
use MagePsycho\Profiler\Model\Instrumentation\Timer;
use MagePsycho\Profiler\Model\Instrumentation\TimerId;

/**
 * Times image manipulation: "IMAGE:resize (Gd2)", "IMAGE:save (Gd2)".
 *
 * The first uncached view of a category page generates every thumbnail it shows, and GD or ImageMagick
 * work is CPU spent inside the request with nothing else to show for it - no query, no cache call, no
 * network. In a profile it currently hides inside the block that triggered it.
 *
 * The detail is the adapter (Gd2, ImageMagick), never the file path: paths are per-product, and a row
 * per product is both useless and a way to write catalog data into a log.
 */
class AdapterProfiler
{
    private const PREFIX = 'IMAGE';

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
     * @param AdapterInterface $subject
     * @param callable $proceed
     * @param string $filename
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundOpen(AdapterInterface $subject, callable $proceed, $filename)
    {
        return $this->measure($subject, 'open', static function () use ($proceed, $filename) {
            return $proceed($filename);
        });
    }

    /**
     * @param AdapterInterface $subject
     * @param callable $proceed
     * @param int|null $frameWidth
     * @param int|null $frameHeight
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundResize(AdapterInterface $subject, callable $proceed, $frameWidth = null, $frameHeight = null)
    {
        return $this->measure($subject, 'resize', static function () use ($proceed, $frameWidth, $frameHeight) {
            return $proceed($frameWidth, $frameHeight);
        });
    }

    /**
     * @param AdapterInterface $subject
     * @param callable $proceed
     * @param string|null $destination
     * @param string|null $newName
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundSave(AdapterInterface $subject, callable $proceed, $destination = null, $newName = null)
    {
        return $this->measure($subject, 'save', static function () use ($proceed, $destination, $newName) {
            return $proceed($destination, $newName);
        });
    }

    /**
     * @param AdapterInterface $subject
     * @param callable $proceed
     * @param string $imagePath
     * @param int $positionX
     * @param int $positionY
     * @param int $opacity
     * @param bool $tile
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundWatermark(
        AdapterInterface $subject,
        callable $proceed,
        $imagePath,
        $positionX = 0,
        $positionY = 0,
        $opacity = 30,
        $tile = false
    ) {
        return $this->measure(
            $subject,
            'watermark',
            static function () use ($proceed, $imagePath, $positionX, $positionY, $opacity, $tile) {
                return $proceed($imagePath, $positionX, $positionY, $opacity, $tile);
            }
        );
    }

    /**
     * @param AdapterInterface $subject
     * @param callable $proceed
     * @param int $top
     * @param int $left
     * @param int $right
     * @param int $bottom
     * @return mixed
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundCrop(
        AdapterInterface $subject,
        callable $proceed,
        $top = 0,
        $left = 0,
        $right = 0,
        $bottom = 0
    ) {
        return $this->measure($subject, 'crop', static function () use ($proceed, $top, $left, $right, $bottom) {
            return $proceed($top, $left, $right, $bottom);
        });
    }

    /**
     * @param AdapterInterface $subject
     * @param string $operation
     * @param callable $callback
     * @return mixed
     */
    private function measure(AdapterInterface $subject, string $operation, callable $callback)
    {
        if (!$this->guard->isActive(Settings::AREA_IMAGE)) {
            return $callback();
        }

        return $this->timer->measure(
            $this->timerId->build(self::PREFIX, $operation, $this->timerId->shortClass(get_class($subject), 1)),
            $callback
        );
    }
}
