<?PHP

  /**
   * qcEvents - Event-Loop
   * Copyright (C) 2014 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  class qcEvents_Base {
    /* Event-Types */
    const EVENT_READ = 0;
    const EVENT_WRITE = 1;
    const EVENT_ERROR = 2;
    const EVENT_TIMER = 3;
    
    /* All events of this base */
    private $Events = array ();
    
    /* Stored FDs for event-loop */
    private $readFDs = array ();
    private $writeFDs = array ();
    private $errorFDs = array ();
    private $fdOwner = array ();
    
    /* Pending forced events */
    private $forcedEvents = array ();
    
    /* Timer-Events */
    private $Timers = array ();
    private $TimerNext = null;
    
    /* Loop-State */
    private $loopState = -1;
    
    // {{{ singleton
    /**
     * Create a single instance of this class
     * 
     * @access public
     * @return qcEvents_Base
     **/
    public static function singleton () {
      static $Singleton = null;
      
      if (!$Singleton)
        $Singleton = new static ();
      
      return $Singleton;
    }
    // }}}
    
    
    // {{{ addEvent
    /**
     * Add an event to our event-loop
     * 
     * @param qcEvents_Interface_Loop $Event
     * 
     * @access public
     * @return bool
     **/
    public function addEvent (qcEvents_Interface_Loop $Event) {
      // Check if the event is already assigned to an event-loop
      if (is_object ($Base = $Event->getEventBase ()) && ($Base instanceof qcEvents_Base)) {
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
     * @param qcEvents_Interface_Loop $Event
     * 
     * @access public
     * @return bool
     **/
    public function updateEvent (qcEvents_Interface_Loop $Event) {
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
      
      return true;
    }
    // }}}
    
    // {{{ removeEvent
    /**
     * Remove an event from this event-loop
     * 
     * @access public
     * @return void
     **/
    public function removeEvent (qcEvents_Interface_Loop $Event) {
      // Check if this event is on our collection
      if (($Index = array_search ($Event, $this->Events, true)) === false)
        return false;
      
      // Remove the references
      foreach (array_keys ($this->fdOwner, $Event, true) as $Key)
        unset ($this->fdOwner [$Key], $this->errorFDs [$Key]);
      
      unset ($this->Events [$Index]);
      unset ($this->readFDs [$Index], $this->writeFDs [$Index]);
      
      // Check timers
      foreach ($this->Timers as $s=>$Timers) {
        foreach ($Timers as $m=>$Events) {
          foreach ($Events as $i=>$ev)
            if ($ev [0] === $Event)
              unset ($this->Timers [$s][$m][$i]);
          
          if (count ($this->Timers [$s][$m]) == 0)
            unset ($this->Timers [$s][$m]);
        }
        
        if (count ($this->Timers [$s]) == 0)
          unset ($this->Timers [$s]);
      }
    }
    // }}}
    
    // {{{ haveEvent 
    /**
     * Check if we have a given event registered
     * 
     * @param qcEvents_Interface_Loop $Event
     * 
     * @access public
     * @return bool
     **/
    public function haveEvent (qcEvents_Interface_Loop $Event) {
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
    
    // {{{ forceEventRaise
    /**
     * Force the raise of a given event on next iteration of event-loop
     * 
     * @param qcEvents_Interface_Loop $Event
     * @param enum $evType
     * 
     * @access public
     * @return bool
     **/
    public function forceEventRaise (qcEvents_Interface_Loop $Event, $evType) {
      if ($evType == self::EVENT_READ)
        $this->forcedEvents [] = array ($Event, 'raiseRead');
      elseif ($evType == self::EVENT_WRITE)
        $this->forcedEvents [] = array ($Event, 'raiseWrite');
      elseif ($evType == self::EVENT_ERROR)
        $this->forcedEvents [] = array ($Event, 'raiseError');
      elseif (($evType == self::EVENT_TIMER) && ($Event instanceof qcEvents_Interface_Timer))
        $this->forcedEvents [] = array ($Event, 'raiseTimer');
      else
        return false;
      
      return true;
    }
    // }}}
    
    // {{{ addTimer
    /**
     * Setup a new timer
     * 
     * @param qcEvents_Interface_Timer $Event
     * @param mixed $Timeout The timeout to wait (may be in seconds or array (seconds, microseconds))
     * @param bool $Repeat (optional) Repeat this event
     * @param callable $Callback (optional) Don't run raiseTimer() but the given callback
     * @param mixed $Private (optional) Pass this parameter to the given callback
     * 
     * @access public
     * @return void
     **/
    public function addTimer (qcEvents_Interface_Timer $Event, $Timeout, $Repeat = false, callable $Callback = null, $Private = null) {
      // Make sure we don't wast space if no callback is given
      if ($Callback === null)
        $Private = null;
      
      // Get the current time
      $Now = $this->getTimer ();
      
      // Calculate the time of the event
      $Then = $Now;
      $Then [0] += (is_array ($Timeout) ? $Timeout [0] : $Timeout);
      $Then [1] += (is_array ($Timeout) ? $Timeout [1] : 0);
      
      while ($Then [1] > 1000000) {
        $Then [0]++;
        $Then [1] -= 1000000;
      }
      
      // Enqueue the event
      if (!isset ($this->Timers [$Then [0]])) {
        $this->Timers [$Then [0]] = array ($Then [1] => array (array ($Event, $Timeout, $Repeat, $Callback, $Private)));
        
        ksort ($this->Timers, SORT_NUMERIC);
      } elseif (!isset ($this->Timers [$Then [0]][$Then [1]])) {
        $this->Timers [$Then [0]][$Then [1]] = array (array ($Event, $Timeout, $Repeat, $Callback, $Private));
        
        ksort ($this->Timers [$Then [0]], SORT_NUMERIC);
      } else
        $this->Timers [$Then [0]][$Then [1]][] = array ($Event, $Timeout, $Repeat, $Callback, $Private);
      
      // Set the next timer
      if (($this->TimerNext === null) ||
          ($this->TimerNext [0] > $Then [0]) ||
          (($this->TimerNext [0] == $Then [0]) && ($this->TimerNext [1] > $Then [1])))
        $this->TimerNext = $Then;
    }
    // }}}
    
    // {{{ clearTimer
    /**
     * Remove a pending timer
     * 
     * @param qcEvents_Interface_Timer $Event
     * @param mixed $Timeout The timeout to wait (may be in seconds or array (seconds, microseconds))
     * @param bool $Repeat (optional) Repeat this event
     * @param callable $Callback (optional) Don't run raiseTimer() but the given callback
     * @param mixed $Private (optional) Pass this parameter to the given callback
     * 
     * @access public
     * @return void
     **/
    public function clearTimer (qcEvents_Interface_Timer $Event, $Timeout, $Repeat = false, callable $Callback = null, $Private = null) {
      foreach ($this->Timers as $Sec=>$Timers) {
        foreach ($Timers as $Usec=>$Events) {
          foreach ($Events as $ID=>$Spec)
            if (($Spec [0] === $Event) && ($Spec [1] === $Timeout) && ($Spec [2] === $Repeat) && ($Spec [3] === $Callback) && ($Spec [4] === $Private))
              unset ($this->Timers [$Sec][$Usec][$ID]);
          
          if (count ($this->Timers [$Sec][$Usec]) == 0)
            unset ($this->Timers [$Sec][$Usec]);
        }
        
        if (count ($this->Timers [$Sec]) == 0)
          unset ($this->Timers [$Sec]);
      }
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
      if (($this->loopState >= 0) && !$Single) {
        trigger_error ('Do not enter the loop twice');
        
        return false;
      }
      
      // Reset the loop-state
      if (!($doubleState = (($this->loopState >= 0) && $Single)))
        $this->loopState = ($Single ? 1 : 0);
      
      // Main-Loop
      do {
        // Run forced events first
        $evForced = $this->forcedEvents;
        $this->forcedEvents = array ();
        
        foreach ($evForced as $ev) {
          call_user_func ($ev);
          
          if ($this->loopState > 1)
            break (2);
        }
        
        // Check if there are queued event-handlers
        if ((count ($this->fdOwner) == 0) && (count ($this->Timers) == 0))
          break;
        
        // Copy the fdSets (We do the copy because the arrays will be modified)
        $readFDs = $this->readFDs;
        $writeFDs = $this->writeFDs;
        $errorFDs = $this->errorFDs;
        
        // Check wheter to select or just wait
        $usecs = $this->getTimerWaitTime ();
        
        if ((count ($readFDs) == 0) && (count ($writeFDs) == 0) && (count ($errorFDs) == 0)) {
          $Count = 0;
          
          // Sleep if we are in a normal loop
          if ($this->loopState == 0)
            usleep ($usecs);
        } else {
          $secs = floor ($usecs / 1000000);
          $usecs -= $secs * 1000000;
          
          $Count = stream_select ($readFDs, $writeFDs, $errorFDs, $secs, $usecs);
        }
        
        // Check for pending signals
        $this->runTimers ();
        
        // Stop here if there are no events pending
        if ($Count == 0)
          continue;
        
        foreach ($readFDs as $readFD) {
          if (isset ($this->fdOwner [(int)$readFD]))
            $this->fdOwner [(int)$readFD]->raiseRead ();
          
          if ($this->loopState > 1)
            break (2);
        }
        
        foreach ($writeFDs as $writeFD) {
          if (isset ($this->fdOwner [(int)$writeFD]))
            $this->fdOwner [(int)$writeFD]->raiseWrite ();  
          
          if ($this->loopState > 1)
            break (2);
        }
        
        foreach ($errorFDs as $errorFD) {
          if (isset ($this->fdOwner [(int)$errorFD]))
            $this->fdOwner [(int)$errorFD]->raiseError ();  
          
          if ($this->loopState > 1)
            break (2);
        }
      } while ($this->loopState < 1);
      
      // Reset the loop-state
      $this->loopState = ($doubleState ? 0 : -1);
      
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
      if ($this->loopState < 0)
        return;
      
      $this->loopState = 2;
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
      if ($this->loopState < 0)
        return;
      
      $this->loopState = max ($this->loopState, 1);
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
      
      return array ($Now ['sec'], $Now ['usec']);
    }
    // }}}
    
    // {{{ getTimerWaitTime
    /**
     * Retrive the wait-time until the next timer-event in microseconds
     * 
     * @access private
     * @return int
     **/
    private function getTimerWaitTime () {
      // Check if there are events forced
      if (count ($this->forcedEvents) > 0)
        return 1;
      
      // Check if there is a timer queued
      if ($this->TimerNext === null)
        return 5000000;
      
      // Get the current time
      $Now = $this->getTimer ();
      
      // Return the wait-time
      return max (1, (($this->TimerNext [0] - $Now [0]) * 1000000) + ($this->TimerNext [1] - $Now [1]));
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
      
      foreach ($this->Timers as $Sec=>$Timers) {
        // Get the current time
        $Now = $this->getTimer ();
        
        if ($Sec > $Now [0]) {
          if (($this->TimerNext !== null) && (($this->TimerNext [0] == $Current [0]) || ($this->TimerNext [0] > $Sec))) {
            reset ($this->Timers);
            $this->TimerNext = array (key ($this->Timers), key ($Timers));
          }
          
          break;
        }
        
        foreach ($Timers as $USec=>$Events) {
          // Get the current time
          $Now = $this->getTimer ();
          
          if ($USec > $Now [1]) {
            $this->TimerNext [1] = $USec;
            
            break (2);
          }
          
          unset ($this->Timers [$Sec][$USec]);
          
          // Run all events
          foreach ($Events as $Event) {
            // Run the callback
            if ($Event [3] !== null) {
              if (!is_array ($Event [3]) || ($Event [3][0] !== $Event [0]))
                call_user_func ($Event [3], $Event [0], $Event [4]);
              else
                call_user_func ($Event [3], $Event [4]);
            
            // ... or raise the event
            } else
              $Event [0]->raiseTimer ();
            
            // Check wheter to repeat
            if ($Event [2])
              $this->addTimer ($Event [0], $Event [1], $Event [2], $Event [3], $Event [4]);
          }
        }
        
        // Remove the second if all timers were fired
        if (isset ($this->Timers [$Sec]) && (count ($this->Timers [$Sec]) == 0))
          unset ($this->Timers [$Sec]);
      }
      
      // Check wheter to dequeue the timer
      if (count ($this->Timers) == 0)
        $this->TimerNext = null;
      elseif (($this->TimerNext [0] == $Current [0]) &&
              ($this->TimerNext [1] == $Current [1])) {
        reset ($this->Timers);
        
        $this->TimerNext = array (
          key ($this->Timers),
          key (current ($this->Timers))
        );
      }
    }
    // }}}
  }

?>