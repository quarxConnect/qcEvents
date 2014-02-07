<?PHP

  /**
   * qcEvents - Base Event Handler
   * Copyright (C) 2012 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Interface.php');
  
  /**
   * Event-Base
   * ----------
   * Main-Interface to our event-handler
   * 
   * @class qcEvents_Base
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Base {
    // libEvent-Support
    private $evBase = null;
    
    /* List of all events on this handler */
    private $Events = array ();
    
    /* List of events that should run on the next iteration */
    private $forceEvents = array ();
    
    /* Queued FDs */
    private $readFDs = array ();
    private $writeFDs = array ();
    private $errorFDs = array ();
    private $eventFDs = array ();
    private $fdMap = array ();
    
    // Loop-Handling
    private $onLoop = false;
    private $loopBreak = false;
    private $loopExit = false;
    private $loopContinue = false;
    private $loopEmpty = false;
    
    // Signal-Handling
    private $handleSignals = null;
    
    // Timeouts
    private $timeoutNext = 0;
    private $timeoutEvents = array ();
    
    // {{{ __construct
    /**
     * Create a new event-base
     * 
     * @access friendly
     * @return void
     **/
    function __construct () {
      // Handle libEvent-Support
      if (self::checkLibEvent () && !is_resource ($this->evBase = event_base_new ()))
        throw new Exception ("Could not create event-base");
      
      // Check if we may handle signals
      if (!($this->handleSignals = extension_loaded ('pcntl')))
        trigger_error ('No PCNTL Extension found, using manual timer-events', E_USER_WARNING);
    }
    // }}}
    
    // {{{ addEvent
    /**
     * Append an event to our queue
     * 
     * @param object $Event
     * 
     * @access public
     * @return bool
     **/
    public function addEvent ($Event) {
      // Make sure this is a valid Event
      if (!($Event instanceof qcEvents_Interface))
        return false;
      
      // Remove the event from its previous base
      $Index = false;
      
      if ($Event->haveEventBase ()) {
        if ($Event->getEventBase () === $this)
          $Index = array_search ($Event, $this->Events, true);
        else
          $Event->unbind ();
      }
      
      // Append the Event to our ones
      if ($Index === false) {
        $this->Events [] = $Event;
        
        // Register ourself on the event   
        $Event->setEventBase ($this, true);
        
        // Retrive internal ID of this event
        $Index = array_search ($Event, $this->Events, true);
      }
      
      if (is_resource ($fd = $Event->getFD ()))
        $this->updateEventFD ($Index, $fd);
      
      return true;
    }
    // }}}
    
    // {{{ updateEvent
    /**
     * Update the watched events for a given handle
     * 
     * @param object $Event
     * 
     * @access public
     * @return void
     **/
    public function updateEvent ($Event) {
      // Lookup the event
      if (($key = array_search ($Event, $this->Events)) === false)
        return false;
      
      return $this->updateEventFD ($key);
    }
    // }}}
    
    // {{{ updateEventFD
    /**
     * Update the event-watchers for a given event
     * 
     * @param int $Index
     * @param resource $fd (optional)
     * 
     * @access private
     * @return void
     **/
    private function updateEventFD ($Index, $fd = null) {
      // Make sure the event is available
      if (!isset ($this->Events [$Index]))
        return false;
      
      // Make sure we have an fd or clean up and exit
      if (!is_resource ($fd) && !is_resource ($fd = $this->Events [$Index]->getFD ())) {
        if (isset ($this->eventFDs [$Index]) && is_resource ($this->eventFDs [$Index]))
          event_del ($this->eventFDs [$Index]);
        
        // Sanity-Check FD-Map
        foreach (array_keys ($this->fdMap, $Index) as $k)
          unset ($this->fdMap [$k]);
        
        // Remove all stored FDs
        unset ($this->readFDs [$Index], $this->writeFDs [$Index], $this->errorFDs [$Index], $this->eventFDs [$Index]);
        
        return false;
      }
      
      // Handle libEvent-Support
      if (self::checkLibEvent ()) {
        // Make sure we have an libevent-Event available
        if ((!isset ($this->eventFDs [$Index]) || !is_resource ($this->eventFDs [$Index])) &&
            !($this->eventFDs [$Index] = event_new ()))
          return false;
        
        // Generate flags for libevent
        $flags = ($this->Events [$Index]->watchRead () ? EV_READ : 0) |
                 ($this->Events [$Index]->watchWrite () ? EV_WRITE : 0) |
                 EV_PERSIST;
        
        // Update the Event
        event_set ($this->eventFDs [$Index], $fd, $flags, array ($this, 'libeventHandler'), $Index);
        
      // Handle our own implementation
      } else {
        if ($this->Events [$Index]->watchRead ())
          $this->readFDs [$Index] = $fd;
        else
          unset ($this->readFDs [$Index]);
        
        if ($this->Events [$Index]->watchWrite ())
          $this->writeFDs [$Index] = $fd;
        else
          unset ($this->writeFDs [$Index]);
        
        if ($this->Events [$Index]->watchError ())
          $this->errorFDs [$Index] = $fd;
        else
          unset ($this->errorFDs [$Index]);
        
        $this->fdMap [(int)$fd] = $Index;
      }
    }
    // }}}
    
    // {{{ removeEvent
    /**
     * Remove an event from our list of events
     * 
     * @param object $Event
     * 
     * @access public
     * @return bool - Always true ;)
     **/
    public function removeEvent ($Event) {
      // Lookup the event
      if (($key = array_search ($Event, $this->Events)) === false)
        return true;
      
      // Remove all references
      if (isset ($this->eventFDs [$key]) && is_resource ($this->eventFDs [$key]))
        event_del ($this->eventFDs [$key]);
      
      unset ($this->Events [$key], $this->readFDs [$key], $this->writeFDs [$key], $this->errorFDs [$key], $this->eventFDs [$key]);
      
      // Remove any queued timers
      foreach ($this->timeoutEvents as $TI=>$Events) {
        foreach ($Events as $EI=>$EvInfo)
          if ($EvInfo [0] == $Event) {
            unset ($this->timeoutEvents [$TI][$EI]);
            
            if (count ($this->timeoutEvents [$TI]) == 0) {
              unset ($this->timeoutEvents [$TI]);
              
              if ($this->timeoutNext == $TI)
                $this->timeoutNext = null;
            }
          }
      }
      
      if ((count ($this->timeoutEvents) > 0) && ($this->timeoutNext === null))
        $this->setTimer ();
      
      // Tell the event that it has to unbind
      $Event->unbind ();
      
      return true;
    }
    // }}}
    
    // {{{ getEvents
    /**
     * Retrive all registered events for this handler
     * 
     * @access public
     * @return array
     **/
    public function getEvents () {
      return $this->Events;
    }
    // }}}
    
    // {{{ haveEvents
    /**
     * Check if there are events registered on this base
     * 
     * @access public
     * @return bool
     **/
    public function haveEvents () {
      return ((count ($this->readFDs) > 0) || (count ($this->writeFDs) > 0) && (count ($this->errorFDs) > 0));
    }
    // }}}
    
    // {{{ quitOnEmpty
    /**
     * Leave the loop if there are no events on the queue
     * 
     * @param bool $Trigger (optional) Set the condition
     * 
     * @access public
     * @return bool
     **/
    public function quitOnEmpty ($Trigger = null) {
      if ($Trigger === null)
        return !$this->loopEmpty;
      
      $this->loopEmpty = !$Trigger;
      
      return true;
    }
    // }}}
    
    // {{{ haveEvent
    /**
     * Check if we have a given event registered
     * 
     * @param object $Event
     * 
     * @access public
     * @return bool
     **/
    public function haveEvent ($Event) {
      return in_array ($Event, $this->Events, true);
    }
    // }}}
    
    // {{{ loop
    /**
     * Enter the mainloop
     * 
     * @access public
     * @return void
     **/
    public function loop () {
      // Remember that we are on loop
      $this->onLoop = true;
      $this->loopContinue = true;
      
      // Check if there are any events queued
      if ((count ($this->readFDs) == 0) && (count ($this->writeFDs) == 0) && (count ($this->errorFDs) == 0) && (count ($this->timeoutEvents) == 0)) {
        if (!$this->loopEmpty)
          return;
        
        trigger_error ('Entering Event-Loop without FDs');
      }
      
      // Handle libEvent-Support
      if (self::checkLibEvent ()) {
        $rc = 0;
        
        while ($this->loopContinue && ($rc == 0)) {
          // Reset loop-status
          $this->loopContinue = false;
          
          // Run queued events first
          $this->runQueuedEvents ();
          
          // Enter the loop
          $rc = event_base_loop ($this->evBase);
        }
        
        $this->onLoop = false;
        
        return ($rc == 0);
      }
      
      $this->loopExit = false;
      $this->loopBreak = false;
      $rc = null;
      
      while ($this->loopContinue) {
        // Reset loop-status
        $this->loopContinue = false;
        
        // Run queued events first
        $this->runQueuedEvents ();
        
        // Enter the loop
        while (!($this->loopExit || $this->loopBreak))
          if (($rc = self::loopOnceInternal (100000)) === false)
            break;
      }
      
      $this->onLoop = false;
      
      return $rc;
    }
    // }}}
    
    // {{{ loopOnce
    /**
     * Run the main-loop once
     * 
     * @param int $Timeout (optional) Timeout for our own implementation
     * 
     * @access public
     * @return void
     **/
    public function loopOnce ($Timeout = 250) {
      // Run queued events first
      $this->runQueuedEvents ();
      
      // Handle libEvent-Support
      if (self::checkLibEvent ())
        return (event_base_loop ($this->evBase, EVLOOP_ONCE) == 0);
      
      return self::loopOnceInternal ($Timeout);
    }
    // }}}
    
    // {{{ loopOnceInternal
    /**
     * Poll our registered events for changes
     * 
     * @param int $Timeout
     * 
     * @access private
     * @return void
     **/
    private function loopOnceInternal ($Timeout) {
      // Copy arrays for select()
      $readFDs = $this->readFDs;
      $writeFDs = $this->writeFDs;
      $errorFDs = $this->errorFDs;
      
      // Check if we should run stream select
      if ((count ($readFDs) == 0) && (count ($writeFDs) == 0) && (count ($errorFDs) == 0)) {
        usleep ($Timeout);
        
        $this->signalHandler ();
        
        return (count ($this->timeoutEvents) > 0 ? null : false);
      }
      
      // Wait for events
      $Count = @stream_select ($readFDs, $writeFDs, $errorFDs, 0, $Timeout);
      $this->signalHandler ();
      
      if ($Count == 0)  
        return null;
      
      // Handle the events
      $this->loopBreak = false;
      
      foreach ($readFDs as $FD) {
        $Index = $this->fdMap [(int)$FD];
        
        if (!isset ($this->Events [$Index]))
          continue;
        
        $this->Events [$Index]->readEvent ();
        
        if ($this->loopBreak)
          return false;
      }
      
      foreach ($writeFDs as $FD) {
        $Index = $this->fdMap [(int)$FD];
        
        if (!isset ($this->Events [$Index]))
          continue;
        
        $this->Events [$Index]->writeEvent ();
        
        if ($this->loopBreak)
          return false;
      }
      
      foreach ($errorFDs as $FD) {
        $Index = $this->fdMap [(int)$FD];
        
        if (!isset ($this->Events [$Index]))
          continue;
        
        $this->Events [$Index]->errorEvent ();
        
        if ($this->loopBreak)
          return false;
      }
      
      return true;
    }
    // }}}
    
    // {{{ libeventHandler
    /**
     * Handle an incoming callback from libevent
     * 
     * @param resource $fd
     * @param enum $Event
     * @param int $arg
     * 
     * @access public
     * @return void
     **/
    public function libeventHandler ($fd, $Event, $arg) {
      // Check if the given event exists
      if (!isset ($this->Events [$arg])) {
        trigger_error ('Event for non-existing handle captured', E_USER_NOTICE);
        
        return;
      }
      
      // Handle the event
      if (($Event & EV_READ) == EV_READ)
        $this->Events [$arg]->readEvent ();
      
      if (($Event & EV_WRITE) == EV_WRITE)
        $this->Events [$arg]->writeEvent ();
      
      if (($Event & EV_TIMEOUT) == EV_TIMEOUT)
        $this->Events [$arg]->errorEvent ();
    }
    // }}}
    
    // {{{ signalHandler
    /**
     * Handle pending signals
     * 
     * @access private
     * @return void
     **/
    private function signalHandler () {
      // Let PCNTL handle the events
      if ($this->handleSignals === true)
        return pcntl_signal_dispatch ();
      
      // Do it manual
      while (($this->timeoutNext > 0) && ($this->timeoutNext <= time ()))
        $this->timerEvent (SIGALRM);
    }
    // }}}
    
    // {{{ runQueuedEvents
    /**
     * Handle queued events
     * 
     * @access private
     * @return void
     **/
    private function runQueuedEvents () {
      // Check if there are any events pending
      if (count ($this->forceEvents) == 0)
        return null;
      
      // Run all pending events
      $this->loopBreak = false;
      
      foreach ($this->forceEvents as $ID=>$Ev) {
        // Run the event
        switch ($Ev [1]) {
          case qcEvents_Interface::EVENT_READ:
            $Ev [0]->readEvent ();
            break;
          
          case qcEvents_Interface::EVENT_WRITE:
            $Ev [0]->writeEvent ();
            break;
          
          case qcEvents_Interface::EVENT_ERROR:
            $Ev [0]->errorEvent ();
            break;
          
          case qcEvents_Interface::EVENT_TIMER:
            $Ev [0]->timerEvent ();
            break;
        }
        
        unset ($this->forceEvents [$ID]);
        
        // Check wheter to quit here
        if ($this->loopBreak)
          return false;
      }
      
      return true;
    }
    // }}}
    
    // {{{ timerEvent
    /**
     * Handler for ALARM-Signals
     * 
     * @param int $Signal
     * 
     * @access public
     * @return void
     **/
    public function timerEvent ($Signal) {
      // Make sure its an Alarm
      if ($Signal != SIGALRM)
        return;
      
      // Make sure we have events
      if (!isset ($this->timeoutEvents [$this->timeoutNext]))
        return $this->setTimer ();
      
      // Handle the events
      $Events = $this->timeoutEvents [$this->timeoutNext];
      unset ($this->timeoutEvents [$this->timeoutNext]);
      
      $S = false;
      $T = time ();
      
      foreach ($Events as $Event) {
        // Run the event
        if ($Event [3] !== null)
          call_user_func ($Event [3], $Event [4]);
        else
          $Event [0]->timerEvent ();
        
        // Requeue the event
        if ($Event [2]) {
          $N = $T + $Event [1];
          
          if (!isset ($this->timeoutEvents [$N])) {
            $this->timeoutEvents [$N] = array ($Event);
            $S = true;
          } else
            $this->timeoutEvents [$N][] = $Event;
        }
      }
      
      if ($S)
        ksort ($this->timeoutEvents, SORT_NUMERIC);
      
      return $this->setTimer ();
    }
    // }}}
    
    // {{{ loopBreak
    /**
     * Leave the current loop immediatly
     * 
     * @access public
     * @return void
     **/
    public function loopBreak () {
      if (self::checkLibEvent ())
        return event_base_loopbreak ($this->evBase);
      
      return ($this->loopBreak = true);
    }
    // }}}
    
    // {{{ loopExit
    /**
     * Let the current loop finish, then exit
     * 
     * @access public
     * @return void
     **/
    public function loopExit () {
      if (self::checkLibEvent ()) 
        return event_base_loopexit ($this->evBase);
      
      return ($this->loopExit = true);
    }
    // }}}
    
    // {{{ queueForNextIteration
    /**
     * Force to raise an event on our next iteration
     * 
     * @param object $Handle
     * @param enum $Event
     * 
     * @access public
     * @return bool
     **/
    public function queueForNextIteration ($Handle, $Event = qcEvents_Interface::EVENT_TIMER) {
      // Check if this event belongs to us
      if (!$this->haveEvent ($Handle))
        return false;
      
      // Make sure that a main-loop is continued
      if ($this->onLoop)
        $this->loopContinue = true;
      
      // Queue the event
      $this->forceEvents [] = array ($Handle, $Event);
      
      // Stop the current loop after it was finished
      $this->loopExit ();
      
      return true;
    }
    // }}}
    
    // {{{ addTimeout
    /**
     * Add create a timer for an event-object
     * 
     * @param object $Event The event itself
     * @param int $Timeout How many seconds to wait
     * @param bool $Repeat (optional) Keep the timeout
     * @param callback $Callback (optional) Use this function as Callback
     * @param mixed $Private (optional) Private data passed to the callback
     * 
     * @access public
     * @return bool
     **/
    public function addTimeout ($Event, $Timeout, $Repeat = false, $Callback = null, $Private = null) {
      // Check the callback
      if (($Callback !== null) && !is_callable ($Callback))
        $Callback = null;
      
      // Calculate 
      $T = time ();
      $N = $T + $Timeout;
      
      if (!isset ($this->timeoutEvents [$N])) {
        $this->timeoutEvents [$N] = array (array ($Event, $Timeout, $Repeat, $Callback, $Private));
        
        ksort ($this->timeoutEvents, SORT_NUMERIC);
      } else
        $this->timeoutEvents [$N][] = array ($Event, $Timeout, $Repeat, $Callback, $Private);
      
      // Setup the timer
      if (($this->timeoutNext < $T) || ($this->timeoutNext > $N)) {
        $this->timeoutNext = $N;
        
        if ($this->handleSignals) {
          pcntl_signal (SIGALRM, array ($this, 'timerEvent'));
          pcntl_alarm ($Timeout);
        }
      }
      
      return true;
    }
    // }}}
    
    // {{{ setTimer
    /**
     * Make sure that the timer is set to the next timer-event
     * 
     * @access private
     * @return void
     **/
    private function setTimer () {
      // Reset the next timeout
      $this->timeoutNext = 0;
      
      // Don't do anything if there are no events
      if (count ($this->timeoutEvents) == 0)
        return;
      
      // Find next timestamp
      $T = time ();
      
      foreach ($this->timeoutEvents as $Next=>$Keys)
        if (($Next < $T) && $this->handleSignals)
          unset ($this->timeoutEvents [$Next]);
        else
          break;
      
      if ($Next < $T)
        return;
      
      // Set the timer
      $this->timeoutNext = $Next;
      
      if ($this->handleSignals)
        pcntl_alarm ($Next - $T);
    }
    // }}}
    
    // {{{ checkLibEvent
    /**
     * Check wheter php was compiled with libevent-Support
     * 
     * @access public
     * @return bool
     **/
    public static function checkLibEvent () {
      // Check if a previous result was cached
      if (!defined ('PHPEVENT_LIBEVENT')) {
        if (function_exists ('event_set'))
          define ('PHPEVENT_LIBEVENT', true);
        else
          define ('PHPEVENT_LIBEVENT', false);
      }
      
      // Return the cached result
      return PHPEVENT_LIBEVENT;
    }
    // }}}
  }

?>