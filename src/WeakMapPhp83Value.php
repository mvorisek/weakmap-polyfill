<?php

declare(strict_types=1);

namespace Brick\WeakmapPolyfill;

if (\PHP_VERSION_ID < 8_03_00) {
    /**
     * @internal
     *
     * @template TKey of object
     * @template TValue of mixed
     */
    class WeakMapPhp83Value
    {
        /** @var \WeakReference<\WeakMap<TKey, \WeakReference<self<TKey, TValue>>>> */
        private \WeakReference $weakMap;
        /** @var \WeakReference<\stdClass> */
        private \WeakReference $weakMapDestructedEarly;

        /** @var \WeakReference<TKey> */
        private \WeakReference $weakKey;
        /** @var TValue */
        private $value;

        /**
         * @param \WeakMap<TKey, \WeakReference<self<TKey, TValue>>> $weakMap
         * @param \stdClass                                          $destructedEarly
         * @param TKey                                               $key
         * @param TValue                                             $value
         */
        public function __construct(\WeakMap $weakMap, \stdClass $destructedEarly, object $key, $value)
        {
            $this->weakMap = \WeakReference::create($weakMap);
            $this->weakMapDestructedEarly = \WeakReference::create($destructedEarly);
            $this->weakKey = \WeakReference::create($key);
            $this->value = $value;
        }

        public function __destruct()
        {
            if ($this->weakMapDestructedEarly->get() === null) {
                return;
            }

            $weakMap = $this->weakMap->get();
            if ($weakMap === null) {
                return;
            }

            $key = $this->weakKey->get();
            if ($key !== null && $weakMap->offsetExists($key)) {
                $weakMap->offsetSet(
                    $key,
                    \WeakReference::create(
                        new self($weakMap, $this->weakMapDestructedEarly->get(), $key, $this->value)
                    )
                );
            }
        }

        /**
         * @return TValue
         */
        public function get()
        {
            return $this->value;
        }
    }
}
