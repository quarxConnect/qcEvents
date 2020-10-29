<?PHP

  /**
   * qcEvents - Generic Parented Implementation
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
  
  trait qcEvents_Trait_Parented {
    /* Our paretned event-loop */
    private $eventLoop = null;
    
    // {{{ getEventBase
    /**
     * Retrive the handle of the current event-loop-handler
     * 
     * @access public
     * @return qcEvents_Base May be NULL if none is assigned
     **/
    public function getEventBase () {
      return $this->eventLoop;
    }  
    // }}}
    
    // {{{ setEventBase
    /**
     * Set a new event-loop-handler
     * 
     * @param qcEvents_Base $Base
     * 
     * @access public
     * @return void  
     **/
    public function setEventBase (qcEvents_Base $Base) {
      // Check if the event-loop if different from the current one
      if ($Base === $this->eventLoop)
        return;
      
      // Remove ourself from the current event loop
      if ($this->eventLoop && ($this instanceof qcEvents_Interface_Loop))
        $this->eventLoop->removeEvent ($this);
      
      // Assign the new event-loop
      $this->eventLoop = $Base;   
      
      if ($this instanceof qcEvents_Interface_Loop)
        $Base->addEvent ($this);
    }
    // }}}
    
    // {{{ unsetEventBase
    /**
     * Remove any assigned event-loop-handler
     * 
     * @access public
     * @return void  
     **/
    public function unsetEventBase () {
      if (!$this->eventLoop)
        return;
      
      if ($this instanceof qcEvents_Interface_Loop)
        $this->eventLoop->removeEvent ($this);
      
      $this->eventLoop = null;
    }
    // }}}
  }

?>