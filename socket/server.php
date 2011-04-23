<?PHP

  require_once ('phpEvents/event.php');
  require_once ('phpEvents/socket.php');
  
  class phpEvents_Socket_Server extends phpEvents_Event {
    const MODE_TCP = phpEvents_Socket::MODE_TCP;
    const MODE_UDP = phpEvents_Socket::MODE_UDP;
    
    const READ_UDP_BUFFER = 1500;
    
    private $Mode = phpEvents_Socket_Server::MODE_TCP;
    
    // Internal stored class for connections
    private $connectionClass = 'phpEvents_Socket';
    
    // Are we listening at the moment
    private $Online = false;
    
    // All connections we handle
    private $Clients = array ();
    
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
      if (($Classname == 'phpEvents_Socket') ||
          !is_subclass_of ($Classname, 'phpEvents_Socket'))
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
    public function listen ($Mode, $Port, $IP = '0.0.0.0', $Backlog = null) {
      // Handle Context
      if ($Backlog !== null)
        # bindto : Bind socket to ipaddr:port
        $Context = stream_context_create (array ('backlog' => $Backlog));
      else
        $Context = stream_context_create (array ());
      
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
          $Client->setConnection ($fd, $Remote);
          
          $this->Clients [$Remote] = $Client;
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
  }

?>