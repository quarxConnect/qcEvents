<?PHP

  /**
   * qcEvents - Generic Event Handle
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
  
  require_once ('qcEvents/Base.php');
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Interface.php');
  
  /**
   * Event
   * -----
   * Event-object
   * 
   * @class qcEvents_Event
   * @package qcEvents
   * @revision 01   
   **/
  class qcEvents_Event extends qcEvents_Hookable implements qcEvents_Interface {
    // Informations on this event
    private $fd = null;
    private $monitorRead = false;
    private $monitorWrite = false;
    private $monitorError = false;
    private $monitorTimer = false;
    private $Callback = null;
    
    // Information about our status and parent
    private $isBound = false;
    private $eventBase = null;
    
    // {{{ __construct
    /**
     * Create a new Event-Handler
     * 
     * @param qcEvents_Base $Base (optional)
     * @param resource $fd (optional)
     * @param bool $monitorRead (optional)
     * @param bool $monitorWrite (optional)
     * @param bool $monitorError (optional)
     * @param callable $Callback (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $Base = null, $fd = null, $monitorRead = true, $monitorWrite = false, $monitorError = false, $Callback = null) {
      // Set our event-handler
      if ($Base !== null)
        $this->setEventBase ($Base);
      
      // Check wheter to proceed
      if ($fd === null)
        return;
      
      // Make sure we have a valid stream
      if (!is_resource ($fd))
        throw new Exception ('Invalid fd');
      
      // Check wheter to set a callback
      if ($Callback !== null)
        $this->setCallback ($Callback);
      
      // Setup ourself
      if (!$this->setFD ($fd, $monitorRead, $monitorWrite, $monitorError))
        throw new Exception ('Could not create event');
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
    
    // {{{ setFD
    /**
     * Set the file-descriptor to monitor
     * 
     * @param resource $fd
     * @param bool $monitorRead (optional) Monitor read-events (default)
     * @param bool $monitorWrite (optional) Monitor write-events
     * @param bool $monitorError (optional) Montor error-events
     * 
     * @access public
     * @return bool
     **/
    public function setFD ($fd, $monitorRead = true, $monitorWrite = false, $monitorError = false) {
      // Check wheter FD is a valid resource
      if (!is_resource ($fd))
        return false;
      
      // Store informations in our object   
      $this->fd = $fd;
      $this->monitorRead = $monitorRead;
      $this->monitorWrite = $monitorWrite;
      $this->monitorError = $monitorError;
      
      if ($this->isBound && is_object ($this->eventBase))
        $this->eventBase->updateEvent ($this);
      
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
    
    // {{{ watchRead
    /**
     * Check or set wheter to watch for read-events
     * 
     * @param bool $Toggle (optional) Set new status
     * 
     * @access public
     * @return bool
     **/
    public function watchRead ($Toggle = null) {
      if (($Toggle !== null) && $this->setFD ($this->getFD (), $Toggle, $this->watchWrite (), $this->watchError ()))
        return true;
      
      return $this->monitorRead;
    }
    // }}}
    
    // {{{ watchWrite
    /**
     * Check or set wheter to watch for write-events
     * 
     * @param bool $Toggle (optional) Set new status
     * 
     * @access public
     * @return bool
     **/
    public function watchWrite ($Toggle = null) {
      if (($Toggle !== null) && $this->setFD ($this->getFD (), $this->watchRead (), $Toggle, $this->watchError ()))
        return true;
      
      return $this->monitorWrite;
    }
    // }}}
    
    // {{{ watchError
    /**
     * Check or set wheter to watch for error-events
     * 
     * @param bool $Toggle (optional) Set new status
     * 
     * @access public
     * @return bool
     **/
    public function watchError ($Toggle = null) {
      if (($Toggle !== null) && $this->setFD ($this->getFD (), $this->watchRead (), $this->watchWrite (), $Toggle))
        return true;
      
      return $this->monitorError;
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
      return $this->isBound;
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
      if ($this->isBound)
        return true;
      
      // Make sure we have a base assigned
      if (!is_object ($this->eventBase))
        return false;
      
      return ($this->isBound = $this->eventBase->addEvent ($this));
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
      if (!$this->isBound)
        return true;
      
      // Now really unbind
      $this->isBound = false;
      
      if (is_object ($this->eventBase))
        $this->eventBase->removeEvent ($this);
      
      return true;
    }
    // }}}
    
    // {{{ haveEventBase
    /**
     * Check if we have an event-base assigned
     * 
     * @access public
     * @return bool
     **/
    public final function haveEventBase () {
      return is_object ($this->eventBase);
    }
    // }}}
    
    // {{{ getEventBase
    /**
     * Retrive our assigned event-base
     * 
     * @access public
     * @return qcEvents_Base
     **/
    public final function getEventBase () {
      return $this->eventBase;
    }
    // }}}
    
    // {{{ setEventBase
    /**
     * Set an event-base for this event
     * 
     * @access public
     * @return bool
     **/
    public final function setEventBase (qcEvents_Base $Base, $setBound = false) {
      // Make sure we are registered on this handler
      if (!$Base->haveEvent ($this))
        return $Base->addEvent ($this);
      
      // Set this handler
      $this->eventBase = $Base;
      
      if ($setBound) {
        $this->isBound = true;
        $this->___callback ('onEventHandler');
      }
      
      return true;
    }
    // }}}
    
    // {{{ getBase
    /**
     * Retrive our base Event-Handler
     * 
     * @access public
     * @return object
     **/
    public function getBase () {
      trigger_error ('qcEvents_Event::getBase() is deprecated, use qcEvents_Event::getEventBase() instead', E_USER_DEPRECATED);
      
      return $this->getEventBase ();
    }
    // }}}
    
    // {{{ setBase
    /**
     * Set our base Event-Handler
     * 
     * @param object $Base
     * 
     * @access public
     * @return bool
     **/
    public function setBase ($Base) {
      trigger_error ('qcEvents_Event::setBase() is deprecated, use qcEvents_Event::setEventBase() instead', E_USER_DEPRECATED);
      
      return $this->setEventBase ($Base);
    }
    // }}}
    
    // {{{ setHandler
    /**
     * Store handle of our event-base
     * 
     * @param object $Handler
     * @param bool $markBound (optional)
     * 
     * @access public
     * @return bool
     **/
    public final function setHandler ($Handler, $markBound = false) {
      trigger_error ('qcEvents_Event::setHandler() is deprecated, use qcEvents_Event::setEventBase() instead', E_USER_DEPRECATED);
      
      return $this->setEventBase ($Handler, $markBound);
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
      trigger_error ('qcEvents_Event::getHandler() is deprecated, use qcEvents_Event::getEventBase() instead', E_USER_DEPRECATED);
      
      return $this->getEventBase ();
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
        call_user_func ($this->Callback, $this, self::EVENT_READ);
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
        call_user_func ($this->Callback, $this, self::EVENT_WRITE);
    }
    // }}}
    
    // {{{ errorEvent
    /**
     * Handle an error-event
     * 
     * @access public
     * @return void
     **/
    public function errorEvent () {
      if ($this->Callback !== null)
        call_user_func ($this->Callback, $this, self::EVENT_ERROR);
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
        call_user_func ($this->Callback, $this, self::EVENT_TIMER);
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
      if (is_object ($this->eventBase))
        $this->eventBase->loopOnce ($Timeout);
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
      if (!is_object ($this->eventBase))
        return false;
      
      // Queue the event on our parent
      return $this->eventBase->queueForNextIteration ($this, $Event);
    }
    // }}}
    
    // {{{ setTimeout
    /**
     * Add create a timeout for this event-object
     * 
     * @param int $Timeout How many seconds to wait
     * @param bool $Repeat (optional) Keep the timeout
     * @param callback $Callback (optional) Use this function as Callback
     * @param mixed $Privte (optional) Private data passed to the callback
     * 
     * @access public
     * @return bool
     **/
    public function addTimeout ($Timeout, $Repeat = false, $Callback = null, $Private = null) {
      if (!is_object ($B = $this->getEventBase ()))
        return false;
      
      return $B->addTimeout ($this, $Timeout, $Repeat, $Callback, $Private);
    }
    // }}}
  }

?>