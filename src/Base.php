<?php

  /**
   * quarxConnect Events - Event-Loop
   * Copyright (C) 2014-2024 Bernd Holzmueller <bernd@quarxconnect.de>
   *
   * This program is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or
   * (at your option) any later version.
   *
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   * GNU General Public License for more details.
   *
   * You should have received a copy of the GNU General Public License
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/

  declare (strict_types=1);

  namespace quarxConnect\Events;

  use Closure;
  use Exception;
  use Throwable;
  
  class Base {
    /* Loop-States */
    private const LOOP_STATE_IDLE   = -1;
    private const LOOP_STATE_ACTIVE = 0;
    private const LOOP_STATE_ONCE   = 1;
    private const LOOP_STATE_BREAK  = 2;

    /**
     * All events of this base
     *
     * @var array
     **/
    private array $eventHandlers = [];

    /**
     * File-Descriptors to watch for reading
     *
     * @var array
     **/
    private array $readDescriptors = [];

    /**
     * File-Descriptors to watch for writing
     *
     * @var array
     **/
    private array $writeDescriptors = [];

    /**
     * File-Descriptors to watch for errors
     *
     * @var array
     **/
    private array $errorDescriptors = [];

    /**
     * Map Event-Handlers of descriptors
     *
     * @var array
     **/
    private array $descriptorOwners = [];

    /**
     * Pending forced events
     *
     * @var array
     **/
    private array $forcedEvents = [];

    /**
     * Timer-Events
     *
     * @var array
     **/
    private array $activeTimers = [];

    /**
     * Time of next timer-event
     *
     * @var array|null
     **/
    private array|null $timerNext = null;

    /**
     * Loop-State
     *
     * @var integer
     **/
    private int $loopState = Base::LOOP_STATE_IDLE;

    // {{{ singleton
    /**
     * Create a single instance of this class
     *
     * @access public
     * @return Base
     **/
    public static function singleton (): Base
    {
      static $primaryInstance = null;

      if (!$primaryInstance)
        $primaryInstance = new Base ();

      return $primaryInstance;
    }
    // }}}

    // {{{ __debugInfo
    /**
     * Return information about this instance to be dumped by var_dump()
     *
     * @access public
     * @return array
     **/
    public function __debugInfo (): array
    {
      // Append state to result
      static $stateMap = [
        self::LOOP_STATE_IDLE   => 'Idle',
        self::LOOP_STATE_ACTIVE => 'Active',
        self::LOOP_STATE_ONCE   => 'Once',
        self::LOOP_STATE_BREAK  => 'Break in Active/Once',
      ];

      $debugInfo = [
        'loopState' => ($stateMap [$this->loopState] ?? 'Unknown (' . $this->loopState . ')'),
        'watchedReads' => count ($this->readDescriptors),
        'watchedWrites' => count ($this->writeDescriptors),
        'watchedErrors' => count ($this->errorDescriptors),
      ];

      // Append events to result
      if (count ($this->eventHandlers) > 0) {
        $registeredEvents = [];

        foreach ($this->eventHandlers as $registeredEvent)
          if (method_exists ($registeredEvent, '__debugInfo'))
            $registeredEvents [] = $registeredEvent;
          else if (function_exists ('spl_object_id'))
            $registeredEvents [] = get_class ($registeredEvent) . '#' . spl_object_id ($registeredEvent);
          else
            $registeredEvents [] = get_class ($registeredEvent) . '@' . spl_object_hash ($registeredEvent);

        $debugInfo ['registeredEvents'] = $registeredEvents;
      }

      // Append timers to result
      if (count ($this->activeTimers) > 0) {
        $registeredTimers = [ ];

        foreach ($this->activeTimers as $secTimers)
          foreach ($secTimers as $usecTimers)
            $registeredTimers = array_merge ($registeredTimers, array_values ($usecTimers));

        $debugInfo ['registeredTimers'] = $registeredTimers;
        $debugInfo ['nextTimerScheduledAt'] = [ 'sec' => $this->timerNext [0], 'usec' => $this->timerNext [1] ];
      }

      // Append forced events to result
      if (count ($this->forcedEvents) > 0) {
        $forcedEvents = [];

        foreach ($this->forcedEvents as $forcedEvent)
          if (is_array ($forcedEvent) && (count ($forcedEvent) == 2))
            $forcedEvents [] = [ (is_object ($forcedEvent [0]) ? get_class ($forcedEvent [0]) : $forcedEvent [0]), $forcedEvent [1] ];
          elseif ($forcedEvent instanceof Closure)
            $forcedEvents [] = (function_exists ('spl_object_id') ? 'Closure#' . spl_object_id ($forcedEvent) : 'Closure@' . spl_object_hash ($forcedEvent));
          else
            $forcedEvents [] = $forcedEvent;

        $debugInfo ['forcedEvents'] = $forcedEvents;
      }

      return $debugInfo;
    }
    // }}}

    // {{{ addEvent
    /**
     * Add an event to our event-loop
     *
     * @param ABI\Loop $eventHandler
     *
     * @access public
     * @return bool
     **/
    public function addEvent (ABI\Loop $eventHandler): bool
    {
      // Check if the event is already assigned to an event-loop
      $eventBase = $eventHandler->getEventBase ();

      if (
        is_object ($eventBase) &&
        ($eventBase !== $this)
      )
        $eventBase->removeEvent ($eventHandler);

      if (in_array ($eventHandler, $this->eventHandlers, true))
        return $this->updateEvent ($eventHandler);

      // Set ourselves as event-loop on the event
      $eventHandler->setEventBase ($this);

      // Append to local storage
      $this->eventHandlers [] = $eventHandler;

      // Treat as an update
      return $this->updateEvent ($eventHandler);
    }
    // }}}

    // {{{ updateEvent
    /**
     * Update the FDs of an event
     *
     * @param ABI\Loop $eventHandler
     *
     * @access public
     * @return boolean
     **/
    public function updateEvent (ABI\Loop $eventHandler): bool
    {
      // Check if this event is on our collection
      $eventIndex = array_search ($eventHandler, $this->eventHandlers, true);

      if ($eventIndex === false)
        return false;

      // Remove error-fds and stale owner-ships (maybe we may skip this for performance-reasons)
      foreach (array_keys ($this->descriptorOwners, $eventHandler, true) as $Key)
        unset ($this->descriptorOwners [$Key], $this->errorDescriptors [$Key]);

      // Retrieve the FDs
      $readDescriptor = $eventHandler->getReadFD ();
      $writeDescriptor = $eventHandler->getWriteFD ();
      $errorDescriptor = $eventHandler->getErrorFD ();

      if (is_resource ($readDescriptor)) {
        $this->readDescriptors [$eventIndex] = $readDescriptor;
        $this->errorDescriptors [(int)$readDescriptor] = $readDescriptor;
        $this->descriptorOwners [(int)$readDescriptor] = $eventHandler;
      } else
        unset ($this->readDescriptors [$eventIndex]);

      if (is_resource ($writeDescriptor)) {
        $this->writeDescriptors [$eventIndex] = $writeDescriptor;
        $this->errorDescriptors [(int)$writeDescriptor] = $writeDescriptor;
        $this->descriptorOwners [(int)$writeDescriptor] = $eventHandler;
      } else
        unset ($this->writeDescriptors [$eventIndex]);

      if (is_resource ($errorDescriptor)) {
        $this->errorDescriptors [(int)$errorDescriptor] = $errorDescriptor;
        $this->descriptorOwners [(int)$errorDescriptor] = $eventHandler;
      }

      return true;
    }
    // }}}

    // {{{ removeEvent
    /**
     * Remove an event from this event-loop
     *
     * @param ABI\Loop $eventHandler
     *
     * @access public
     * @return void
     **/
    public function removeEvent (ABI\Loop $eventHandler): void
    {
      // Check if this event is on our collection
      $eventIndex = array_search ($eventHandler, $this->eventHandlers, true);

      if ($eventIndex === false)
        return;

      // Remove the references
      foreach (array_keys ($this->descriptorOwners, $eventHandler, true) as $descriptorIndex)
        unset (
          $this->descriptorOwners [$descriptorIndex],
          $this->errorDescriptors [$descriptorIndex]
        );

      unset (
        $this->eventHandlers [$eventIndex],
        $this->readDescriptors [$eventIndex],
        $this->writeDescriptors [$eventIndex]
      );
    }
    // }}}

    // {{{ haveEvent 
    /**
     * Check if we have a given event registered
     *
     * @param ABI\Loop $eventHandler
     *
     * @access public
     * @return boolean
     **/
    public function haveEvent (ABI\Loop $eventHandler): bool
    {
      return in_array ($eventHandler, $this->eventHandlers, true);
    }
    // }}}

    // {{{ getEvents
    /**
     * Retrieve all events from this base
     *
     * @access public
     * @return array
     **/
    public function getEvents (): array
    {
      return $this->eventHandlers;
    }
    // }}}

    // {{{ getDataPath
    /**
     * Retrieve path to our data-directory
     *
     * @access public
     * @return string
     *
     * @throws Exception
     **/
    public function getDataPath (): string
    {
      // Check if we can get user-settings
      if (function_exists ('posix_getpwuid')) {
        /** @noinspection PhpComposerExtensionStubsInspection */
        $pw = posix_getpwuid (posix_geteuid ());
        $Path = $pw ['dir'];

      // Have a look at our environment as fallback
      } elseif (isset ($_ENV ['HOME']))
        $Path = $_ENV ['HOME'];
      else
        throw new Exception ('Unable to locate users root-directory');

      // Append ourselves to path
      $Path .= '/.qcEvents';

      // Make sure our path exists
      if (
        !is_dir ($Path) &&
        !mkdir ($Path, 0700)
      )
        throw new Exception ('Failed to create data-path');
      
      return $Path;
    }
    // }}}

    // {{{ forceCallback
    /**
     * Force a given callback to be invoked on the next loop-round
     *
     * @param callable $Callback
     *
     * @access public
     * @return void
     **/
    public function forceCallback (callable $Callback): void
    {
      $this->forcedEvents [] = $Callback;
    }
    // }}}

    // {{{ addTimeout
    /**
     * Enqueue a timeout
     *
     * @param array|int|float $timerDuration The timeout to wait (might be in seconds or array (seconds, microseconds))
     * @param bool $timerRepeat (optional)
     *
     * @access public
     * @return Timer Timer-Promise is fulfilled once the timeout was reached and never rejected
     **/
    public function addTimeout (array|int|float $timerDuration, bool $timerRepeat = false): Timer
    {
      if (is_array ($timerDuration))
        $timerDuration = $timerDuration [0] + $timerDuration [1] / 1000000;

      return new Timer ($this, $timerDuration, $timerRepeat);
    }
    // }}}

    // {{{ addTimer
    /**
     * Set up a new timer
     *
     * @param Timer $newTimer
     *
     * @access public
     * @return void
     **/
    public function addTimer (Timer $newTimer): void
    {
      // Check if the timer is already enqueue
      foreach ($this->activeTimers as $usecTimeouts)
        foreach ($usecTimeouts as $timerInstances)
          if (in_array ($newTimer, $timerInstances, true))
            return;

      // Get interval the timer
      $timerInterval = $newTimer->getInterval ();

      $Seconds = floor ($timerInterval);
      $uSeconds = ($timerInterval - $Seconds) * 1000000;

      // Enqueue the timer
      $Then = $this->getTimer ();

      $Then [0] += $Seconds;
      $Then [1] += $uSeconds;

      while ($Then [1] > 1000000) {
        $Then [0]++;
        $Then [1] -= 1000000;
      }

      // Enqueue the event
      if (!isset ($this->activeTimers [$Then [0]])) {
        $this->activeTimers [$Then [0]] = [ $Then [1] => [ $newTimer ] ];

        ksort ($this->activeTimers, SORT_NUMERIC);
      } elseif (!isset ($this->activeTimers [$Then [0]][$Then [1]])) {
        $this->activeTimers [$Then [0]][$Then [1]] = [ $newTimer ];

        ksort ($this->activeTimers [$Then [0]], SORT_NUMERIC);
      } else
        $this->activeTimers [$Then [0]][$Then [1]][] = $newTimer;

      // Set the next timer
      if (
        ($this->timerNext === null) ||
        ($this->timerNext [0] > $Then [0]) ||
        (($this->timerNext [0] == $Then [0]) && ($this->timerNext [1] > $Then [1]))
      )
        $this->timerNext = $Then;
    }
    // }}}

    // {{{ clearTimer
    /**
     * Remove a pending timer
     *
     * @param Timer $Timer
     *
     * @access public
     * @return void
     **/
    public function clearTimer (Timer $Timer): void
    {
      $timerFound = false;

      foreach ($this->activeTimers as $Second=>$Timers) {
        foreach ($Timers as $uSecond=>$Events) {
          foreach ($Events as $ID=>$pTimer)
            if ($pTimer === $Timer) {
              $timerFound = true;

              unset ($this->activeTimers [$Second][$uSecond][$ID]);
              break;
            }

          if (count ($this->activeTimers [$Second][$uSecond]) == 0)
            unset ($this->activeTimers [$Second][$uSecond]);

          if ($timerFound)
            break;
        }

        if (count ($this->activeTimers [$Second]) == 0)
          unset ($this->activeTimers [$Second]);

        if ($timerFound)
          break;
      }

      if ($timerFound)
        $Timer->cancel ();
    }
    // }}}

    // {{{ loop
    /**
     * Enter the event-loop
     *
     * @param bool $loopSingle (optional) Just process all pending events once
     * @param bool $singleWait (optional) Wait for timers if necessary
     *
     * @access public
     * @return void
     *
     * @throws Exception
     **/
    public function loop (bool $loopSingle = false, bool $singleWait = false): void
    {
      // Don't enter the loop twice
      $onLoop = ($this->loopState != self::LOOP_STATE_IDLE);

      if ($onLoop && !$loopSingle)
        throw new Exception ('Do not enter the loop twice');

      // Reset the loop-state
      if (!($doubleState = ($onLoop && $loopSingle)))
        $this->loopState = ($loopSingle ? self::LOOP_STATE_ONCE : self::LOOP_STATE_ACTIVE);

      // Main-Loop
      do {
        // Run forced events first
        $forcedEvents = $this->forcedEvents;
        $forcedEventCount = count ($forcedEvents);
        $this->forcedEvents = [];

        while ($forcedEventCount-- > 0) {
          $this->invoke (array_shift ($forcedEvents));

          if ($this->loopState == self::LOOP_STATE_BREAK) {
            $this->forcedEvents = array_merge (
              $forcedEvents,
              $this->forcedEvents
            );

            break (2);
          }
        }

        // Check if there are queued event-handlers
        if (
          (count ($this->descriptorOwners) == 0) &&
          (count ($this->activeTimers) == 0)
        ) {
          if (
            !$loopSingle &&
            (count ($this->forcedEvents) > 0)
          )
            continue;

          break;
        }

        // Copy the fdSets (We do the copy because the arrays will be modified)
        $readDescriptors = $this->readDescriptors;
        $writeDescriptors = $this->writeDescriptors;
        $errorDescriptors = $this->errorDescriptors;

        // Check if there are events forced 
        if (
          ($loopSingle && (!$singleWait || !$this->timerNext)) ||
          (count ($this->forcedEvents) > 0)
        ) {
          $microSeconds = 1;

        // Check if there is a timer queued
        } elseif ($this->timerNext !== null) {
          // Get the current time
          $Now = $this->getTimer ();

          // Return the wait-time
          $microSeconds = (int)max (1, (($this->timerNext [0] - $Now [0]) * 1000000) + ($this->timerNext [1] - $Now [1]));
        } else
          $microSeconds = 5000000;

        // Check whether to select or just wait
        if (
          (count ($readDescriptors) === 0) &&
          (count ($writeDescriptors) === 0) &&
          (count ($errorDescriptors) === 0)
        ) {
          $eventCount = 0;

          // Check if there are timers enqueued
          if ($this->timerNext === null)
            trigger_error ('Empty loop without timers');

          // Sleep if we are in a normal loop
          if ($this->loopState == self::LOOP_STATE_ACTIVE)
            usleep ($microSeconds);
        } else {
          $secs = (int)floor ($microSeconds / 1000000);
          $microSeconds %= 1000000;

          $eventCount = stream_select ($readDescriptors, $writeDescriptors, $errorDescriptors, $secs, $microSeconds);
        }

        // Check for pending signals
        if ($this->timerNext !== null)
          $this->runTimers ();

        // Stop here if there are no events pending
        if ($eventCount == 0) {
          if ($loopSingle)
            break;

          continue;
        }

        foreach ($readDescriptors as $readFD) {
          if (isset ($this->descriptorOwners [(int)$readFD]))
            $this->invoke ([ $this->descriptorOwners [(int)$readFD], 'raiseRead' ]);

          if ($this->loopState == self::LOOP_STATE_BREAK)
            break (2);
        }

        foreach ($writeDescriptors as $writeFD) {
          if (isset ($this->descriptorOwners [(int)$writeFD]))
            $this->invoke ([ $this->descriptorOwners [(int)$writeFD], 'raiseWrite' ]);

          if ($this->loopState == self::LOOP_STATE_BREAK)
            break (2);
        }

        foreach ($errorDescriptors as $errorFD) {
          if (isset ($this->descriptorOwners [(int)$errorFD]))
            $this->invoke ([ $this->descriptorOwners [(int)$errorFD], 'raiseError' ], $errorFD);

          if ($this->loopState == self::LOOP_STATE_BREAK)
            break (2);
        }
      } while ($this->loopState == self::LOOP_STATE_ACTIVE);

      // Reset the loop-state
      $this->loopState = ($doubleState ? self::LOOP_STATE_ACTIVE : self::LOOP_STATE_IDLE);
    }
    // }}}

    // {{{ loopBreak
    /**
     * Immediately abort the event-loop
     *
     * @access public
     * @return void
     **/
    public function loopBreak (): void
    {
      if ($this->loopState != self::LOOP_STATE_IDLE)
        $this->loopState = self::LOOP_STATE_BREAK;
    }
    // }}}

    // {{{ loopExit
    /**
     * Exit the event-loop once all currently pending events where processed
     *
     * @access public
     * @return void
     **/
    public function loopExit (): void
    {
      if ($this->loopState != self::LOOP_STATE_IDLE)
        $this->loopState = max ($this->loopState, self::LOOP_STATE_ONCE);
    }
    // }}}

    // {{{ getTimer
    /**
     * Retrieve current precise time
     *
     * @access private
     * @return array
     **/
    private function getTimer (): array
    {
      $Now = gettimeofday ();

      return [ $Now ['sec'], $Now ['usec'] ];
    }
    // }}}

    // {{{ runTimers
    /**
     * Run any pending timer-event
     *
     * @access private
     * @return void
     **/
    private function runTimers (): void
    {
      // Check if there is a timer queued
      if ($this->timerNext === null)
        return;

      // Get the current time
      $Now = $this->getTimer ();

      // Check whether to run timers
      if (
        ($this->timerNext [0] > $Now [0]) ||
        (($this->timerNext [0] == $Now [0]) && ($this->timerNext [1] > $Now [1]))
      )
        return;

      // Run all timers
      $Current = $this->timerNext;

      foreach ($this->activeTimers as $Second=>$Timers) {
        // Check if we have moved too far
        if ($Second > $Now [0]) {
          if (
            ($this->timerNext !== null) &&
            (($this->timerNext [0] == $Current [0]) || ($this->timerNext [0] > $Second))
          ) {
            reset ($this->activeTimers);

            if (($nextSecond = key ($this->activeTimers)) !== null) {
              reset ($this->activeTimers [$nextSecond]);

              $this->timerNext = [ $nextSecond, key ($this->activeTimers [$nextSecond]) ];
            } else
              $this->timerNext = null;
          }

          break;
        }

        foreach ($Timers as $uSecond=>$pTimers) {
          // Check if we are dealing with really present events and would move too far
          if (($Second == $Now [0]) && ($uSecond > $Now [1])) {
            $this->timerNext [1] = $uSecond;

            break (2);
          }

          // Remove this distinct usec from queue
          // We do this already here because $Timer->run() might re-enqueue timers,
          // so they have to be removed before
          unset ($this->activeTimers [$Second][$uSecond]);

          // Run all events
          foreach ($pTimers as $Timer)
            // Run the timer
            $Timer->run ();

          // Get the current time
          $Now = $this->getTimer ();
        }

        // Remove the second if all timers were fired
        if (
          ($Second < $Now [0]) ||
          (isset ($this->activeTimers [$Second]) && (count ($this->activeTimers [$Second]) == 0))
        )
          unset ($this->activeTimers [$Second]);
      }

      // Check whether to dequeue the timer
      if (count ($this->activeTimers) == 0)
        $this->timerNext = null;
      elseif (
        ($this->timerNext [0] == $Current [0]) &&
        ($this->timerNext [1] == $Current [1])
      ) {
        reset ($this->activeTimers);

        $this->timerNext = [
          key ($this->activeTimers),
          key (current ($this->activeTimers))
        ];
      }
    }
    // }}}

    // {{{ invoke
    /**
     * Safely run a given callback
     *
     * @param callable $invokeCallback
     * @param ...
     *
     * @access private
     * @return void
     **/
    private function invoke (callable $invokeCallback): void
    {
      // Prepare parameters
      $invokeParameters = func_get_args ();
      $invokeCallback = array_shift ($invokeParameters);

      // Try to run
      try {
        call_user_func_array ($invokeCallback, $invokeParameters);
      } catch (Throwable $errorException) {
        error_log ('Uncaught ' . $errorException);
      }
    }
    // }}}
  }
