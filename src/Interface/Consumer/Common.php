<?php

  /**
   * quarxConnect Events - Interface for Source-Consumers
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

  namespace quarxConnect\Events\Interface\Consumer;
  use quarxConnect\Events\Interface;
  use quarxConnect\Events;
  
  interface Common extends Interface\Hookable {
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param mixed $Data
     * @param Interface\Source $Source
     * 
     * @access public
     * @return void
     **/
    public function consume ($Data, Interface\Source $Source);
    // }}}
    
    // {{{ close
    /**
     * Close this event-interface
     * 
     * @access public
     * @return Events\Promise
     **/
    public function close () : Events\Promise;
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param Interface\Source $Source
     * 
     * @access public
     * @return Events\Promise
     **/
    public function deinitConsumer (Interface\Source $Source) : Events\Promise;
    // }}}
    
    
    // {{{ eventClosed
    /**
     * Callback: The interface was closed
     * 
     * @access public
     * @return void
     **/
    # protected function eventClosed ();
    // }}}
    
    // {{{ eventUnpiped
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param Source $Source
     * 
     * @access protected
     * @return void
     **/
    # protected function eventUnpiped (Source $Source);
    // }}}
  }
