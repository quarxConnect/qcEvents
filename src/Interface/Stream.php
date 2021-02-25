<?php

  /**
   * quarxConnect Events - Interface for Event-Streams
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
  
  namespace quarxConnect\Events\Interface;
  use quarxConnect\Events;
  
  interface Stream extends Source, Sink {
    // {{{ pipeStream
    /**
     * Create a bidrectional pipe
     * Forward any data received from this source to another handler and
     * allow the handler to write back to this stream
     * 
     * @param Stream\Consumer $Handler
     * @param bool $Finish (optional) Raise close on the handler if we are finished (default)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function pipeStream (Stream\Consumer $Handler, $Finish = true) : Events\Promise;
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
  }
