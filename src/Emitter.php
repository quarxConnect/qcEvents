<?php

    /**
     * quarxConnect Events - Generic Event-Emitter
     * Copyright (C) 2014-2023 Bernd Holzmueller <bernd@innorize.gmbh>
     * 
     * This program is free software: you can redistribute it and/or modify
     * it under the terms of the GNU General Public License as published by
     * the Free Software Foundation, either version 3 of the License, or
     * (at your option) any later version.
     * 
     * This program is distributed in the hope that it will be useful,
     * but WITHOUT ANY WARRANTY; without even the implied warranty of
     * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
     * GNU General Public License for more details.
     * 
     * You should have received a copy of the GNU General Public License
     * along with this program.  If not, see <http://www.gnu.org/licenses/>.
     **/
    
    declare (strict_types=1);

    namespace quarxConnect\Events;

    use quarxConnect\Events\ABI\Event;
    use quarxConnect\Events\ABI\Event\Stoppable as StoppableEvent;

    use Psr\EventDispatcher\EventDispatcherInterface;
    use Psr\EventDispatcher\ListenerProviderInterface;

    class Emitter implements EventDispatcherInterface, ListenerProviderInterface {
        /**
         * List of registered event-listeners
         *
         * @var array
         **/
        private array $eventListeners = [];

        // {{{ getListenersForEvent
        /**
         * Retrieve a list of all listeners for a given Event
         * 
         * @param Event $theEvent An event for which to return the relevant listeners.
         * 
         * @access public
         * @return iterable<callable> An iterable (array, iterator, or generator) of callables. Each callable MUST be type-compatible with $event.
         **/
        public function getListenersForEvent (Event $theEvent): iterable {
            $allListeners = [];

            foreach ($this->eventListeners as $eventClass=>$eventListeners)
                if ($theEvent instanceof $eventClass)
                    $allListeners [$eventClass] = $eventListeners;
            
            uksort (
                $allListeners,
                fn (string $leftEventClass, string $rightEventClass): int => is_subclass_of ($leftEventClass, $rightEventClass) ? 1 : -1
            );

            return call_user_func_array ('array_merge', $allListeners);
        }
        // }}}

        // {{{ addEventListener
        /**
         * Add a listener for a given event-class
         * 
         * @param string $eventClass Class-Name of the event to listen to
         * @param callable $eventListener A callable to forward the event to
         * @param bool $listenOnce (optional) Only listen once to the given event
         * @param bool $preventClassCheck (optional) Don't check if the given event-class is a valid event
         * 
         * @access public
         * @return void
         * 
         * @throws Exception\InvalidClass
         **/
        public function addEventListener (string $eventClass, callable $eventListener, bool $listenOnce = false, bool $preventClassCheck = false): void {
            // Check if this is a valid event-class
            if (
                !$preventClassCheck &&
                !is_a ($eventClass, Event::class, true)
            )
                throw new Exception\InvalidClass ('Event-Class must implement ' . Event::class);
            
            // Push to our list of listeners
            if (!isset ($this->eventListeners [$eventClass]))
                $this->eventListeners [$eventClass] = [];
            
            $this->eventListeners [$eventClass][] = new class ($this, $eventClass, $eventListener, $listenOnce) {
                /**
                 * The emitter for this event-listener
                 *
                 * @var Emitter
                 **/
                private Emitter $myEmitter;

                /**
                 * The event-class this listener listens for
                 *
                 * @var string
                 **/
                private string $eventClass;

                /**
                 * The callable to run upon event
                 *
                 * @var callable
                 **/
                private $eventListener;

                /**
                 * Listener-Callable shall be only executed once
                 *
                 * @var boolean
                 **/
                private bool $listenOnce;

                // {{{ __construct
                /**
                 * Create a new anonymous instance for an event-listener
                 *
                 * @param Emitter $theEmitter
                 * @param string $eventClass
                 * @param callable $eventListener
                 * @param boolean $listenOnce
                 * 
                 * @access public
                 * @return void
                 **/
                public function __construct (Emitter $theEmitter, string $eventClass, callable $eventListener, bool $listenOnce) {
                    $this->myEmitter = $theEmitter;
                    $this->eventClass = $eventClass;
                    $this->eventListener = $eventListener;
                    $this->listenOnce = $listenOnce;
                }
                // }}}

                // {{{ __invoke
                /**
                 * Run the listener-callback
                 *
                 * @param Event $theEvent
                 * 
                 * @access public
                 * @return Promise|null
                 **/
                public function __invoke (Event $theEvent): ?Promise {
                    // Try to run the listener-callback
                    try {
                        $isException = false;
                        $listenerResult = call_user_func ($this->eventListener, $theEvent);
                    } catch (Throwable $listenerException) {
                        $isException = true;
                        $listenerResult = $listenerException;
                    }

                    // Remove the listener if it shall be executed only once
                    if ($this->listenOnce)
                        $this->myEmitter->removeEventListener ($this->eventClass, $this);
                    
                    // Forward/Throw the result
                    if ($isException)
                        throw $listenerResult;
                    
                    return ($listenerResult instanceof Promise ? $listenerResult : null);
                }
                // }}}

                // {{{ getCallable
                /**
                 * Return the callable from this listener
                 *
                 * @access public
                 * @return callable
                 **/
                public function getCallable (): callable {
                    return $this->eventListener;
                }
                // }}}
            };
        }
        // }}}

        // {{{ removeEventListener
        /**
         * Remove a listener for a given event-class
         *
         * @param string $eventClass
         * @param callable|Listener $eventListener
         * 
         * @access public
         * @return void
         **/
        public function removeEventListener (string $eventClass, $eventListener): void {
            if (!isset ($this->eventListeners [$eventClass]))
                return;
            
            foreach ($this->eventListeners [$eventClass] as $listenerIndex=>$theListener)
                if (
                    ($theListener === $eventListener) ||
                    ($theListener->getCallable () === $eventListener)
                ) {
                    unset ($the->eventListeners [$eventClass][$listenerIndex]);

                    if ($theListener === $eventListener)
                        return;
                }
        }
        // }}}

        // {{{ dispatch
        /**
         * Provide all relevant listeners with an event to process.
         *
         * @param Event $theEvent The object to process.
         *
         * @access public
         * @return Promise Resolves to the Event that was passed, now modified by listeners.
         **/
        public function dispatch (Event $theEvent): Promise {
            return Promise::walk (
                $this->getListenersForEvent ($theEvent),
                function (callable $listenerCallback) use ($theEvent): ?Promise {
                    // Check if event-propagation was stopped
                    if (
                        ($theEvent instanceof StoppableEvent) &&
                        $theEvent->isPropagationStopped ()
                    )
                        return null;
                    
                    // Invoke the listener
                    $listenerResult = call_user_func ($listenerCallback, $theEvent);

                    // Return promise or null
                    return ($listenerResult instanceof Promise ? $listenerResult : null);
                }
            )->then (
                fn (): Event => $theEvent
            );
        }
        // }}}
    }
