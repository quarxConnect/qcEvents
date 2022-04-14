<?php

  /**
   * quarxConnect Events - Socket Factory Interface
   * Copyright (C) 2020-2022 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  namespace quarxConnect\Events\ABI\Socket;
  use \quarxConnect\Events;
  use \quarxConnect\Events\ABI;
  
  interface Factory extends ABI\Hookable, ABI\Based {
    // {{{ createConnection
    /**
     * Request a connected socket from this factory
     * 
     * @param array|string $remoteHost
     * @param int $remotePort
     * @param int $socketType
     * @param bool $useTLS (optional)
     * @param bool $allowReuse (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function createConnection ($remoteHost, int $remotePort, int $socketType, bool $useTLS = false, bool $allowReuse = false) : Events\Promise;
    // }}}
    
    // {{{ releaseConnection
    /**
     * Return a connected socket back to the factory
     * 
     * @param Events\ABI\Stream
     * 
     * @access public
     * @return void
     **/
    public function releaseSocket (Events\ABI\Stream $acquiredSocket) : void;
    // }}}
  }
