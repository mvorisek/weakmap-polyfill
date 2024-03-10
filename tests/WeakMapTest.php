<?php

declare(strict_types=1);

use Brick\WeakmapPolyfill\WeakMapPhp83;
use PHPUnit\Framework\TestCase;

class WeakMapTest extends TestCase
{
    /**
     * @return \WeakMap<object, mixed>|WeakMapPhp83<object, mixed>
     */
    protected function createWeakMap() : object
    {
        return new WeakMap();
    }

    public function testArrayAccess() : void
    {
        $weakMap = $this->createWeakMap();

        $a = new stdClass;
        $b = new stdClass;

        $weakMap[$a] = 123;
        self::assertTrue(isset($weakMap[$a]));
        self::assertSame(123, $weakMap[$a]);

        $weakMap[$b] = 456;
        self::assertTrue(isset($weakMap[$b]));
        self::assertSame(456, $weakMap[$b]);

        unset($weakMap[$a]);
        self::assertFalse(isset($weakMap[$a]));
        self::assertTrue(isset($weakMap[$b]));

        unset($weakMap[$b]);
        self::assertFalse(isset($weakMap[$a]));
        self::assertFalse(isset($weakMap[$b]));
    }

    public function testArrayAccessWithNull() : void
    {
        $weakMap = $this->createWeakMap();

        $a = new stdClass;

        $weakMap[$a] = null;
        self::assertFalse(isset($weakMap[$a]));
        self::assertNull($weakMap[$a]);
    }

    public function testReusedObjectId() : void
    {
        $weakMap = $this->createWeakMap();

        $a = new stdClass;
        $b = new stdClass;

        $weakMap[$a] = 123;
        $weakMap[$b] = 456;

        self::assertTrue(isset($weakMap[$a]));
        self::assertTrue(isset($weakMap[$b]));

        unset($a, $b);

        // typically reusing the same spl_object_id as the old ones
        $a = new stdClass;
        $b = new stdClass;

        self::assertFalse(isset($weakMap[$a]));
        self::assertFalse(isset($weakMap[$b]));
    }

    public function testAccessingUnknownObjectThrowsError() : void
    {
        $weakMap = $this->createWeakMap();

        $a = new stdClass;

        self::expectException(Error::class);
        $weakMap[$a];
    }

    public function testAccessingObjectWithReusedIdThrowsError() : void
    {
        $weakMap = $this->createWeakMap();

        $k = new stdClass;
        $v = new stdClass;

        $weakMap[$k] = $v;

        unset($k);
        unset($v);

        $a = new stdClass;

        self::expectException(Error::class);
        $weakMap[$a];
    }

    public function testCount() : void
    {
        $weakMap = $this->createWeakMap();

        $a = new stdClass;
        $b = new stdClass;

        self::assertSame(0, $weakMap->count());

        $weakMap[$a] = 123;
        self::assertSame(1, $weakMap->count());

        $weakMap[$b] = 456;
        self::assertSame(2, $weakMap->count());

        unset($a); // implicit (WeakReference gone)
        self::assertSame(1, $weakMap->count());

        unset($weakMap[$b]); // explicit (ArrayAccess)
        self::assertSame(0, $weakMap->count());
    }

    public function testDataIsDestroyedWhenObjectIsRemoved() : void
    {
        $weakMap = $this->createWeakMap();

        $k = new stdClass;
        $v = new stdClass;
        $r = WeakReference::create($v);

        self::assertSame(0, $weakMap->count());

        $weakMap[$k] = $v;

        // Remove our reference to $v, which is now only used as a value in the WeakMap.
        unset($v);

        self::assertSame(1, $weakMap->count());
        self::assertNotNull($r->get());

        // Removing the object key $k should force the WeakMap to clean up associated data $v;
        // the WeakReference to $v should therefore not point anywhere anymore.
        unset($weakMap[$k]);

        self::assertSame(0, $weakMap->count());
        self::assertNull($r->get());
    }

    public function testDataIsDestroyedWhenObjectIsGarbageCollected() : void
    {
        $weakMap = $this->createWeakMap();

        $k = new stdClass;
        $v = new stdClass;
        $r = WeakReference::create($v);

        self::assertSame(0, $weakMap->count());

        $weakMap[$k] = $v;

        // Remove our reference to $v, which is now only used as a value in the WeakMap.
        unset($v);

        self::assertSame(1, $weakMap->count());
        self::assertNotNull($r->get());

        // Garbage-collecting the object key $k should force the WeakMap to clean up associated data $v;
        // the WeakReference to $v should therefore not point anywhere anymore.
        unset($k);

        self::assertSame(0, $weakMap->count());
        self::assertNull($r->get());
    }

    public function testTraversable() : void
    {
        $weakMap = $this->createWeakMap();

        $a = new stdClass;
        $b = new stdClass;
        $c = new stdClass;

        $weakMap[$a] = 1;
        $weakMap[$b] = 2;
        $weakMap[$c] = 3;

        self::assertSame([[$a, 1], [$b, 2], [$c, 3]], $this->iteratorToKeyValuePairs($weakMap));

        unset($b);
        self::assertSame([[$a, 1], [$c, 3]], $this->iteratorToKeyValuePairs($weakMap));

        unset($a);
        self::assertSame([[$c, 3]], $this->iteratorToKeyValuePairs($weakMap));

        unset($c);
        self::assertSame([], $this->iteratorToKeyValuePairs($weakMap));
    }

