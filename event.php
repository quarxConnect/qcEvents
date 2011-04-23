<?PHP

  require_once ('phpEvents/base.php');
  
  /**
   * Event
   * -----
   * Event-object
   * 
   * @class phpEvents_Event
   * @package phpEvent
   * @revision 01   
   * @author Bernd Holzmueller <bernd@tiggerswelt.net>
   **/
  class phpEvents_Event {
    const EVENT_READ = 0;
    const EVENT_WRITE = 1;
    const EVENT_TIMER = 2;
    
    // libEvent-Support
    private $evPtr = null;
    
    // Informations on this event
    private $fd = null;
    private $monitorRead = false;
    private $monitorWrite = false;
    private $Callback = null;
    
    // Information about our status and parent
    private $Bound = false;
    private $Handler = null;
    
    // {{{ __construct
    /**
     * Create a new Event-Handler
     * 
     * @param resource $fd
     * @param bool $monitorRead
     * @param bool $monitorWrite
     * @param callback $Callback
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($fd, $monitorRead, $monitorWrite, $Callback = null) {
      // Validate our parameters
      if (!($monitorRead || $monitorWrite))
        throw new Exception ('Nothing to monitor');
      
      if (!is_resource ($fd))
        throw new Exception ('Invalid fd');
      
      if ($Callback !== null)
        $this->setCallback ($Callback);
      
      if (!$this->setFD ($fd, $monitorRead, $monitorWrite))
        throw new Exception ('Could not create event');
    }
    // }}}
    
    // {{{ setFD
    /**
     * Set the file-descriptor to monitor
     * 
     * @param resource $fd
     * @param bool $monitorRead (optional) Monitor read-events (default)
     * @param bool $monitorWrite (optional) Monitor write-events
     * 
     * @access public
     * @return bool
     **/
    public function setFD ($fd, $monitorRead = true, $monitorWrite = false) {
      // Cleanup first
      if ($wasBound = $this->isBound ())
        $this->unbind ();
      
      // Check wheter FD is a valid resource
      if (!is_resource ($fd))
        return false;
      
      // Handle libEvent-Support
      if (phpEvents_Base::checkLibEvent ()) {
        if (!is_resource ($this->evPtr) && !is_resource ($this->evPtr = event_new ()))
          return false;
        
        $flags = ($monitorRead ? EV_READ : 0) |
                 ($monitorWrite ? EV_WRITE : 0) |
                 EV_PERSIST;
        
        if ($wasBound)
          $this->bind ();
        
        return event_set ($this->evPtr, $fd, $flags, array ($this, 'evCallback'), null);
      } 
      
      // Store informations in our object   
      $this->fd = $fd;
      $this->monitorRead = $monitorRead;
      $this->monitorWrite = $monitorWrite;
      
      if ($wasBound)
        $this->bind ();
      
      return true;
    }
    // }}}
    
    // {{{ setCallback
    /**
     * Store a new callback-function
     * 
     * @param callback $Callback
     * 
     * @access public
     * @return bool
     **/
    public function setCallback ($Callback) {
      // Make sure we don't store crap
      if (!is_callable ($Callback))
        return false;
      
      $this->Callback = $Callback;
      
      return true;
    }
    // }}}
    
    // {{{ getPtr
    /**
     * Retrive libEvent-Pointer
     * 
     * @access public
     * @return resource
     **/
    public function getPtr () {
      return $this->evPtr;
    }
    // }}}
    
    // {{{ getFD
    /**
     * Retrive our file-descriptor
     * 
     * @access public
     * @return resource
     **/
    public function getFD () {
      return $this->fd;
    }
    // }}}
    
    // {{{ getRead
    /**
     * Check if we are catching read-events
     * 
     * @access public
     * @return bool
     **/
    public function getRead () {
      return $this->monitorRead;
    }
    // }}}
    
    // {{{ getWrite
    /**
     * Check if we are catching write-events
     * 
     * @access public
     * @return bool
     **/
    public function getWrite () {
      return $this->monitorWrite;
    }
    // }}}
    
    // {{{ isBound
    /**
     * Check wheter this event is bound to a base
     * 
     * @access public
     * @return bool
     **/
    public function isBound () {
      return $this->Bound;
    }
    // }}}
    
    // {{{ bind
    /**
     * Add this event to an event-base
     * 
     * @access public
     * @return bool
     **/
    public function bind () {
      // Don't bind twice
      if ($this->isBound ())
        return true;
      
      // Make sure we have a base assigned
      if (!is_object ($this->Handler))
        return false;
      
      return ($this->Bound = $this->Handler->addEvent ($this));
    }
    // }}}
    
    // {{{ unbind
    /**
     * Remove this event from an event-base
     * 
     * @access public
     * @return bool
     **/
    public function unbind () {
      // Check if this event is unbound
      if (!$this->Bound)
        return true;
      
      // Handle libEvent-Support
      if (phpEvents_Base::checkLibEvent () && !event_del ($this->evPtr))
        return false;
      
      // Now really unbind
      $this->Bound = false;
      
      if (is_object ($this->Handler))
        $this->Handler->removeEvent ($this);
      
      return true;
    }
    // }}}
    
    // {{{ setHandler
    /**
     * Store handle of our event-base
     * 
     * @param object $Handler
     * 
     * @access public
     * @return bool
     **/
    public function setHandler ($Handler, $markBound = false) {
      if (!($Handler instanceof phpEvents_Base))
        return false;
      
      if (!$Handler->haveEvent ($this))
        return false;
      
      $this->Handler = $Handler;
      
      if ($markBound)
        $this->Bound = true;
      
      return true;
    }
    // }}}
    
    // {{{ getHandler
    /**
     * Retrive handle of the event-base we are assigned to
     * 
     * @access public
     * @return object
     **/
    public function getHandler () {
      return $this->Handler;
    }
    // }}}
    
    // {{{ evCallback
    /**
     * Callback function for libEvent-Support
     * 
     * @access public
     * @return void
     **/ 
    public function evCallback ($fd, $event, $arg) {
      if ($event == EV_READ)
        $this->readEvent ();
      elseif ($event == EV_WRITE)
        $this->writeEvent ();
      else
        trigger_error ('Unknown Event ' . $event, E_USER_NOTICE);
    }
    // }}}
    
    // {{{ readEvent
    /**
     * Handle a read-event
     * 
     * @access public
     * @return void
     **/
    public function readEvent () {
      if ($this->Callback !== null)
        call_user_function ($this->Callback, $this, self::EVENT_READ);
    }
    // }}}
    
    // {{{ writeEvent
    /**
     * Handle a write-event
     * 
     * @access public
     * @return void
     **/
    public function writeEvent () {
      if ($this->Callback !== null)
        call_user_function ($this->Callback, $this, self::EVENT_WRITE);
    }
    // }}}
    
    // {{{ timerEvent
    /**
     * Handle a timer-event
     * 
     * @access public
     * @return void
     **/
    public function timerEvent () {
      if ($this->Callback !== null)
        call_user_function ($this->Callback, $this, self::EVENT_TIMER);
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
      if (is_object ($this->Handler))
        $this->Handler->loopOnce ($Timeout);
    }
    // }}}
    
    // {{{ forceOnNextIteration
    /**
     * Force to raise a given event on next iteration
     * 
     * @access public
     * @return bool
     **/
    public function forceOnNextIteration ($Event = self::EVENT_TIMER) {
      // Check if we have a parent assigned
      if (!is_object ($this->Handler))
        return false;
      
      // Queue the event on our parent
      return $this->Handler->queueForNextIteration ($this, $Event);
    }
    // }}}
  }

?>