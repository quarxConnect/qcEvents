<?php

  /**
   * quarxConnect Events - Event-Loop
   * Copyright (C) 2014-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  class Base {
    /* Event-Types */
    public const EVENT_READ = 0;
    public const EVENT_WRITE = 1;
    public const EVENT_ERROR = 2;
    public const EVENT_TIMER = 3;
    
    /* All events of this base */
    private $Events = [ ];
    
    /* Stored FDs for event-loop */
    private $readFDs = [ ];
    private $writeFDs = [ ];
    private $errorFDs = [ ];
    private $fdOwner = [ ];
    
    /* Pending forced events */
    private $forcedEvents = [ ];
    
    /* Timer-Events */
    private $Timers = [ ];
    private $TimerNext = null;
    
    /* Loop-State */
    private const LOOP_STATE_IDLE = -1;
    private const LOOP_STATE_ACTIVE = 0;
    private const LOOP_STATE_ONCE = 1;
    private const LOOP_STATE_BREAK = 2;
    
    private $loopState = Base::LOOP_STATE_IDLE;
    
    // {{{ singleton
    /**
     * Create a single instance of this class
     * 
     * @access public
     * @return Base
     **/
    public static function singleton () : Base {
      static $primaryInstance = null;
      
      if (!$primaryInstance)
        $primaryInstance = new static ();
      
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
    public function __debugInfo () : array {
      // Append state to result
      static $stateMap = [
        self::LOOP_STATE_IDLE   => 'Idle',
        self::LOOP_STATE_ACTIVE => 'Active',
        self::LOOP_STATE_ONCE   => 'Once',
        self::LOOP_STATE_BREAK  => 'Break in Active/Once',
      ];
      
      $Result = [
        'loopState' => ($stateMap [$this->loopState] ?? 'Unknown (' . $this->loopState . ')'),
        'watchedReads' => count ($this->readFDs),
        'watchedWrites' => count ($this->writeFDs),
        'watchedErrors' => count ($this->errorFDs),
      ];
      
      // Append events to result
      if (count ($this->Events) > 0) {
        $registeredEvents = [ ];
        
        foreach ($this->Events as $registeredEvent)
          if (method_exists ($registeredEvent, '__debugInfo'))
            $registeredEvents [] = $registeredEvent;
          else if (function_exists ('spl_object_id'))
            $registeredEvents [] = get_class ($registeredEvent) . '#' . spl_object_id ($registeredEvent);
          else
            $registeredEvents [] = get_class ($registeredEvent) . '@' . spl_object_hash ($registeredEvent);
        
        $Result ['registeredEvents'] = $registeredEvents;
      }
      
      // Append timers to result
      if (count ($this->Timers) > 0) {
        $registeredTimers = [ ];
        
        foreach ($this->Timers as $secTimers)
          foreach ($secTimers as $usecTimers)
            $registeredTimers = array_merge ($registeredTimers, array_values ($usecTimers));
        
        $Result ['registeredTimers'] = $registeredTimers;
        $Result ['nextTimerScheduledAt'] = [ 'sec' => $this->TimerNext [0], 'usec' => $this->TimerNext [1] ];
      }
      
      // Append forced events to result
      if (count ($this->forcedEvents) > 0) {
        $forcedEvents = [ ];
        
        foreach ($this->forcedEvents as $forcedEvent)
          if (is_array ($forcedEvent) && (count ($forcedEvent) == 2))
            $forcedEvents [] = [ (is_object ($forcedEvent [0]) ? get_class ($forcedEvent [0]) : $forcedEvent [0]), $forcedEvent [1] ];
          elseif ($forcedEvent instanceof Closure)
            $forcedEvents [] = (function_exists ('spl_object_id') ? 'Closure#' . spl_object_id ($forcedEvent) : 'Closure@' . spl_object_hash ($forcedEvent));
          else
            $forcedEvents [] = $forcedEvent;
        
        $Result ['forcedEvents'] = $forcedEvents;
      }
      
      return $Result;
    }
    // }}}
    
    // {{{ addEvent
    /**
     * Add an event to our event-loop
     * 
     * @param Interface\Loop $Event
     * 
     * @access public
     * @return bool
     **/
    public function addEvent (Interface\Loop $Event) {
      // Check if the event is already assigned to an event-loop
      if (is_object ($Base = $Event->getEventBase ()) && ($Base instanceof Base)) {
        if ($Base !== $this)
          $Base->removeEvent ($Event);
        
        elseif (in_array ($Event, $this->Events, true))
          return $this->updateEvent ($Event);
      }
      
      // Set ourself as event-loop on the event
      $Event->setEventBase ($this);
      
      // Append to local storage
      $this->Events [] = $Event;
      
      // Treat as an update
      return $this->updateEvent ($Event);
    }
    // }}}
    
    // {{{ updateEvent
    /**
     * Update the FDs of an event
     * 
     * @param Interface\Loop $Event
     * 
     * @access public
     * @return bool
     **/
    public function updateEvent (Interface\Loop $Event) {
      // Check if this event is on our collection
      if (($Index = array_search ($Event, $this->Events, true)) === false)
        return false;
      
      // Remove error-fds and stale owner-ships (maybe we may skip this for performance-reasons)
      foreach (array_keys ($this->fdOwner, $Event, true) as $Key)
        unset ($this->fdOwner [$Key], $this->errorFDs [$Key]);
      
      // Retrive the FDs
      if (is_resource ($readFD = $Event->getReadFD ())) {
        $this->readFDs [$Index] = $this->errorFDs [(int)$readFD] = $readFD;
        $this->fdOwner [(int)$readFD] = $Event;
      } else
        unset ($this->readFDs [$Index]);
      
      if (is_resource ($writeFD = $Event->getWriteFD ())) {
        $this->writeFDs [$Index] = $this->errorFDs [(int)$writeFD] = $writeFD;
        $this->fdOwner [(int)$writeFD] = $Event;
      } else
        unset ($this->writeFDs [$Index]);
      
      if (is_resource ($errorFD = $Event->getErrorFD ())) {
        $this->errorFDs [(int)$errorFD] = $errorFD;
        $this->fdOwner [(int)$errorFD] = $Event;
      }
      
      return true;
    }
    // }}}
    
    // {{{ removeEvent
    /**
     * Remove an event from this event-loop
     * 
     * @param Interface\Loop $Event
     * 
     * @access public
     * @return void
     **/
    public function removeEvent (Interface\Loop $Event) {
      // Check if this event is on our collection
      if (($Index = array_search ($Event, $this->Events, true)) === false)
        return false;
      
      // Remove the references
      foreach (array_keys ($this->fdOwner, $Event, true) as $Key)
        unset ($this->fdOwner [$Key], $this->errorFDs [$Key]);
      
      unset ($this->Events [$Index]);
      unset ($this->readFDs [$Index], $this->writeFDs [$Index]);
    }
    // }}}
    
    // {{{ haveEvent 
    /**
     * Check if we have a given event registered
     * 
     * @param Interface\Loop $Event
     * 
     * @access public
     * @return bool
     **/
    public function haveEvent (Interface\Loop $Event) {
      return in_array ($Event, $this->Events, true);
    }
    // }}}
    
    // {{{ getEvents
    /**
     * Retrive all events from this base
     * 
     * @access public
     * @return array
     **/
    public function getEvents () {
      return $this->Events;
    }
    // }}}
    
    // {{{ getDataPath
    /**
     * Retrive path to our data-directory
     * 
     * @access public
     * @return string
     **/
    public function getDataPath () {
      // Check if we can get user-settings
      if (function_exists ('posix_getpwuid')) {
        $pw = posix_getpwuid (posix_geteuid ());
        $Path = $pw ['dir'];
      
      // Have a look at our environment as fallback
      } elseif (isset ($_ENV ['HOME']))
        $Path = $_ENV ['HOME'];
      else
        return null;
      
      // Append ourself to path
      $Path .= '/.qcEvents';
      
      // Make sure our path exists
      if (is_dir ($Path) || mkdir ($Path, 0700))
        return $Path;
    }
    // }}}
    
    // {{{ forceEventRaise
    /**
     * Force the raise of a given event on next iteration of event-loop
     * 
     * @param Interface\Loop $Event
     * @param enum $evType
     * 
     * @access public
     * @return bool
     **/
    public function forceEventRaise (Interface\Loop $Event, $evType) {
      if ($evType == self::EVENT_READ)
        $this->forcedEvents [] = [ $Event, 'raiseRead' ];
      elseif ($evType == self::EVENT_WRITE)
        $this->forcedEvents [] = [ $Event, 'raiseWrite' ];
      elseif ($evType == self::EVENT_ERROR)
        $this->forcedEvents [] = [ $Event, 'raiseError' ];
      else
        return false;
      
      return true;
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
    public function forceCallback (callable $Callback) {
      $this->forcedEvents [] = $Callback;
    }
    // }}}
    
    // {{{ addTimeout
    /**
     * Enqueue a timeout
     * 
     * @param mixed $Timeout The timeout to wait (may be in seconds or array (seconds, microseconds))
     * @param bool $Repeat (optional)
     * 
     * @access public
     * @return Timer Timer-Promise is fullfilled once the timeout was reached and never rejected
     **/
    public function addTimeout ($Timeout, $Repeat = false) : Timer {
      return new Timer ($this, $Timeout, $Repeat);
    }
    // }}}
    
    // {{{ addTimer
    /**
     * Setup a new timer
     * 
     * @param Timer $Timer
     * 
     * @access public
     * @return bool TRUE if the timer was added, FALSE if Timer is already on queue
     **/
    public function addTimer (Timer $Timer) {
      // Check if the timer is already enqueue
      foreach ($this->Timers as $sTime=>$Timers)
        foreach ($Timers as $uTime=>$Instances)
          if (in_array ($Timer, $Instances, true))
            return false;
      
      // Get interval the timer
      $Interval = $Timer->getInterval ();
      
      $Seconds = floor ($Interval);
      $uSeconds = ($Interval - $Seconds) * 1000000;
      
      // Enqueue the timer
      $Then = $this->getTimer ();
      
      $Then [0] += $Seconds;
      $Then [1] += $uSeconds;
      
      while ($Then [1] > 1000000) {
        $Then [0]++;
        $Then [1] -= 1000000;
      }
      
      // Enqueue the event
      if (!isset ($this->Timers [$Then [0]])) {
        $this->Timers [$Then [0]] = [ $Then [1] => [ $Timer ] ];
        
        ksort ($this->Timers, SORT_NUMERIC);
      } elseif (!isset ($this->Timers [$Then [0]][$Then [1]])) {
        $this->Timers [$Then [0]][$Then [1]] = [ $Timer ];
        
        ksort ($this->Timers [$Then [0]], SORT_NUMERIC);
      } else
        $this->Timers [$Then [0]][$Then [1]][] = $Timer;
      
      // Set the next timer
      if (($this->TimerNext === null) ||
          ($this->TimerNext [0] > $Then [0]) ||
          (($this->TimerNext [0] == $Then [0]) && ($this->TimerNext [1] > $Then [1])))
        $this->TimerNext = $Then;
      
      return true;
    }
    // }}}
    
    // {{{ clearTimer
    /**
     * Remove a pending timer
     * 
     * @param Timer $Timer
     * 
     * @access public
     * @return bool True if the timer was really removed
     **/
    public function clearTimer (Timer $Timer) {
      $Found = false;
      
      foreach ($this->Timers as $Second=>$Timers) {
        foreach ($Timers as $uSecond=>$Events) {
          foreach ($Events as $ID=>$pTimer)
            if ($pTimer === $Timer) {
              $Found = true;
              
              unset ($this->Timers [$Second][$uSecond][$ID]);
              break;
            }
          
          if (count ($this->Timers [$Second][$uSecond]) == 0)
            unset ($this->Timers [$Second][$uSecond]);
          
          if ($Found)
            break;
        }
        
        if (count ($this->Timers [$Second]) == 0)
          unset ($this->Timers [$Second]);
        
        if ($Found)
          break;
      }
      
      if ($Found)
        $Timer->cancel ();
      
      return $Found;
    }
    // }}}
    
    // {{{ loop
    /**
     * Enter the event-loop
     * 
     * @param bool $Single (optional) Just process all pending events once
     * 
     * @access public
     * @return bool
     **/
    public function loop ($Single = false) {
      // Don't enter the loop twice
      $onLoop = ($this->loopState != self::LOOP_STATE_IDLE);
      
      if ($onLoop && !$Single) {
        trigger_error ('Do not enter the loop twice');
        
        return false;
      }
      
      // Reset the loop-state
      if (!($doubleState = ($onLoop && $Single)))
        $this->loopState = ($Single ? self::LOOP_STATE_ONCE : self::LOOP_STATE_ACTIVE);
      
      // Main-Loop
      do {
        // Run forced events first
        $evForced = $this->forcedEvents;
        $this->forcedEvents = [ ];
        
        foreach ($evForced as $ev) {
          $this->invoke ($ev);
          
          if ($this->loopState == self::LOOP_STATE_BREAK)
            break (2);
        }
        
        // Check if there are queued event-handlers
        if ((count ($this->fdOwner) == 0) && (count ($this->Timers) == 0)) {
          if (count ($this->forcedEvents) > 0)
            continue;
          
          break;
        }
        
        // Copy the fdSets (We do the copy because the arrays will be modified)
        $readFDs = $this->readFDs;
        $writeFDs = $this->writeFDs;
        $errorFDs = $this->errorFDs;
        
        // Check if there are events forced 
        if (count ($this->forcedEvents) > 0) {
          $usecs = 1;
        
        // Check if there is a timer queued
        } elseif ($this->TimerNext !== null) {
          // Get the current time
          $Now = $this->getTimer ();
          
          // Return the wait-time
          $usecs = max (1, (($this->TimerNext [0] - $Now [0]) * 1000000) + ($this->TimerNext [1] - $Now [1]));
        } else
          $usecs = 5000000;
        
        // Check wheter to select or just wait
        if ((count ($readFDs) == 0) && (count ($writeFDs) == 0) && (count ($errorFDs) == 0)) {
          $Count = 0;
          
          // Check if there are timers enqueued
          if ($this->TimerNext === null)
            trigger_error ('Empty loop without timers');
          
          // Sleep if we are in a normal loop
          if ($this->loopState == self::LOOP_STATE_ACTIVE)
            usleep ($usecs);
        } else {
          $secs = floor ($usecs / 1000000);
          $usecs -= $secs * 1000000;
          
          $Count = stream_select ($readFDs, $writeFDs, $errorFDs, $secs, $usecs);
        }
        
        // Check for pending signals
        if ($this->TimerNext !== null)
          $this->runTimers ();
        
        // Stop here if there are no events pending
        if ($Count == 0)
          continue;
        
        foreach ($readFDs as $readFD) {
          if (isset ($this->fdOwner [(int)$readFD]))
            $this->invoke ([ $this->fdOwner [(int)$readFD], 'raiseRead' ]);
          
          if ($this->loopState == self::LOOP_STATE_BREAK)
            break (2);
        }
        
        foreach ($writeFDs as $writeFD) {
          if (isset ($this->fdOwner [(int)$writeFD]))
            $this->invoke ([ $this->fdOwner [(int)$writeFD], 'raiseWrite' ]);
          
          if ($this->loopState == self::LOOP_STATE_BREAK)
            break (2);
        }
        
        foreach ($errorFDs as $errorFD) {
          if (isset ($this->fdOwner [(int)$errorFD]))
            $this->invoke ([ $this->fdOwner [(int)$errorFD], 'raiseError' ], $errorFD);
          
          if ($this->loopState == self::LOOP_STATE_BREAK)
            break (2);
        }
      } while ($this->loopState == self::LOOP_STATE_ACTIVE);
      
      // Reset the loop-state
      $this->loopState = ($doubleState ? self::LOOP_STATE_ACTIVE : self::LOOP_STATE_IDLE);
      
      // Indicate success
      return true;
    }
    // }}}
    
    // {{{ loopBreak
    /**
     * Immediatly abort the event-loop
     * 
     * @access public
     * @return void
     **/
    public function loopBreak () {
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
    public function loopExit () {
      if ($this->loopState != self::LOOP_STATE_IDLE)
        $this->loopState = max ($this->loopState, self::LOOP_STATE_ONCE);
    }
    // }}}
    
    // {{{ getTimer
    /**
     * Retrive current precise time
     * 
     * @access private
     * @return array
     **/
    private function getTimer () {
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
    private function runTimers () {
      // Check if there is a timer queued
      if ($this->TimerNext === null)
        return;
      
      // Get the current time
      $Now = $this->getTimer ();
      
      // Check wheter to run timers
      if (($this->TimerNext [0] > $Now [0]) ||
          (($this->TimerNext [0] == $Now [0]) && ($this->TimerNext [1] > $Now [1])))
        return;
      
      // Run all timers
      $Current = $this->TimerNext;
      
      foreach ($this->Timers as $Second=>$Timers) {
        // Check if we have moved too far
        if ($Second > $Now [0]) {
          if (($this->TimerNext !== null) &&
              (($this->TimerNext [0] == $Current [0]) || ($this->TimerNext [0] > $Second))) {
            reset ($this->Timers);
            
            $this->TimerNext = [ key ($this->Timers), key ($Timers) ];
          }
          
          break;
        }
        
        foreach ($Timers as $uSecond=>$pTimers) {
          // Check if we are dealing with realy present events and would move too far
          if (($Second == $Now [0]) && ($uSecond > $Now [1])) {
            $this->TimerNext [1] = $uSecond;
            
            break (2);
          }
            
          // Remove this distinct usec from queue
          // We do this already here because $Timer->run() might re-enqueue timers
          // so they have to be removed before
          unset ($this->Timers [$Second][$uSecond]);
          
          // Run all events
          foreach ($pTimers as $Timer)
            // Run the timer
            $Timer->run ();
          
          // Get the current time
          $Now = $this->getTimer ();
        }
        
        // Remove the second if all timers were fired
        if (($Second < $Now [0]) || (isset ($this->Timers [$Second]) && (count ($this->Timers [$Second]) == 0)))
          unset ($this->Timers [$Second]);
      }
      
      // Check wheter to dequeue the timer
      if (count ($this->Timers) == 0)
        $this->TimerNext = null;
      elseif (($this->TimerNext [0] == $Current [0]) &&
              ($this->TimerNext [1] == $Current [1])) {
        reset ($this->Timers);
        
        $this->TimerNext = [
          key ($this->Timers),
          key (current ($this->Timers))
        ];
      }
    }
    // }}}
    
    // {{{ invoke
    /**
     * Safely run a given callback
     * 
     * @param callable $Callback
     * @param ...
     * 
     * @access private
     * @return mixed
     **/
    private function invoke (callable $Callback) {
      // Prepare parameters
      $Parameters = func_get_args ();
      $Callback = array_shift ($Parameters);
      
      // Try to run
      try {
        $Result = call_user_func_array ($Callback, $Parameters);
      } catch (\Throwable $errorException) {
        error_log ('Uncought' . $errorException);
        
        $Result = $errorException;
      }
      
      // Forward the result
      return $Result;
    }
    // }}}
  }
