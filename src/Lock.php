<?php

namespace Lysice\HyperfRedisLock;

use Hyperf\Utils\Str;
use Hyperf\Utils\InteractsWithTime;

abstract class Lock implements LockContract
{
    use InteractsWithTime;

    /**
     * The name of the lock
     * @var string
     */
    protected $name;

    /**
     * @var int
     */
    protected $seconds;

    /**
     * The scope identifier of this lock
     * @var string
     */
    protected $owner;

    public function __construct($name, $seconds, $owner = null)
    {
        if(is_null($owner)) {
            $owner = Str::random();
        }
        $this->name = $name;
        $this->seconds = $seconds;
        $this->owner = $owner;
    }

    /**
     * Attempt to acquire the lock
     * @return bool
     */
    abstract public function acquire();

    /**
     * Release the lock
     * @return void
     */
    abstract public function release();

    /**
     * Returns the owner value written into the driver for this lock
     * @return string
     */
    abstract protected function getCurrentOwner();

    /**
     * Attempt to acquire the lock
     * @param null|\Closure $callback
     * @param null|\Closure $finally
     * @return bool|mixed
     */
    public function get($callback = null, $finally = null)
    {
        $result = $this->acquire();
        if($result && is_callable($callback)) {
            try {
                return $callback();
            } finally {
                $this->release();
            }
        }
        if (!$result && is_callable($finally)) {
            return $finally();
        }

        return $result;
    }

    /**
     * @param $seconds
     * @param callable | null $callback
     * @param int $gapMs call gap millisecond
     * @return bool|mixed
     * @throws LockTimeoutException
     */
    public function block($seconds, $callback = null, $gapMs = 0)
    {
        $start = microtime(true);
        $starting = $this->currentTime();
        while (!$this->acquire()) {
            $sleepMs = 250;
            logger()->info(sprintf(__METHOD__ . ' not get lock:%s sleep %dms', $this->name, $sleepMs));
            usleep($sleepMs * 1000);
            if ($this->currentTime() - $seconds >= $starting) {
                throw new LockTimeoutException();
            }
        }

        if(is_callable($callback)) {
            try {
                $res = $callback();
                $end = microtime(true);
                $leftMs = $gapMs - intval(($end - $start) * 1000);
                if($gapMs > 0 && $leftMs > 0) {
                    logger()->info(sprintf(__METHOD__ . ' exec too fast lock:%s need sleep %dms', $this->name, $leftMs));
                    usleep($leftMs * 1000);
                }
                return $res;
            } finally {
                $this->release();
            }
        }

        return true;
    }

    /**
     * Returns the current owner of the lock.
     *
     * @return string
     */
    public function owner()
    {
        return $this->owner;
    }

    /**
     * Determines whether this lock is allowed to release the lock in the driver.
     *
     * @return bool
     */
    protected function isOwnedByCurrentProcess()
    {
        return $this->getCurrentOwner() === $this->owner;
    }
}
