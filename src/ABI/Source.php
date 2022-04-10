<?php

  /**
   * quarxConnect Events - Interface for Event-Sources
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

  namespace quarxConnect\Events\ABI;
  use quarxConnect\Events;
  
  interface Source extends Common {
    // {{{ read
    /**
     * Try to read pending data from this source
     * 
     * @param int $readLength (optional)
     * 
     * @access public
     * @return string
     **/
    public function read (int $readLength = null) : ?string;
    // }}}
    
    // {{{ pipe
    /**
     * Forward any data received from this source to another handler
     * 
     * @param Consumer $dataReceiver
     * @param bool $forwardClose (optional) Raise close on the handler if we are finished (default)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function pipe (Consumer $dataReceiver, bool $forwardClose = true) : Events\Promise;
    // }}}
    
    // {{{ unpipe
    /**
     * Remove a handler that is currently being piped
     * 
     * @param Consumer\Common $Handler
     * 
     * @access public
     * @return Events\Promise
     **/
    public function unpipe (Consumer\Common $Handler) : Events\Promise;
    // }}}
    
    // {{{ watchRead
    /**
     * Set/Retrive the current event-watching status
     * 
     * @param bool $Set (optional) Set the status
     * 
     * @access public
     * @return bool
     **/
    public function watchRead ($Set = null);
    // }}}
    
    
    // {{{ eventReadable
    /**
     * Callback: A readable-event was received for this handler on the event-loop
     * 
     * @access protected
     * @return void
     **/
    # protected function eventReadable ();
    // }}}
  }
