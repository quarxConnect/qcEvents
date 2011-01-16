<?PHP

  require_once ('phpEvents/event.php');
  require_once ('phpEvents/socket.php');
  
  class phpEvents_Socket_Server extends phpEvents_Event {
    const MODE_TCP = phpEvents_Socket::MODE_TCP;
    const MODE_UDP = phpEvents_Socket::MODE_UDP;
    
    // Internal stored class for connections
    private $connectionClass = null;
    
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
      
      // Put ourself into listening-state
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
      # TODO: Everything except TCP is unimplemented at the moment
      if ($Mode != self::MODE_TCP)
        return false;
      
      // Handle Context
      if ($Backlog !== null)
        # bindto : Bind socket to ipaddr:port
        $Context = stream_context_create (array ('backlog' => $Backlog));
      else
        $Context = stream_context_create (array ());
      
      // Create the socket
      if (!is_resource ($Socket = stream_socket_server ('tcp://' . $IP . ':' . $Port, $ErrNo, $ErrStr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $Context)))
        return false;
      
      // Setup the event-handler
      if (!$this->setFD ($Socket, true, false)) {
        fclose ($Socket);
        
        return false;
      }
      
      // Set ourself to online
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
      // Accept incoming connection
      if (!is_resource ($Connection = stream_socket_accept ($this->getFD ())))
        return false;
      
      // Create Client
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