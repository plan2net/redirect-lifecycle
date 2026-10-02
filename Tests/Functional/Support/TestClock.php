<?php

declare(strict_types=1);

namespace Plan2net\RedirectLifecycle\Tests\Functional\Support;

use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\DateTimeAspect;

trait TestClock
{
    /** Coordinate lifecycle calculations, native processing, and redirect matching. */
    private function setTime(string|int $time): void
    {
        // Explicit timezones retain their calendar-day semantics; unqualified dates use UTC.
        $date = new \DateTimeImmutable(is_int($time) ? '@' . $time : $time, new \DateTimeZone('UTC'));
        $this->get(Context::class)->setAspect('date', new DateTimeAspect($date));
        $GLOBALS['EXEC_TIME'] = $GLOBALS['SIM_ACCESS_TIME'] = $date->getTimestamp();
    }
}
