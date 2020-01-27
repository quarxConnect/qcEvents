<?PHP

  /**
   * qcEvents - Event Trait
   * Copyright (C) 2013 Bernd Holzmueller <bernd@quarxconnect.de>
   * 
   * This program is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or
   * (at your option) any later version.
   * 
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
   * GNU General Public License for more details.
   *
   * You should have received a copy of the GNU General Public License
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/
  
  require_once ('qcEvents/Trait/Parented.php');
  
  trait qcEvents_Trait {
    use qcEvents_Trait_Parented;
    
    private $FD = null;
    private $isBound = false;
    
    // {{{ getFD
    /**
     * Retrive the FD of this event to watch for actions
     * 
     * @access public
     * @return resource
     **/
    public function getFD () {
      return $this->FD;
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
      
      if ($eventBase = $this->getEventBase ())
        $eventBase->removeEvent ($this);
      
      return true;
    }
    // }}}
    
    // {{{ watchRead
    /**
     * Check if the event-fd should be watched for read-events
     * 
     * @access public
     * @return bool  
     **/
    public function watchRead () {
      return false;
    }
    // }}}
    
    // {{{ watchWrite
    /**
     * Check wheter for watch for write-events of the fd of this event
     * 
     * @access public
     * @return bool  
     **/
    public function watchWrite () {
      return false;
    }
    // }}}
    
    // {{{ watchError
    /**
     * Check wheter to watch for error-exceptions on the events fd
     * 
     * @access public
     * @return bool  
     **/
    public function watchError () {
      return false;
    }
    // }}}
    
    // {{{ readEvent
    /**
     * Callback: A read-event occured for this event
     * 
     * @access public
     * @return void
     **/
    public function readEvent () { }
    // }}}
    
    // {{{ writeEvent
    /**
     * Callback: A write-event occured for this event
     * 
     * @access public
     * @return void
     **/
    public function writeEvent () { }
    // }}}
    
    // {{{ errorEvent
    /**
     * Callback: An error-event occured for this event
     * 
     * @access public
     * @return void
     **/
    public function errorEvent () { }
    // }}}
    
    // {{{ timerEvent
    /**
     * Callback: A timer-event occured for this event
     * 
     * @access public
     * @return void
     **/
    public function timerEvent () { }
    // }}}
  }

?>