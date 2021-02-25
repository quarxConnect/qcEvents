<?php

  /**
   * quarxConnect Events - Generic Parented Implementation
   * Copyright (C) 2014-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  declare (strict_types=1);

  namespace quarxConnect\Events\Trait;
  use quarxConnect\Events;
  
  trait Based {
    /* Our paretned event-base */
    private $eventBase = null;
    
    // {{{ getEventBase
    /**
     * Retrive the handle of the current event-loop-handler
     * 
     * @access public
     * @return Events\Base May be NULL if none is assigned
     **/
    public function getEventBase () : ?Events\Base {
      return $this->eventBase;
    }  
    // }}}
    
    // {{{ setEventBase
    /**
     * Set a new event-base-handler
     * 
     * @param Events\Base $eventBase
     * 
     * @access public
     * @return void  
     **/
    public function setEventBase (Events\Base $eventBase) {
      // Check if the event-loop if different from the current one
      if ($eventBase === $this->eventBase)
        return;
      
      // Remove ourself from the current event loop
      if ($this->eventBase && ($this instanceof Events\Interface\Loop))
        $this->eventBase->removeEvent ($this);
      
      // Assign the new event-loop
      $this->eventBase = $eventBase;   
      
      if ($this instanceof Events\Interface\Loop)
        $eventBase->addEvent ($this);
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
      if (!$this->eventBase)
        return;
      
      if ($this instanceof Events\Interface\Loop)
        $this->eventBase->removeEvent ($this);
      
      $this->eventBase = null;
    }
    // }}}
  }
