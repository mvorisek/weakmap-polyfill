<?php

declare(strict_types=1);

use WeakmapPolyfill\CycleWithDestructor;

if (PHP_MAJOR_VERSION === 7) {
    /**
     * A polyfill for the upcoming WeakMap implementation in PHP 8, based on WeakReference in PHP 7.4.
     * The polyfill aims to be 100% compatible with the native WeakMap implementation, feature-wise.
     *
     * The difference between the native PHP 8 implementation and the polyfill is when memory is reclaimed: with the
     * native WeakMap, the memory used by the data attached to an object is reclaimed as soon as the object is
     * destroyed. With the polyfill, the memory is reclaimed only when new operations are performed on the WeakMap:
     *
     * - count() will reclaim memory immediately
     * - offset*() methods will reclaim memory at least every 100 calls
     *   (for large WeakMaps, this will increase to be proportional to the size of the WeakMap)
     *
     * This is a reasonable trade-off between performance and memory usage, but keep in mind that the polyfill will
     * always be slower, and consume more memory, than the native implementation.
     *
     * @template TKey of object
     * @template TValue
     *
     * @implements ArrayAccess<TKey, TValue>
     * @implements IteratorAggregate<TKey, TValue>
     */
    final class WeakMap implements ArrayAccess, Countable, IteratorAggregate
    {
        /**
         * The minimum number of offset*() calls after which housekeeping will be performed.
         * Housekeeping consists in freeing memory associated with destroyed objects.
         */
        private const HOUSEKEEPING_EVERY = 100;

        /**
         * Only perform housekeeping when housekeepingCounter >= count(weakRefs) / HOUSEKEEPING_THRESHOLD.
         *
         * For example, for a WeakMap currently having 500 elements, this would housekeep after at least 50 elements,
         * and would instead wait for HOUSEKEEPING_EVERY(100).
         *
         * For a WeakMap with 100,000 elements, this would instead housekeep after every 10,000 operations.
         */
        private const HOUSEKEEPING_THRESHOLD = 10;

        /**
         * @var array<int, WeakReference<WeakMap<object, mixed>>>
         */
        private static array $housekeepingInstances = [];

        private static bool $destructorFxSetUp = false;

        /**
         * The number of offset*() calls since the last housekeeping.
         */
        private int $housekeepingCounter = 0;

        /**
         * A map of spl_object_id to WeakReference objects. This must be kept in sync with $values.
         *
         * @var array<int, WeakReference<TKey>>
         */
        private array $weakRefs = [];

        /**
         * A map of spl_object_id to values. This must be kept in sync with $weakRefs.
         *
         * @var array<int, TValue>
         */
        private array $values = [];

        public function __construct()
        {
            $this->setupHousekeepingOnGcRun();
        }

        public function __destruct()
        {
            unset(self::$housekeepingInstances[spl_object_id($this)]);
        }

        /**
         * @param TKey $object
         */
        public function offsetExists($object) : bool
        {
            $this->housekeeping();
            $this->assertValidKey($object);

            $id = spl_object_id($object);

            if (isset($this->weakRefs[$id])) {
                if ($this->weakRefs[$id]->get() !== null) {
                    return isset($this->values[$id]);
                }

                // This entry belongs to a destroyed object.
                unset(
                    $this->weakRefs[$id],
                    $this->values[$id]
                );
            }

            return false;
        }

        /**
         * @param TKey $object
         *
         * @return TValue
         */
        public function offsetGet($object)
        {
            $this->housekeeping();
            $this->assertValidKey($object, true);

            $id = spl_object_id($object);

            if (isset($this->weakRefs[$id])) {
                if ($this->weakRefs[$id]->get() !== null) {
                    return $this->values[$id];
                }

                // This entry belongs to a destroyed object.
                unset(
                    $this->weakRefs[$id],
                    $this->values[$id]
                );
            }

            throw new Error(sprintf('Object %s#%d not contained in WeakMap', get_class($object), $id));
        }

        /**
         * @param TKey $object
         * @param TValue $value
         */
        public function offsetSet($object, $value) : void
        {
            $this->housekeeping();
            $this->assertValidKey($object, true);

            $id = spl_object_id($object);

            $this->weakRefs[$id] = WeakReference::create($object);
            $this->values[$id]   = $value;
        }

        /**
         * @param TKey $object
         */
        public function offsetUnset($object) : void
        {
            $this->housekeeping();
            $this->assertValidKey($object);

            $id = spl_object_id($object);

            unset(
                $this->weakRefs[$id],
                $this->values[$id]
            );
        }

        public function count() : int
        {
            $this->housekeeping(true);

            return count($this->weakRefs);
        }

        public function getIterator() : Traversable
        {
            foreach ($this->weakRefs as $id => $weakRef) {
                $object = $weakRef->get();

                if ($object !== null) {
                    yield $object => $this->values[$id];
                } else {
                    // This entry belongs to a destroyed object.
                    unset(
                        $this->weakRefs[$id],
                        $this->values[$id]
                    );
                }
            }

            $this->housekeepingCounter = 0;
        }

        /**
         * NOTE: The native WeakMap does not implement this method,
         * but does throw Error for setting dynamic properties.
         *
         * @param mixed $value
         */
        public function __set(string $name, $value): void {
            throw new Error("Cannot create dynamic property WeakMap::\$$name");
        }

        // NOTE: The native WeakMap does not implement this method,
        // but does forbid serialization.
        public function __serialize(): array {
            throw new Exception("Serialization of 'WeakMap' is not allowed");
        }

        private function housekeeping(bool $force = false) : void
        {
            if (
                $force
                || (
                    ++$this->housekeepingCounter >= self::HOUSEKEEPING_EVERY
                    && $this->housekeepingCounter * self::HOUSEKEEPING_THRESHOLD >= count($this->weakRefs)
                )
            ) {
                foreach ($this->weakRefs as $id => $weakRef) {
                    if ($weakRef->get() === null) {
                        unset(
                            $this->weakRefs[$id],
                            $this->values[$id]
                        );
                    }
                }

                $this->housekeepingCounter = 0;
            }
        }

        /**
         * @param mixed $key
         */
        private function assertValidKey($key, bool $checkNull = false) : void
        {
            if ($checkNull && $key === null) {
                throw new Error('Cannot append to WeakMap');
            }

            if(!is_object($key)) {
                throw new TypeError('WeakMap key must be an object');
            }
        }

        /**
         * @see Based on https://github.com/php/php-src/pull/13650 PHP GC behaviour.
         */
        private function setupHousekeepingOnGcRun() : void
        {
            if (!self::$destructorFxSetUp) {
                self::$destructorFxSetUp = true;

                $gcRuns = 0;
                $setupDestructorFx = static function () use (&$gcRuns, &$setupDestructorFx): void {
                    $destructorFx = static function () use (&$gcRuns, &$setupDestructorFx): void {
                        $gcRunsPrev = $gcRuns;
                        $gcRuns = gc_status()['runs'];
                        if ($gcRunsPrev !== $gcRuns) { // prevent recursion on shutdown
                            $setupDestructorFx();
                        }

                        foreach (self::$housekeepingInstances as $v) {
                            $map = $v->get();
                            if ($map !== null) {
                                $map->housekeeping(true);
                            }
                        }
                    };

                    new CycleWithDestructor($destructorFx);
                };
                $setupDestructorFx();
            }

            self::$housekeepingInstances[spl_object_id($this)] = WeakReference::create($this);
        }
    }
}
