<?PHP

  /**
   * qcEvents - Client-Socket Pool
   * Copyright (C) 2017 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  class qcEvents_Socket_Pool_Session {
    private $socketPool = null;
    
    // {{{ __construct
    /**
     * Create a new pool-session
     * 
     * @param qcEvents_Socket_Pool $socketPool
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Socket_Pool $socketPool) {
      $this->socketPool = $socketPool;
    }
    // }}}
    
    // {{{ getSocketPool
    /**
     * Retrive the socket-pool of this session
     * 
     * @access public
     * @return qcEvents_Socket_Pool
     **/
    public function getSocketPool () : qcEvents_Socket_Pool {
      return $this->socketPool;
    }
    // }}}
    
    // {{{ acquireSocket
    /**
     * Request a socket from this pool-session
     * 
     * @param mixed $remoteHost
     * @param int $remotePort
     * @param enum $socketType
     * @param bool $useTLS
     * 
     * @access public
     * @return void
     **/
    public function acquireSocket ($remoteHost, $remotePort, $socketType, $useTLS) : qcEvents_Promise {
      return $this->socketPool->acquireSocket ($remoteHost, $remotePort, $socketType, $useTLS, $this);
    }
    // }}}
    
    // {{{ releaseSocket
    /**
     * Remove a socket from this pool
     * 
     * @param qcEvents_Socket $Socket
     * 
     * @access public
     * @return void
     **/
    public function releaseSocket (qcEvents_Socket $Socket) {
      return $this->socketPool->releaseSocket ($Socket);
    }
    // }}}
    
    // {{{ close
    /**
     * Close this session
     * 
     * @access public
     * @return void
     **/
    public function close () {
      return $this->socketPool->removeSession ($this);
    }
    // }}}
  }

?>