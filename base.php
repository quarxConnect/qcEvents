<?PHP

  /**
   * Event-Base
   * ----------
   * Main-Interface to our event-handler
   * 
   * @class phpEvents_Base
   * @package phpEvent
   * @revision 01
   * @author Bernd Holzmueller <bernd@tiggerswelt.net>
   **/
  class phpEvents_Base {
    // libEvent-Support
    private $evBase = null;
    
    // List of associated events
    private $Events = array ();
    
    private $FDs = array ();
    private $readFDs = array ();
    private $writeFDs = array ();
    
    // Loop-Handling
    private $loopBreak = false;
    private $loopExit = false;
    
    private $loopReadFDs = array ();
    private $loopWriteFDs = array ();
    
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
      } else {
        $fd = $Event->getFD ();
        
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
      $Event->setHandler ($this);
      
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
      
      if (!self::checkLibEvent ()) {
        $fd = $Event->getFD ();
      
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
      // Handle libEvent-Support
      if (self::checkLibEvent ())
        return (event_base_loop ($this->evBase) == 0);
      
      // Reset loop-status
      $this->loopExit = false;
      
      while (!($this->loopExit || $this->loopBreak))
        self::loopOnceInternal (100000);
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
      // Handle libEvent-Support
      if (self::checkLibEvent ())
        return (event_base_loop ($this->evBase, EVLOOP_ONCE) == 0);
      
      self::loopOnceInternal ($Timeout);
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
      if (stream_select ($readFDs, $writeFDs, $n, 0, $Timeout) == 0)
        return;
      
      // Handle the events
      $this->loopBreak = false;
      
      foreach ($readFDs as $fd)
        foreach ($this->readFDs [$fd] as $Event) {
          $Event->readEvent ();
          
          if ($this->loopBreak)
            return;
        }
      
      foreach ($writeFDs as $fd)
        foreach ($this->writeFDs [$fd] as $Event) {
          $Event->writeEvent ();
          
          if ($this->loopBreak)
            return;
        }
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
    
    // {{{ checkLibEvent
    /**
     * Check wheter php was compiled with libevent-Support
     * 
     * @access public
     * @return bool
     **/
    public static function checkLibEvent () {
      // Check if a previous result was cached
      if (!defined ("PHPEVENT_LIBEVENT")) {
        if (function_exists ("event_set"))
          define ("PHPEVENT_LIBEVENT", true);
        else
          define ("PHPEVENT_LIBEVENT", false);
      }
      
      // Return the cached result
      return PHPEVENT_LIBEVENT;
    }
    // }}}
  }

?>