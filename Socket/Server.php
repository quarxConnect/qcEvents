<?PHP

  /**
   * qcEvents - Multi-Purpose Server Interface
   * Copyright (C) 2013 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Event.php');
  require_once ('qcEvents/Socket.php');
  
  /**
   * Server-Socket
   * -------------
   * Event-based Server-Sockets
   * 
   * @class qcEvents_Socket_Server
   * @package qcEvents
   * @revision 02
   **/
  class qcEvents_Socket_Server extends qcEvents_Event {
    /* Base-Class for Child-Connections */
    const CHILD_CLASS_BASE = 'qcEvents_Socket';
    
    /* Timeout-Values */
    const CHILD_UDP_TIMEOUT = 20;
    
    /* Server-Socket-Types */
    const TYPE_TCP = qcEvents_Socket::TYPE_TCP;
    const TYPE_UDP = qcEvents_Socket::TYPE_UDP;
    
    private $Type = qcEvents_Socket_Server::TYPE_TCP;
    
    // Internal stored class for connections
    private $connectionClass = qcEvents_Socket_Server::CHILD_CLASS_BASE;
    
    // Are we listening at the moment
    private $Listening = false;
    
    // All connections we handle (only in UDP-Mode)
    private $Clients = array ();
    
    // Do we have an Timer set to timeout UDP-Children
    private $haveUDPTimer = false;
    
    // {{{ __construct
    /**
     * Create a new server-process
     * 
     * @param qcEvents_Base $Base (optional)
     * @param string $Host (optional)
     * @param int $Port (optional)
     * @param enum $Type (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $Base = null, $Host = null, $Port = null, $Type = null) {
      // Don't do anything withour an events-base
      if ($Base === null)
        return;
      
      // Set our handler
      $this->setEventBase ($Base);
      
      // Check wheter to setup
      if (($Type === null) || ($Port === null))
        return;
      
      // Put ourself into listenng-state
      $this->listen ($Type, $Port, $Host);
    }
    // }}}
    
    // {{{ setClass
    /**
     * Set class to use for incoming connections
     * 
     * @param string $Classname
     * 
     * @access public
     * @return bool
     **/
    public function setClass ($Classname) {
      // Verify the class
      if (($Classname == $this::CHILD_CLASS_BASE) ||
          !is_subclass_of ($Classname, $this::CHILD_CLASS_BASE))
        return false;
      
      // Set the class
      $this->connectionClass = $Classname;
      
      return true;
    }
    // }}}
    
    // {{{ listen
    /**
     * Create a the server-process
     * 
     * @param enum $Type
     * @param int $Port
     * @param string $Host (optional)
     * @param int $Backlog (optional)
     * 
     * @access public
     * @return bool
     **/
    public function listen ($Type, $Port, $Host = null, $Backlog = null) {
      // Handle Context
      if ($Backlog !== null)
        $Context = stream_context_create (array ('backlog' => $Backlog));
      else
        $Context = stream_context_create (array ());
      
      if ($Host === null)
        $Host = '0.0.0.0';
      
      // Create the socket
      if ($Mode == self::TYPE_UDP) {
        $Proto = 'udp';
        $Flags = STREAM_SERVER_BIND;
      } elseif ($Mode == self::TYPE_TCP) {
        $Proto = 'tcp';
        $Flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
      } else
        return false;
      
      if (!is_resource ($Socket = stream_socket_server ($Proto . '://' . $Host . ':' . $Port, $ErrNo, $ErrStr, $Flags, $Context)))
        return false;
      
      // Setup the event-handler
      if (!$this->setFD ($Socket, true, false)) {
        fclose ($Socket);
        
        return false;
      }
      
      // Set ourself to online
      $this->Type = $Type;
      $this->Listening = true;
      
      // Fire callback
      $this->___callback ('serverOnline');
      
      return true;
    }
    // }}}
    
    // {{{ readEvent
    /**
     * Handle events on our server-socket
     * 
     * @access public
     * @return void
     **/
    public final function readEvent () {
      // Handle UDP-Events
      if ($this->Type == self::TYPE_UDP) {
        if (($Data = stream_socket_recvfrom ($fd = $this->getFD (), self::READ_UDP_BUFFER, 0, $Remote)) === false)
          # TODO: What to do here?
          return false;
        
        // Create a client-handle if there is none yet
        if (!isset ($this->Clients [$Remote])) {
          // Fire callback first
          if ($this->___callback ('serverClientAccept', $Remote) === false)
            return;
          
          // Create the client
          $this->Clients [$Remote] = $Client = new $this->connectionClass;
          $Client->connectServer ($this, $Remote);
          
          // Make sure we have a timer
          if (!$this->haveUDPTimer) {
            $this->addTimeout (max (2, intval (self::CHILD_UDP_TIMEOUT / 4)), true, array ($this, 'checkUDPChildren'));
            $this->haveUDPTimer = true;
          }
        
        // Peek the client from storage
        } else
          $Client = $this->Clients [$Remote];
        
        // Forward the data to the client
        return $Client->readUDPServer ($Data);
      
      // Handle TCP-Events (accept an incoming connection)
      } elseif ($this->Type == self::TYPE_TCP) {
        // Accept incoming connection
        if (!is_resource ($Connection = stream_socket_accept ($this->getFD (), 0, $Remote)))
          return false;
        
        // Fire callback first
        if ($this->___callback ('serverClientAccept', $Remote) === false)
          return;
        
        // Create new Client if neccessary
        $Client = new $this->connectionClass ($this->getEventBase ());
        $Client->connectServer ($this, $Remote, $Connection);
      }
    }
    // }}}
    
    // {{{ disconnectChild
    /**
     * Remove a child-handle from an UDP-Server
     * 
     * @param qcEvents_Socket $Child
     * 
     * @access public
     * @return bool
     **/
    public function disconnectChild (qcEvents_Socket $Child) {
      // This only applies to UDP-Mode
      if ($this->Type != self::TYPE_UDP)
        return false;
      
      // Retrive name of the peer on the child
      $Peer = $Child->getRemoteName ();
      
      // Check if we know it
      if (!isset ($this->Clients [$Peer]))
        return false;
      
      // Remove the client
      $Client = $this->Clients [$Peer];
      unset ($this->Clients [$Peer]);
      
      // Fire callback
      $this->___callback ('serverClientClosed', $Peer, $Client);
      
      return true;
    }
    // }}}
    
    // {{{ checkUDPChildren
    /**
     * Check if one of our children timed out
     * 
     * @access public
     * @return void
     **/
    public function checkUDPChildren () {
      // Retrive the actual time
      $t = time ();
      
      foreach ($this->Clients as $C)
        if ($t - $C->getLastEvent () > self::CHILD_UDP_TIMEOUT)
          $C->disconnect ();
    }
    // }}}
    
    
    // {{{ serverOnline
    /**
     * Callback: The server went into listening state
     * 
     * @access protected
     * @return void
     **/
    protected function serverOnline () { }
    // }}}
    
    // {{{ serverClientAccept
    /**
     * Callback: Accept a new incoming connection
     * 
     * @param string $Remote
     * 
     * @access protected
     * @return bool If FALSE the connection is discared
     **/
    protected function serverClientAccept ($Remote) { }
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
    protected function serverClientClosed ($Remote, qcEvents_Socket $Client) { }
    // }}}
  }

?>