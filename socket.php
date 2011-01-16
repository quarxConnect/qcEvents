<?PHP

  require_once ('phpEvents/event.php');
  
  class phpEvents_Socket extends phpEvents_Event {
    const MODE_TCP = 0;
    const MODE_UDP = 1;
    
    const READ_BUFFER = 4096;
    
    private $Online = false;
    
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
      
      // Create new client-socket
      if (!is_resoruce ($Socket = stream_socket_client ("tcp://" . $IP . ":" . $Port)))
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
      
      $this->closed ();
      $this->Online = false;
      @fclose ($this->getFD ());
      
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
      # TODO: Does anyone want to know the server?!
      return true;
    }
    // }}}
    
    // {{{ setConnection
    /**
     * Inherit Connection-Handle
     * 
     * @param resource $Socket
     * 
     * @access public
     * @return bool
     **/
    public function setConnection ($Socket) {
      // Make sure socket is of the right type
      if (!is_resource ($Socket))
        return false;
      
      $this->setFD ($Socket, true, false);
      $this->Online = true;
      
      $this->connected ();
      
      return true;
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
      $this->receive ($Data);
    }
    // }}}
    
    // {{{ getPeername
    /**
     * Retrive the address of the remove endpoint
     * 
     * @access public
     * @return string
     **/
    public function getPeername () {
      $Addr = stream_socket_get_name ($this->getFD (), true);
      
      return substr ($Addr, 0, strpos ($Addr, ':'));
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
      
      return stream_socket_sendto ($fd, $Data);
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
  }


?>