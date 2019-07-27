<?PHP

  /**
   * qcEvents - Interface for Servers
   * Copyright (C) 2019 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Interface/Hookable.php');
  
  interface qcEvents_Interface_Server extends qcEvents_Interface_Hookable {
    // {{{ setChildClass
    /**
     * Set class to use for incoming connections
     * 
     * @param string $Classname
     * @param bool $Piped (optional) Treat the class as pipe-consumer, not as socket
     * 
     * @access public
     * @return bool
     **/
    public function setChildClass ($Classname, $Piped = false);
    // }}}
    
    // {{{ addChildHook
    /**
     * Register a hook for new children
     * 
     * @param string $Hook
     * @param callback $Callback
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return bool
     **/
    public function addChildHook ($Name, $Callback, $Private = null);
    // }}}
    
    // {{{ getLocalName
    /**
     * Retrive the local sock-addr-spec of this server
     * 
     * @access public
     * @return string
     **/
    public function getLocalName ();
    // }}}
    
    // {{{ getLocalPort
    /**
     * Retrive the local port of this server
     * 
     * @access public
     * @return int
     **/
    public function getLocalPort ();
    // }}}
    
    // {{{ listen
    /**
     * Create a the server-process
     * 
     * @param enum $Type
     * @param int $Port (optional)
     * @param string $Host (optional)
     * @param int $Backlog (optional)
     * 
     * @access public
     * @return bool
     **/
    public function listen ($Type, $Port = null, $Host = null, $Backlog = null);
    // }}}
    
    // {{{ close
    /**
     * Close this event-interface
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function close () : qcEvents_Promis;
    // }}}
    
    
    // {{{ serverOnline
    /**
     * Callback: The server went into listening state
     * 
     * @access protected
     * @return void
     **/
    # protected function serverOnline ();
    // }}}
    
    // {{{ serverOffline
    /**
     * Callback: The server was closed
     * 
     * @access protected
     * @return void
     **/
    # protected function serverOffline ();
    // }}}
    
    // {{{ serverClientAccept
    /**
     * Callback: Accept a new incoming connection
     * 
     * @param string $Remote
     * @param resource $Socket (optional) Stream-Resource for TCP-Connections
     * 
     * @access protected
     * @return bool If FALSE the connection is discared
     **/
    # protected function serverClientAccept ($Remote, $Socket = null);
    // }}}
    
    // {{{ serverClientNew
    /**
     * Callback: A new client was created
     * 
     * @param qcEvents_Socket $Client
     * @param mixed $Consumer
     * 
     * @access protected
     * @return void
     **/
    # protected function serverClientNew (qcEvents_Socket $Socket, $Consumer = null);
    // }}}
    
    // {{{ serverClientClosed
    /**
     * Callback: Client-Connection was/will be closed
     * 
     * @param string $Remote
     * @param qcEvents_Socket $Client
     * 
     * @access protected
     * @return void
     **/
    # protected function serverClientClosed ($Remote, qcEvents_Socket $Socket);
    // }}}
  }

?>