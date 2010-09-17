<?PHP

  require_once ('phpEvents/event.php');
  
  /**
   * Timer Event
   * -----------
   * Event-Object for timed tasks
   * 
   * @class phpEvents_Timer
   * @package phpEvents
   * @revision 01
   * @author Bernd Holzmueller <bernd@tiggerswelt.net>
   **/
  class phpEvents_Timer extends phpEvents_Event {
    private $Timeout = 0;
    private $Repeat = false;
    
    // {{{ __construct
    /**
     * Create a new timer-event
     * 
     * @param int $Timeout (optional) Timeout in seconds
     * @param bool $Repeat (optional) Repeat this event continously
     * @param callback $Callback (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($Timeout = null, $Repeat = false, $Callback = null) {
      // Store the callback
      if ($Callback !== null)
        $this->setCallback ($Callback);
      
      // Set the timeout
      if ($Timeout !== null)
        $this->setTimeout ($Timeout, $Repeat);
    }
    // }}}
    
    // {{{ setTimeout
    /**
     * Set a timeout for this event
     * 
     * @param int $Timeout
     * @param bool $Repeat (optional) Repeat this event
     * 
     * @access public
     * @return bool
     **/
    public function setTimeout ($Timeout, $Repeat = false) {
      // Store these values internally
      $this->Timeout = $Timeout;
      $this->Repeat = $Repeat;
      
      if (!is_object ($P = $this->getHandler ()))
        return true;
      
      return $P->addTimeout ($this, $this->Timeout, $this->Repeat);
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
      // Forward the event to our parent
      if (!parent::setHandler ($Handler, $markBound))
        return false;
      
      // Make sure that we set any timeouts
      return $this->setTimeout ($this->Timeout, $this->Repeat);
    }
    // }}}
  }

?>