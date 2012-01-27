<?PHP

  /**
   * Event-Base
   * ----------
   * Main-Interface to our event-handler
   * 
   * @class qcEvents_Base
   * @package qcEvents
   * @revision 01
   * @author Bernd Holzmueller <bernd@quarxconnect.de>
   * @license http://creativecommons.org/licenses/by-sa/3.0/de/ Creative Commons Attribution-Share Alike 3.0 Germany
   **/
  class qcEvents_Base {
    // libEvent-Support
    private $evBase = null;
    
    // List of associated events
    private $Events = array ();
    private $forceEvents = array ();
    
    private $FDs = array ();
    private $readFDs = array ();
    private $writeFDs = array ();
    
    // Loop-Handling
    private $onLoop = false;
    private $loopBreak = false;
    private $loopExit = false;
    private $loopContinue = false;
    
    private $loopReadFDs = array ();
    private $loopWriteFDs = array ();
    
    // Signal-Handling
    private $handleSignals = null;
    
    // Timeouts
    private $timeoutNext = 0;
    private $timeoutEvents = array ();
    private $timeoutSettings = array ();
    private $timeoutTimer = array ();
    
    // {{{ __construct
    /**
     * Create a new event-base
     * 
     * @access friendly
     * @return void
     **/
    function __construct () {
      // Handle libEvent-Support
      if (self::checkLibEvent ()) {
        if (!is_resource ($this->evBase = event_base_new ()))
          throw new Exception ("Could not create event-base");
      
      // Run our own implementation
      } else {
        # TODO?
      }
      
      $this->handleSignals = extension_loaded ('pcntl');
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
      // Remove the event from its previous base
      $Event->unbind ();
      
      // Append the Event to our ones
      $this->Events [] = $Event;
      
      // Handle libEvent-Support
      if (self::checkLibEvent ()) {
        $ptr = $Event->getPtr ();
        
        return event_base_set ($ptr, $this->evBase) &&
               event_add ($ptr);
      
      // Handle our own implementation
      } elseif (is_resource ($fd = $Event->getFD ())) {
        if ($Event->getRead ()) {
          if (!isset ($this->readFDs [$fd]))
            $this->readFDs [$fd] = array ();
          
          $this->readFDs [$fd][] = $Event;
          $this->loopReadFDs [$fd] = $fd;
        }
        
        if ($Event->getWrite ()) {
          if (!isset ($this->writeFDs [$fd]))
            $this->writeFDs [$fd] = array ();
          
          $this->writeFDs [$fd][] = $Event;
          $this->loopWriteFDs [$fd] = $fd;
        }
        
        $this->FDs [$fd] = $fd;
      }
      
      // Register ourself on the event
      $Event->setHandler ($this, true);
      
      return true;
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
      
      if (!self::checkLibEvent () && is_resource ($fd = $Event->getFD ())) {
        if ($Event->getRead ()) {
          if (count ($this->readFDs [$fd]) == 1) {
            unset ($this->readFDs [$fd]);
            unset ($this->loopReadFDs [$fd]);
          } elseif (($k = array_search ($Event, $this->readFDs [$fd])) !== false)
            unset ($this->readFDs [$fd][$k]);
        }
        
        if ($Event->getWrite ()) {
          if (count ($this->writeFDs [$fd]) == 1) {
            unset ($this->writeFDs [$fd]);
            unset ($this->loopWriteFDs [$fd]);
          } elseif (($k = array_search ($Event, $this->writeFDs [$fd])) !== false)
            unset ($this->writeFDs [$fd][$k]);
        }
        
        unset ($this->FDs [$fd]);
      }
      
      unset ($this->Events [$key]);
      $Event->unbind ();
      
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
      return in_array ($Event, $this->Events);
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
      
      while ($this->loopContinue) {
        // Reset loop-status
        $this->loopExit = false;
        $this->loopContinue = false;
        
        // Run queued events first
        $this->runQueuedEvents ();
        
        // Enter the loop
        while (!($this->loopExit || $this->loopBreak)) {
          $rc = self::loopOnceInternal (100000);
        }
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
      // Retrive a list of FDs to poll
      $readFDs = $this->loopReadFDs;
      $writeFDs = $this->loopWriteFDs;
      $n = null;
      
      // Wait for events
      if (@stream_select ($readFDs, $writeFDs, $n, 0, $Timeout) == 0) {
        $this->signalHandler ();
        
        return null;
      }
      
      $this->signalHandler ();
      
      // Handle the events
      $this->loopBreak = false;
      
      foreach ($readFDs as $fd)
        foreach ($this->readFDs [$fd] as $Event) {
          $Event->readEvent ();
          
          if ($this->loopBreak)
            return false;
        }
      
      foreach ($writeFDs as $fd)
        foreach ($this->writeFDs [$fd] as $Event) {
          $Event->writeEvent ();
          
          if ($this->loopBreak)
            return false;
        }
      
      return true;
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
      if ($this->handleSignals === true)
        pcntl_signal_dispatch ();
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
      foreach ($this->forceEvents as $ID=>$Ev) {
        // Run the event
        switch ($Ev [1]) {
          case qcEvents_Event::EVENT_READ:
            $Ev [0]->readEvent ();
            break;
          
          case qcEvents_Event::EVENT_WRITE:
            $Ev [0]->writeEvent ();
            break;
            
          case qcEvents_Event::EVENT_TIMER:
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
      if (!isset ($this->timeoutTimer [$this->timeoutNext]))
        return $this->setTimer ();
      
      // Handle the events
      $Events = $this->timeoutTimer [$this->timeoutNext];
      unset ($this->timeoutTimer [$this->timeoutNext]);
      
      $S = false;
      
      foreach ($Events as $Key) {
        // Run the event
        if ($this->timeoutSettings [$Key][3] !== null)
          call_user_func ($this->timeoutSettings [$Key][3]);
        else
          $this->timeoutEvents [$Key]->timerEvent ();
        
        // Requeue the event
        if ($this->timeoutSettings [$Key][2]) {
          $this->timeoutSettings [$Key][0] = $N = time () + $this->timeoutSettings [$Key][1];
          $this->timeoutTimer [$N][$Key] = $Key;
          $S = true;
        
        // Remove any reference
        } else
          unset ($this->timeoutEvents [$Key], $this->timeoutSettings [$Key]);
      }
      
      if ($S)
        ksort ($this->timeoutTimer, SORT_NUMERIC);
      
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
    public function queueForNextIteration ($Handle, $Event = qcEvents_Event::EVENT_TIMER) {
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
     * 
     * @access public
     * @return bool
     **/
    public function addTimeout ($Event, $Timeout, $Repeat = false, $Callback = null) {
      // Register this event as timed event
      if (($key = array_search ($Event, $this->timeoutEvents)) === false) {
        $this->timeoutEvents [] = $Event;
        
        if (($key = array_search ($Event, $this->timeoutEvents)) === false)
          return false;
      
      // Check the store settings
      } elseif (($N = $this->timeoutSettings [$key][0]) >= time ()) {
        unset ($this->timeoutTimer [$N][$key]);
        
        if (($N == $this->timeoutNext) && (count ($this->timeoutTimer [$N]) == 0))
          $this->timeoutNext = 0;
      }
      
      // Calculate
      $T = time ();
      $N = $T * $Timeout;
      
      // Register the event
      if (($Callback !== null) && !is_callable ($Callback))
        $Callback = null;
      
      $this->timeoutSettings [$key] = array ($N, $Timeout, $Repeat, $Callback);
      $this->timeoutTimer [$N][$key] = $key;
      ksort ($this->timeoutTimer, SORT_NUMERIC);
      
      // Setup the timer
      if (($this->timeoutNext < $T) || ($this->timeoutNext > $N)) {
        $this->timeoutNext = $N;
        
        pcntl_signal (SIGALRM, array ($this, 'timerEvent'));
        pcntl_alarm ($Timeout);
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
      // Don't do anything if there are no events
      if (count ($this->timeoutTimer) == 0)
        return;
      
      // Find next timestamp
      $T = time ();
      
      foreach ($this->timeoutTimer as $Next=>$Keys)
        if ($Next < $T)
          unset ($this->timeoutTimer [$Next]);
        else
          break;
      
      if ($Next < $T)
        return;
      
      // Set the timer
      $this->timeoutNext = $Next;
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