<?PHP

  /**
   * qcEvents - Asyncronous Sockets
   * Copyright (C) 2012 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Event.php');
  
  /**
   * Event-Socket
   * ------------
   * Socket based Event-Handler
   * 
   * @class qcEvents_Base
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Socket extends qcEvents_Event {
    const MODE_TCP = 0;
    const MODE_UDP = 1;
    
    const READ_BUFFER = 4096;
    const READ_UDP_BUFFER = 1500;
    
    private $Online = false;
    private $Server = null;
    private $Peer = null;
    
    // {{{ __construct
    /**
     * Create a new connection
     * 
     * @param enum $Mode (optional)
     * @param string $IP (optional)
     * @param int $Port (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($Mode = null, $IP = null, $Port = null) {
      // Check wheter to generate a connection
      if (($Mode === null) || ($IP === null) || ($Port === null))
        return;
      
      // Try to connect
      $this->connect ($Mode, $IP, $Port);
    }
    // }}}
    
    // {{{ __destruct
    /**
     * Destroy an open connection
     * 
     * @access friendly
     * @return void
     **/
    function __destruct () {
      if ($this->isOnline ())
        $this->disconnect ();
    }
    // }}}
    
    // {{{ connect
    /**
     * Create a new client-connection
     * 
     * @param enum $Mode
     * @param string $IP
     * @param int $Port
     * 
     * @access public
     * @return bool
     **/
    public function connect ($Mode, $IP, $Port) {
      if ($Mode !== self::MODE_TCP)
        return false;
      
      // Check if we are connected at the moment
      if ($this->Online && !$this->disconnect ())
        return false;
      
      // Check for IPv6
      if ((strpos ($IP, ':') !== false) && ($IP [0] != '['))
        $IP = '[' . $IP . ']';
      
      // Create new client-socket
      if (!is_resource ($Socket = stream_socket_client ('tcp://' . $IP . ':' . $Port, $errno, $err, 5, STREAM_CLIENT_ASYNC_CONNECT)))
        return false;
      
      return $this->setConnection ($Socket);
    }
    // }}}
    
    // {{{ disconnect
    /**
     * Close any existing connection
     * 
     * @access public
     * @return bool
     **/
    public function disconnect () {
      if (!$this->Online)
        return true;
      
      if (!$this->isUDPServerClient ()) {
        $this->unbind ();
        @fclose ($this->getFD ());
      }
      
      $this->Online = false;
      $this->___callback ('closed');
      
      return true;
    }
    // }}}
    
    // {{{ setServer
    /**
     * Set our parent server-handle
     * 
     * @param object $Server
     * 
     * @access public
     * @return bool
     **/
    public function setServer ($Server) {
      $this->Server = $Server;
      
      return true;
    }
    // }}}
    
    // {{{ setConnection
    /**
     * Inherit Connection-Handle
     * 
     * @param resource $Socket
     * @param string $Peer (optional)
     * 
     * @access public
     * @return bool
     **/
    public function setConnection ($Socket, $Peer = null) {
      // Make sure socket is of the right type
      if (!is_resource ($Socket))
        return false;
      
      // Monitor read- and write-events (to notices when socket becomes connected)
      $this->setFD ($Socket, true, true);
      
      if ($Peer !== null)
        $this->setPeername ($Peer);
      
      return true;
    }
    // }}}
    
    // {{{ isOnline
    /**
     * Check our connection-status
     * 
     * @access public
     * @return bool
     **/
    public function isOnline () {
      return $this->Online;
    }
    // }}}
    
    // {{{ isUDPServerClient
    /**
     * Check if this socket is the child of an UDP-Server
     * 
     * @access protected
     * @return bool
     **/
    protected function isUDPServerClient () {
      return (is_object ($this->Server) && ($this->getFD () == $this->Server->getFD ()));
    }
    // }}}
    
    // {{{ haveTLS
    /**
     * Check if we have TLS-Support available
     * 
     * @access public
     * @return bool
     **/
    public function haveTLS () {
      # TODO: Implement this
      return false;
    }
    // }}}
    
    // {{{ startTLS
    /**
     * Start TLS on this connection
     * 
     * @access public
     * @return bool
     **/
    public function startTLS () {
      return false;
    }
    // }}}
    
    // {{{ readEvent
    /**
     * Handle incoming events
     * 
     * @access public
     * @return void
     **/
    public function readEvent () {
      // Read incoming data from socket
      if (($Data = stream_socket_recvfrom ($this->getFD (), self::READ_BUFFER)) == '')
        return $this->disconnect ();
      
      // Forward to our handler
      $this->___callback ('receive', $Data);
    }
    // }}}
    
    // {{{ writeEvent
    /**
     * Recognize when the socket becomes writeable
     * 
     * @access public
     * @return void
     **/
    public function writeEvent () {
      // Do not modify online sockets
      if ($this->Online)
        return;
      
      // Set our status to online
      $this->Online = true;
      
      // Forget about write-events
      $this->setFD ($this->getFD (), true, false);
      
      // Forward the event
      $this->___callback ('connected');
    }
    // }}}
    
    // {{{ readUDPServer
    /**
     * Receive Data from an UDP-Server-Class
     * 
     * @param string $Data
     * 
     * @access public
     * @return void
     **/
    public function readUDPServer ($Data) {
      // Forward to our handler
      $this->___callback ('receive', $Data);
    }
    // }}}
    
    // {{{ setPeername
    /**
     * Set the peername for this connection
     * 
     * @param string $Peer
     * 
     * @remark This works only for UDP-Sockets and only once
     * @access private
     * @return bool
     **/
    private function setPeername ($Peer) {
      if (!$this->isUDPServerClient ())
        return false;
      
      if ($this->Peer !== null)
        return false;
      
      $this->Peer = $Peer;
      
      return true;
    }
    // }}}
    
    // {{{ getPeername
    /**
     * Retrive the address of the remote endpoint
     * 
     * @access public
     * @return string
     **/
    public function getPeername () {
      if ($this->Peer === null)
        $this->Peer = stream_socket_get_name ($this->getFD (), true);
      
      return substr ($this->Peer, 0, strpos ($this->Peer, ':'));
    }
    // }}}
    
    // {{{ getPeerport
    /**
     * Retrive the port of the remote endpoint
     * 
     * @access public
     * @return int
     **/
    public function getPeerport () {
      if ($this->Peer === null)
        $this->Peer = stream_socket_get_name ($this->getFD (), true);
                    
      return intval (substr ($this->Peer, strpos ($this->Peer, ':') + 1));
    }
    // }}}
    
    // {{{ getLocalname
    /**
     * Retrive the address of the local endpoint
     * 
     * @access public
     * @return string
     **/
    public function getLocalname () {
      $Addr = stream_socket_get_name ($this->getFD (), false);
      
      return substr ($Addr, 0, strpos ($Addr, ':'));
    }
    // }}}
    
    // {{{ write
    /**
     * Write data to our connection
     * 
     * @param string $Data
     * @param int $Length (optional)
     * 
     * @access public
     * @return bool  
     **/
    public function write ($Data, $Length = null) {
      if (!is_resource ($fd = $this->getFD ()))
        return false;
      
      if (!$this->isUDPServerClient ())
        return stream_socket_sendto ($fd, $Data);
      
      if ($this->Peer === null)
        return false;
      
      return stream_socket_sendto ($fd, $Data, 0, $this->Peer);
    }
    // }}}
    
    // {{{ mwrite
    /**
     * Write multiple messages to our connection
     * 
     * @param string ...
     * 
     * @access public
     * @return bool
     **/
    public function mwrite () {
      foreach (func_get_args () as $Message)
        if (!$this->write ($Message))
          return false;
      
      return true;
    }
    // }}}
    
    // {{{ close
    /**
     * Just an Alias for disconnect()
     * 
     * @access public
     * @return bool
     **/
    public function close () {
      return $this->disconnect ();
    }
    // }}}
    
    
    // {{{ connected
    /**
     * Callback: Invoked whenever the connection is established
     * 
     * @access protected
     * @return void
     **/
    protected function connected () { }
    // }}}
    
    // {{{ receive
    /**
     * Callback: Invoked whenever incoming data is received
     * 
     * @param string $Data
     * 
     * @access protected
     * @return void
     **/
    protected function receive ($Data) { }
    // }}}
    
    // {{{ closed
    /**
     * Callback: Connection was closed
     * 
     * @access protected
     * @return void
     **/
    protected function closed () { }
    // }}}
    
    // {{{ tlsEnabled
    /**
     * Callback: TLS was successfully enabled on the stream
     * 
     * @access protected
     * @return void
     **/
    protected function tlsEnabled () { }
    // }}}
    
    // {{{ tlsFailed
    /**
     * Callback: The stream failed to enable TLS
     * 
     * @access protected
     * @return void
     **/
    protected function tlsFailed () { }
    // }}}
  }


?>