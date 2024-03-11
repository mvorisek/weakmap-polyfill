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

        private ?\stdClass $cycleRef;

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

            // make self cyclically referenced to survive GC run, inspired by CycleWithDestructor
            $this->cycleRef = new \stdClass();
            $this->cycleRef->x = $this;
        }

        public function __destruct()
        {
            if ($this->weakMapDestructedEarly->get() === null) {
                $this->destroy();

                return;
            }

            $weakMap = $this->weakMap->get();
            if ($weakMap === null) {
                $this->destroy();

                return;
            }

            $key = $this->weakKey->get();
            if ($key !== null && $weakMap->offsetExists($key)) {
                // set new value wrapper, as the previous one (=$this) will be released after GC run
                $weakMap->offsetSet(
                    $key,
                    \WeakReference::create(
                        new self($weakMap, $this->weakMapDestructedEarly->get(), $key, $this->value)
                    )
                );
            } else {
                $this->destroy();
            }
        }

        /**
         * @return TValue
         */
        public function get()
        {
            return $this->value;
        }

        public function destroy() : void
        {
            $this->value = null;
            $this->cycleRef = null;
        }
    }
}
