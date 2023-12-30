<?php

    declare (strict_types=1);

    use quarxConnect\Events\Emitter;
    use quarxConnect\Events\Promise;
    use quarxConnect\Events\ABI\Event;
    use quarxConnect\Events\Test\TestCase;

    class EventEmitterTest extends TestCase {
        private Emitter|null $theEmitter = null;
        private object|null $theEvent = null;

        // {{{ setUp
        /**
         * Prepare data for the tests
         *
         * @access public
         * @return void
         **/
        public function setUp (): void {
            parent::setUp ();

            $this->theEmitter = new Emitter ();
            $this->theEvent = new class () implements Event { };
        }
        // }}}

        // {{{ testEventRegistration
        /**
         * Check if we can register callbacks for our event
         *
         * @access public
         * @return void
         **/
        public function testEventRegistration (): void {
            $this->assertCount (
                0,
                $this->theEmitter->getListenersForEvent ($this->theEvent),
                'There are no registered event-listeners for our event'
            );

            $this->theEmitter->addEventListener (
                $this->theEvent::class,
                fn () => true
            );

            $this->assertCount (
                1,
                $this->theEmitter->getListenersForEvent ($this->theEvent),
                'There is one registered event-listeners for our event'
            );
        }
        // }}}

        // {{{ testEventDispatch
        /**
         * Try to just dispatch and catch an event
         *
         * @access public
         * @return Promise
         **/
        public function testEventDispatch (): Promise {
            $eventCaught = false;

            $this->theEmitter->addEventListener (
                $this->theEvent::class,
                function () use (&$eventCaught): void {
                    $eventCaught = true;
                }
            );

            $this->assertAsynchronousResult (
                function () use (&$eventCaught): void {
                    $this->assertTrue (
                        $eventCaught,
                        'Event was caught'
                    );
                }
            );

            return $this->theEmitter->dispatch ($this->theEvent);
        }
        // }}}

        // {{{ testEventDispatchOnce
        /**
         * Dispatch an event twice and only catch once
         *
         * @access public
         * @return Promise
         **/
        public function testEventDispatchOnce (): Promise {
            $eventsCaught = 0;
            $eventsCaughtOnce = 0;

            $this->theEmitter->addEventListener (
                $this->theEvent::class,
                function () use (&$eventsCaught): void {
                    $eventsCaught++;
                }
            );

            $this->theEmitter->addEventListener (
                $this->theEvent::class,
                function () use (&$eventsCaughtOnce): void {
                    $eventsCaughtOnce++;
                },
                true
            );

            $this->assertAsynchronousResult (
                function () use (&$eventsCaught, &$eventsCaughtOnce): void {
                    $this->assertEquals (
                        2,
                        $eventsCaught,
                        'Event was caught twice at all'
                    );

                    $this->assertEquals (
                        1,
                        $eventsCaughtOnce,
                        'Event was caught once where wanted only once'
                    );
                }
            );

            // Dispatch the event twice
            return $this->theEmitter->dispatch ($this->theEvent)->then (
                fn () => $this->theEmitter->dispatch ($this->theEvent)
            );
        }
        // }}}

        // {{{ testRemoveListener
        /**
         * Try to remove a listener between two events
         *
         * @access public
         * @return Promise
         */
        public function testRemoveListener (): Promise {
            $eventsCaught = 0;
            $eventsCaughtOnce = 0;

            $theListener = function () use (&$eventsCaughtOnce): void {
                $eventsCaughtOnce++;
            };

            $this->theEmitter->addEventListener (
                $this->theEvent::class,
                function () use (&$eventsCaught): void {
                    $eventsCaught++;
                }
            );

            $this->theEmitter->addEventListener (
                $this->theEvent::class,
                $theListener
            );

            $this->assertAsynchronousResult (
                function () use (&$eventsCaught, &$eventsCaughtOnce): void {
                    $this->assertEquals (
                        2,
                        $eventsCaught,
                        'Event was caught twice at all'
                    );

                    $this->assertEquals (
                        1,
                        $eventsCaughtOnce,
                        'Event was caught once before the listener was removed'
                    );
                }
            );

            // Dispatch the event twice
            return $this->theEmitter->dispatch ($this->theEvent)->then (
                function () use ($theListener): Promise {
                    $this->theEmitter->removeEventListener ($this->theEvent::class, $theListener);

                    return $this->theEmitter->dispatch ($this->theEvent);
                }
            );
        }
        // }}}

        // {{{ testEventPromise
        /**
         * Test to register a promise that fulfills once for our event
         *
         * @access public
         * @return Promise
         **/
        public function testEventPromise (): Promise {
            $this->assertAsynchronousResult (true);

            $thePromise = $this->theEmitter->addEventPromise ($this->theEvent::class)->then (
                fn () => true
            );

            $this->theEmitter->dispatch ($this->theEvent);

            return $thePromise;
        }
        // }}}
    }
