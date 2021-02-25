<?php

  /**
   * quarxConnect Events - Interface Based
   * Copyright (C) 2020-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  namespace quarxConnect\Events\Interface;
  
  interface Based {
    // {{{ getEventBase
    /**
     * Retrive the handle of the current event-loop-handler
     * 
     * @access public
     * @return \quarxConnect\Events\Base
     **/
    public function getEventBase () : ?\quarxConnect\Events\Base;
    // }}}
    
    // {{{ setEventBase
    /**
     * Set the Event-Base of this source
     * 
     * @param \quarxConnect\Events\Base $eventBase
     * 
     * @access public
     * @return void
     **/
    public function setEventBase (\quarxConnect\Events\Base $eventBase);
    // }}}
    
    // {{{ unsetEventBase
    /**
     * Remove any assigned event-loop-handler
     * 
     * @access public
     * @return void
     **/
    public function unsetEventBase ();
    // }}}
  }
