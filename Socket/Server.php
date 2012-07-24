<?PHP

  /**
   * qcEvents - Multi-Purpose Server Interface
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
  require_once ('qcEvents/Socket.php');
  
  /**
   * Server-Socket
   * -------------
   * Event-based Server-Sockets
   * 
   * @class qcEvents_Socket_Server
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Socket_Server extends qcEvents_Event {
    const MODE_TCP = qcEvents_Socket::MODE_TCP;
    const MODE_UDP = qcEvents_Socket::MODE_UDP;
    
    const READ_UDP_BUFFER = 1500;
    const CHILD_UDP_TIMEOUT = 30;
    
    private $Mode = qcEvents_Socket_Server::MODE_TCP;
    
    // Internal stored class for connections
    private $connectionClass = 'qcEvents_Socket';
    
    // Are we listening at the moment
    private $Online = false;
    
    // All connections we handle (only in UDP-Mode)
    private $Clients = array ();
    
    // Do we have an Timer set to timeout UDP-Children
    private $haveUDPTimer = false;
    
    // {{{ __construct
    /**
     * Create a new server-process
     * 
     * @param enum $Mode (optional)
     * @param int $Port (optional)
     * @param string $IP (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($Mode = null, $Port = null, $IP = null) {
      // Check wheter to setup
      if (($Mode === null) || ($Port === null))
        return;
      
      // Put ourself into listenng-state
      $this->listen ($Mode, $Port, $IP);
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
      if (($Classname == 'qcEvents_Socket') ||
          !is_subclass_of ($Classname, 'qcEvents_Socket'))
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
     * @param enum $Mode
     * @param int $Port
     * @param string $IP (optional)
     * @param int $Backlog (optional)
     * 
     * @access public
     * @return bool
     **/
    public function listen ($Mode, $Port, $IP = null, $Backlog = null) {
      // Handle Context
      if ($Backlog !== null)
        # bindto : Bind socket to ipaddr:port
        $Context = stream_context_create (array ('backlog' => $Backlog));
      else
        $Context = stream_context_create (array ());
      
      if ($IP === null)
        $IP = '0.0.0.0';
      
      // Create the socket
      if ($Mode == self::MODE_UDP) {
        $Proto = 'udp';
        $Flags = STREAM_SERVER_BIND;
      } elseif ($Mode == self::MODE_TCP) {
        $Proto = 'tcp';
        $Flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
      } else
        return false;
      
      if (!is_resource ($Socket = stream_socket_server ($Proto . '://' . $IP . ':' . $Port, $ErrNo, $ErrStr, $Flags, $Context)))
        return false;
      
      // Setup the event-handler
      if (!$this->setFD ($Socket, true, false)) {
        fclose ($Socket);
        
        return false;
      }
      
      // Set ourself to online
      $this->Mode = $Mode;
      
      return ($this->Online = true);
    }
    // }}}
    
    // {{{ readEvent
    /**
     * Handle events on our server-socket
     * 
     * @access public
     * @return void
     **/
    public function readEvent () {
      // Handle UDP-Events
      if ($this->Mode == self::MODE_UDP) {
        if (($Data = stream_socket_recvfrom ($fd = $this->getFD (), self::READ_UDP_BUFFER, 0, $Remote)) === false)
          # TODO: What to do here?
          return false;
        
        // Create a client-handle if there is none yet
        if (!isset ($this->Clients [$Remote])) {
          $Client = new $this->connectionClass;
          
          // Setup the client
          $Client->setServer ($this);
          $Client->setConnection ($fd, $Remote, $this->Mode);
          
          $this->Clients [$Remote] = $Client;
          
          if (!$this->haveUDPTimer) {
            $this->addTimeout (self::CHILD_UDP_TIMEOUT, true, array ($this, 'checkUDPChildren'));
            $this->haveUDPTimer = true;
          }
        } else
          $Client = $this->Clients [$Remote];
        
        // Forward the data to the client
        $Client->readUDPServer ($Data);
        
        # TODO: Add a timeout to destroy UDP-Clients?
        
        return;
      
      // Handle TCP-Events (accept an incoming connection)
      } elseif ($this->Mode == self::MODE_TCP) {
        // Accept incoming connection
        if (!is_resource ($Connection = stream_socket_accept ($this->getFD ())))
          return false;
        
      // This should never happen
      } else
        return false;
      
      // Create new Client if neccessary
      $Client = new $this->connectionClass;
        
      // Remember this client here
      # Do we really care?!
      # $this->Clients [] = $Client;
      
      // Setup the client
      $Client->setServer ($this);
      $Client->setConnection ($Connection);
      
      // Add the client to our event-handler
      if (is_object ($Handler = $this->getHandler ()))
        $Handler->addEvent ($Client);
    }
    // }}}
    
    // {{{ disconnectChild
    /**
     * Remove a child-handle from an UDP-Server
     * 
     * @param object $Child
     * 
     * @access public
     * @return bool
     **/
    public function disconnectChild ($Child) {
      // This only applies to UDP-Mode
      if ($this->Mode != self::MODE_UDP)
        return false;
      
      // Retrive name of the peer on the child
      $Peer = $Child->getPeer ();
      
      // Check if we know it
      if (isset ($this->Clients [$Peer]))
        unset ($this->Clients [$Peer]);
      
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
  }

?>