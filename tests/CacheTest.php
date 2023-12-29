<?php

    declare (strict_types=1);

    use quarxConnect\Events\Cache;
    use quarxConnect\Events\Promise;
    use quarxConnect\Events\Test\TestCase;

    use quarxConnect\Events\Cache\Exception\NotFound;

    class CacheTest extends TestCase {
        public function testHave (): void {
            $theCache = new Cache ($this->getEventBase ());

            $this->assertFalse (
                $theCache->have ('testKey'),
                'Key is not present on an empty cache'
            );

            $theCache->set (
                'testKey',
                'someValue'
            );

            $this->assertTrue (
                $theCache->have ('testKey'),
                'Key is present on cache'
            );

            $this->assertFalse (
                $theCache->have ('otherKey'),
                'Key is not present on cache'
            );
        }

        public function testGet (): void {
            $theCache = new Cache ($this->getEventBase ());

            $theCache->set (
                'testKey',
                'testValue'
            );

            $this->assertEquals (
                'testValue',
                $theCache->get ('testKey'),
                'Cached value is as expected'
            );
        }

        public function testInvalidGet (): void {
            $theCache = new Cache ($this->getEventBase ());

            $this->expectException (NotFound::class);

            try {
                $theCache->get ('missingKey');
            } catch (NotFound $cacheException) {
                $this->assertEquals (
                    'missingKey',
                    $cacheException->lookupKey,
                    'Lookup-Key on exception is the one we tried to look up'
                );

                throw $cacheException;
            }
        }

        public function testSet (): void {
            $theCache = new Cache ($this->getEventBase ());

            $theCache->set (
                'testKey',
                'testValue'
            );

            $this->assertEquals (
                'testValue',
                $theCache->get ('testKey'),
                'Initial value is as expected'
            );

            $theCache->set (
                'testKey',
                'nextValue'
            );

            $this->assertEquals (
                'nextValue',
                $theCache->get ('testKey'),
                'Updated value is as expected'
            );
        }

        public function testUnset (): void {
            $theCache = new Cache ($this->getEventBase ());

            $theCache->set (
                'testKey',
                'someValue'
            );

            $theCache->set (
                'otherKey',
                'otherValue'
            );

            $theCache->unset ('testKey');

            $this->assertFalse (
                $theCache->have ('testKey'),
                'Test-Key was removed from cache'
            );

            $this->assertTrue (
                $theCache->have ('otherKey'),
                'Other key was not removed from cache'
            );
        }

        public function testPrune (): void {
            $theCache = new Cache ($this->getEventBase ());

            $theCache->set (
                'testKey',
                'someValue'
            );

            $theCache->set (
                'otherKey',
                'otherValue'
            );

            $theCache->prune ();

            $this->assertFalse (
                $theCache->have ('testKey'),
                'Test-Key was removed from cache'
            );

            $this->assertFalse (
                $theCache->have ('otherKey'),
                'Other key was also removed from cache'
            );
        }

        public function testLookup (): Promise {
            $theCache = new Cache (
                $this->getEventBase (),
                fn ($theKey) => $theKey . 'Value'
            );

            $this->assertAsynchronousResult (
                'testKeyValue'
            );

            return $theCache->lookup ('testKey');
        }
    }
