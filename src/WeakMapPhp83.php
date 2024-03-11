<?php

declare(strict_types=1);

namespace Brick\WeakmapPolyfill;

if (\PHP_VERSION_ID >= 8_03_00) {
    class_alias(\WeakMap::class, WeakMapPhp83::class);
} else {
    /**
     * \WeakMap with https://github.com/php/php-src/issues/10043 fixed for PHP 8.2 and below.
     *
     * @template TKey of object
     * @template TValue of mixed
     */
    final class WeakMapPhp83 implements \ArrayAccess, \Countable, \IteratorAggregate
    {
        /** Workaround https://github.com/php/php-src/issues/13612. */
        private \stdClass $destructedEarly;

        /** @var \WeakMap<TKey, \WeakReference<WeakMapPhp83Value<TKey, TValue>>> */
        private \WeakMap $weakMap;

        public function __construct()
        {
            $this->weakMap = new \WeakMap();

            $this->destructedEarly = new \stdClass();
        }

        /**
         * @param TKey $object
         */
        public function offsetExists($object) : bool
        {
            $this->assertValidKey($object);

            return $this->weakMap->offsetExists($object)
                && $this->offsetGet($object) !== null;
        }

        /**
         * @param TKey $object
         *
         * @return TValue
         */
        #[\ReturnTypeWillChange]
        public function offsetGet($object)
        {
            $this->assertValidKey($object);

            return $this->weakMap->offsetGet($object)->get()->get();
        }

        /**
         * @param TKey $object
         * @param TValue $value
         */
        public function offsetSet($object, $value) : void
        {
            $this->assertValidKey($object);

            $valueBefore = null;
            if ($this->weakMap->offsetExists($object)) {
                $valueBefore = $this->weakMap->offsetGet($object)->get();
            }

            $this->weakMap->offsetSet(
                $object,
                \WeakReference::create(
                    new WeakMapPhp83Value($this->weakMap, $this->destructedEarly, $object, $value)
                )
            );

            if ($valueBefore !== null) {
                $valueBefore->destroy();
            }
        }

        /**
         * @param TKey $object
         */
        public function offsetUnset($object) : void
        {
            $this->assertValidKey($object);

            $valueBefore = null;
            if ($this->weakMap->offsetExists($object)) {
                $valueBefore = $this->weakMap->offsetGet($object)->get();
            }

            $this->weakMap->offsetUnset($object);

            if ($valueBefore !== null) {
                $valueBefore->destroy();
            }
        }

        public function count() : int
        {
            return $this->weakMap->count();
        }

        /**
         * @return \Traversable<TKey, TValue>
         */
        public function getIterator() : \Traversable
        {
            foreach ($this->weakMap->getIterator() as $object => $v) {
                yield $object => $this->offsetGet($object);
            }
        }

        // NOTE: The native WeakMap does not implement this method,
        // but does throw Error for setting dynamic properties.
        public function __set($name, $value): void {
            throw new \Error("Cannot create dynamic property WeakMap::\$$name");
        }

        // NOTE: The native WeakMap does not implement this method,
        // but does forbid serialization.
        public function __serialize(): array {
            throw new \Exception("Serialization of 'WeakMap' is not allowed");
        }

        private function assertValidKey($key) : void
        {
            if ($key === null) {
                throw new \Error('Cannot append to WeakMap');
            }

            if (!is_object($key)) {
                throw new \TypeError('WeakMap key must be an object');
            }
        }
    }
}
