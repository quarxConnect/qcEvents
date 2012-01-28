<?PHP

  /**
   * qcEvents - Timed Events
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
  
  require_once ('qcEvents/Event.php');
  
  /**
   * Timer Event
   * -----------
   * Event-Object for timed tasks
   * 
   * @class qcEvents_Timer
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Timer extends qcEvents_Event {
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