    public function testHousekeeping() : void
    {
        $weakMap = $this->createWeakMap();

        $k = new stdClass;
        $v = new stdClass;
        $r = WeakReference::create($v);

        $unknownObject = new stdClass;

        $weakMap[$k] = $v;

        unset($k);
        unset($v);

        if (\PHP_MAJOR_VERSION < 8) {
            for ($i = 0; $i < 99; $i++) {
                self::assertNotNull($r->get());
                isset($weakMap[$unknownObject]);
            }
        }

        self::assertNull($r->get());
    }

    public function testHousekeepingOnGcRun(?WeakMap $weakMap = null) : void
    {
        if ($weakMap === null) {
            $weakMap = $this->createWeakMap();
        }

        $k = new stdClass;
        $v = new stdClass;
        $r = WeakReference::create($v);

        $weakMap[$k] = $v;

        unset($k);
        unset($v);

        if (\PHP_MAJOR_VERSION < 8) {
            self::assertNotNull($r->get());
            gc_collect_cycles();
        }

        self::assertNull($r->get());
    }

    public function testNoInternalCycle() : void
    {
        $weakMap = $this->createWeakMap();
        $rWeakMap = WeakReference::create($weakMap);

        $k = new stdClass;
        $v = new stdClass;
        $rK = WeakReference::create($k);
        $rV = WeakReference::create($v);

        $weakMap[$k] = $v;

        unset($weakMap);
        unset($k);
        unset($v);

        self::assertNull($rWeakMap->get());
        self::assertNull($rK->get());
        self::assertNull($rV->get());
    }

    public function testHousekeepingOnGcRunSurvival() : void
    {
        $weakMap = $this->createWeakMap();

        $vkPairs = [];
        for ($i = 100; $i > 0; $i--) {
            for ($j = 100; $j > 0; $j--) {
                $k = new stdClass;
                $v = new stdClass;
                $weakMap[$k] = $v;
                $vkPairs[] = [$k, $v];
            }

            gc_collect_cycles();
            gc_collect_cycles();
            gc_collect_cycles();
        }

        foreach ($vkPairs as [$k, $v]) {
            if ($weakMap[$k] !== $v) {
                self::assertSame($v, $weakMap[$k]);
            }
        }
        self::assertSame(count($vkPairs), count($weakMap));

        $this->testHousekeepingOnGcRun($weakMap);
    }

    public function testKeyMustBeObjectToSet() : void
    {
        $weakMap = $this->createWeakMap();

        self::expectException(TypeError::class);
        self::expectExceptionMessage('WeakMap key must be an object');
        $weakMap[1] = 2;
    }

    public function testKeyMustBeObjectToGet() : void
    {
        $weakMap = $this->createWeakMap();

        self::expectException(TypeError::class);
        self::expectExceptionMessage('WeakMap key must be an object');
        $weakMap[1];
    }

    public function testKeyMustBeObjectToIsset() : void
    {
        $weakMap = $this->createWeakMap();

        self::expectException(TypeError::class);
        self::expectExceptionMessage('WeakMap key must be an object');
        isset($weakMap[1]);
    }

    public function testKeyMustBeObjectToUnset() : void
    {
        $weakMap = $this->createWeakMap();

        self::expectException(TypeError::class);
        self::expectExceptionMessage('WeakMap key must be an object');
        unset($weakMap[1]);
    }

    public function testCantAppend() : void
    {
        $weakMap = $this->createWeakMap();

        self::expectException(Error::class);
        self::expectExceptionMessage('Cannot append to WeakMap');
        $weakMap[] = 1;
    }

    public function testCantDeepAppend() : void
    {
        $weakMap = $this->createWeakMap();

        self::expectException(Error::class);
        self::expectExceptionMessage('Cannot append to WeakMap');
        $weakMap[][1] = 1;
    }

    public function testCantSetDynamicProperty() : void
    {
        $weakMap = $this->createWeakMap();
        self::expectException(Error::class);
        self::expectExceptionMessage('Cannot create dynamic property WeakMap::$abc');
        $weakMap->abc = 123;
    }

    public function testCantSerialize() : void
    {
        $weakMap = $this->createWeakMap();
        self::expectException(Exception::class);
        self::expectExceptionMessage("Serialization of 'WeakMap' is not allowed");
        serialize($weakMap);
    }

    /**
     * Similar to iterator_to_array(), but returns the result as a list of key-value pairs.
     * We need this, as iterator_to_array() on a WeakMap would fail because keys are objects.
     */
    private function iteratorToKeyValuePairs(iterable $iterator) : array
    {
        $result = [];

        foreach ($iterator as $key => $value) {
            $result[] = [$key, $value];
        }

        return $result;
    }
}
