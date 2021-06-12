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

  namespace quarxConnect\Events\ABI;
  use \quarxConnect\Events;
  
  interface Consumer extends Consumer\Common {
    // {{{ initConsumer
    /**
     * Setup ourself to consume data from a source
     * 
     * @param Source $dataSource
     * 
     * @access public
     * @return Events\Promise
     **/
    public function initConsumer (Source $dataSource) : Events\Promise;
    // }}}
    
    
    // {{{ eventPiped
    /**
     * Callback: A source was attached to this consumer
     * 
     * @param Source $Source
     * 
     * @access protected
     * @return void
     **/
    # protected function eventPiped (Source $Source);
    // }}}
  }
