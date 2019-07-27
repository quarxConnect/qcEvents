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
  
  require_once ('qcEvents/Interface/Loop.php');
  require_once ('qcEvents/Interface/Server.php');
  require_once ('qcEvents/Trait/Hookable.php');
  require_once ('qcEvents/Socket.php');
  require_once ('qcEvents/Promise.php');
  
  /**
   * Server-Socket
   * -------------
   * Event-based Server-Sockets
   * 
   * @class qcEvents_Socket_Server
   * @package qcEvents
   * @revision 03
   **/
  class qcEvents_Socket_Server implements qcEvents_Interface_Loop, qcEvents_Interface_Server {
    use qcEvents_Trait_Hookable;
    
    /* Base-Class for Child-Connections */
    const CHILD_CLASS_BASE = 'qcEvents_Socket';
    
    /* Timeout-Values */
    const CHILD_UDP_TIMEOUT = 20;
    
    /* Server-Socket-Types */
    const TYPE_TCP = qcEvents_Socket::TYPE_TCP;
    const TYPE_UDP = qcEvents_Socket::TYPE_UDP;
    
    /* Our assigned event-loop */
    private $eventLoop = null;
    
    /* Our server-socket */
    private $Socket = null;
    
    /* Type of our server-socket */
    private $Type = qcEvents_Socket_Server::TYPE_TCP;
    
    /* Class for new child-connections (should be at least qcEvents_Socket) */
    private $childClass = qcEvents_Socket_Server::CHILD_CLASS_BASE;
    
    /* Use Child-Class as pipe-consumer, not as socket */
    private $childClassPiped = false;
    
    /* Registered hooks for our children */
    private $childHooks = array ();
    
    // Are we listening at the moment
    private $Listening = false;
    
    // All connections we handle (only in UDP-Mode)
    private $Clients = array ();
    
    /* Timer to time-out UDP-Connections */
    private $udpTimer = null;
    
    /* Preset of TLS-Options */
    private $tlsOptions = array (
      'ciphers' => 'ECDHE:!aNULL:!WEAK',
      'verify_peer' => false,
      'verify_peer_name' => false,
    );
    
    // {{{ __construct
    /**
     * Create a new server-process
     * 
     * @param qcEvents_Base $Base (optional) Event-Base to bind to
     * @param string $Host (optional) Hostname to listen on (may be null)
     * @param int $Port (optional) Port to listen on
     * @param enum $Type (optional) Type of socket to use (TCP/UDP)
     * @param string $Class (optional) Class for Child-Connections
     * @param bool $Piped (optional) Use Child-Class as Pipe-Consumer
     * @param array $Hooks (optional) Hooks for Child-Connections
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $Base = null, $Host = null, $Port = null, $Type = null, $Class = null, $Piped = false, $Hooks = null) {
      // Set our handler
      if ($Base !== null)
        $this->setEventBase ($Base);
      
      // Set child-class
      if ($Class !== null)
        $this->setChildClass ($Class, $Piped);
      
      // Register any hooks
      if (is_array ($Hooks))
        foreach ($Hooks as $Hook=>$Callback)
          $this->addChildHook ($Hook, $Callback);
      
      // Check wheter to setup
      if (($Type === null) || ($Port === null))
        return;
      
      // Put ourself into listenng-state
      $this->listen ($Type, $Port, $Host);
    }
    // }}}
    
    // {{{ getEventBase
    /**
     * Retrive the handle of the current event-loop-handler
     * 
     * @access public
     * @return qcEvents_Base May be NULL if none is assigned
     **/
    public function getEventBase () {
      return $this->eventLoop;
    }  
    // }}}
    
    // {{{ setEventBase
    /**
     * Set a new event-loop-handler
     * 
     * @param qcEvents_Base $Base
     * 
     * @access public
     * @return void  
     **/
    public function setEventBase (qcEvents_Base $Base) {
      // Check if the event-loop if different from the current one
      if ($Base === $this->eventLoop)
        return;
      
      // Remove ourself from the current event loop
      if ($this->eventLoop)
        $this->eventLoop->removeEvent ($this);
      
      // Assign the new event-loop
      $this->eventLoop = $Base;   
      
      $Base->addEvent ($this);
    }
    // }}}
    
    // {{{ unsetEventBase
    /**
     * Remove any assigned event-loop-handler
     * 
     * @access public
     * @return void  
     **/
    public function unsetEventBase () {
      if (!$this->eventLoop)
        return;
      
      $this->eventLoop->removeEvent ($this);
      $this->eventLoop = null;
    }
    // }}}
    
    // {{{ getReadFD
    /**
     * Retrive the stream-resource to watch for reads
     * 
     * @access public
     * @return resource May return NULL if no reads should be watched
     **/
    public function getReadFD () {
      return $this->Socket;
    }
    // }}}
    
    // {{{ getWriteFD
    /**
     * Retrive the stream-resource to watch for writes
     * 
     * @access public
     * @return resource May return NULL if no writes should be watched
     **/
    public function getWriteFD () { }
    // }}}
    
    // {{{ getErrorFD
    /**
     * Retrive an additional stream-resource to watch for errors
     * @remark Read-/Write-FDs are always monitored for errors
     * 
     * @access public
     * @return resource May return NULL if no additional stream-resource should be watched
     **/
    public function getErrorFD () {
      return null;  
    }
    // }}}
    
    // {{{ getWriteFDforClient
    /**
     * Retrive the Write-FD for one of our clients
     * 
     * @param qcEvents_Socket $Client
     * 
     * @access public
     * @return resource
     **/
    public function getWriteFDforClient (qcEvents_Socket $Client) {
      if (($this->Type != self::TYPE_UDP) || !in_array ($Client, $this->Clients, true))
        return false;
      
      return $this->Socket;
    }
    // }}}
    
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
    public function setChildClass ($Classname, $Piped = false) {
      // Verify the class
      if ((!$Piped && !is_a ($Classname, $this::CHILD_CLASS_BASE, true)) ||
          ($Piped && !is_a ($Classname, 'qcEvents_Interface_Consumer', true) && !is_a ($Classname, 'qcEvents_Interface_Stream_Consumer', true))) {
        trigger_error ($Classname . ' has to implement ' . ($Piped ? 'qcEvents_Interface_Consumer or qcEvents_Interface_Stream_Consumer' : $this::CHILD_CLASS_BASE));
        
        return false;
      }
      
      // Set the class
      $this->childClass = $Classname;
      $this->childClassPiped = $Piped;
      
      return true;
    }
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
    public function addChildHook ($Name, $Callback, $Private = null) {
      // Check if this is a valid callback
      if (!is_callable ($Callback))
        return false;
      
      // Register the hook
      if (!isset ($this->childHooks [$Name]))
        $this->childHooks [$Name] = array (array ($Callback, $Private));
      else
        $this->childHooks [$Name][] = array ($Callback, $Private);
      
      return true;
    }
    // }}}
    
    // {{{ getLocalName
    /**
     * Retrive the local sock-addr-spec of this server
     * 
     * @access public
     * @return string
     **/
    public function getLocalName () {
      $Local = stream_socket_get_name ($this->Socket, false);
      
      if (substr ($Local, 0, 3) == ':::')
        $Local = gethostname () . substr ($Local, 2);
      elseif (substr ($Local, 0, 7) == '0.0.0.0')
        $Local = gethostname () . substr ($Local, 7);
      
      return $Local;
    }
    // }}}
    
    // {{{ getLocalPort
    /**
     * Retrive the local port of this server
     * 
     * @access public
     * @return int
     **/
    public function getLocalPort () {
      $Local = stream_socket_get_name ($this->Socket, false);
      
      if (($p = strrpos ($Local, ':')) === false)
        return false;
      
      return (int)substr ($Local, $p + 1);
    }
    // }}}
    
    // {{{ tlsCiphers
    /**
     * Set a list of supported TLS-Ciphers
     * 
     * @param array $Ciphers
     * 
     * @access public
     * @return void
     **/
    public function tlsCiphers (array $Ciphers) {
      $this->tlsOptions ['ciphers'] = implode (':', $Ciphers);
    }
    // }}}
    
    // {{{ tlsCertificate
    /**
     * Setup TLS-Certificates for this end
     * 
     * The Certificate-File has contain both key and certificate in PEM-Format,
     * an optional CA-Chain may be included as well.
     * 
     * @param string $certFile
     * @param array $sniCerts (optional)
     * 
     * @access public
     * @return void
     **/
    public function tlsCertificate ($certFile, array $sniCerts = null) {
      $this->tlsOptions ['local_cert'] = $certFile;
      
      if ($sniCerts !== null)
        $this->tlsOptions ['SNI_server_certs'] = $sniCerts;
    }
    // }}}
    
    // {{{ tlsVerify
    /**
     * Set verification-options for TLS-secured connections
     * 
     * @param bool $Verify (optional) Verify the peer (default)
     * @param bool $VerifyName (optional) Verify peers name (default)
     * @param bool $SelfSigned (optional) Allow self signed certificates
     * @param string $caFile (optional) File or Directory containing CA-Certificates
     * @param int $Depth (optional) Verify-Depth
     * @param string $Fingerprint (optional) Expected fingerprint of peers certificate
     * 
     * @access public
     * @return void
     **/
    public function tlsVerify ($Verify = false, $VerifyName = false, $SelfSigned = false, $caFile = null, $Depth = null, $Fingerprint = null) {
      if ($Verify !== null)
        $this->tlsOptions ['verify_peer'] = !!$Verify;
      
      if ($VerifyName !== null)
        $this->tlsOptions ['verify_peer_name'] = !!$VerifyName;
      
      if ($SelfSigned !== null)
        $this->tlsOptions ['allow_self_signed'] = !!$SelfSigned;
      
      if ($caFile !== null) {
        if (is_dir ($caFile))
          $this->tlsOptions ['capath'] = $caFile;
        else
          $this->tlsOptions ['cafile'] = $caFile;
      }

      if ($Depth !== null)
        $this->tlsOptions ['verify_depth'] = $Depth;
      
      if ($Fingerprint !== null)
        $this->tlsOptions ['peer_fingerprint'] = $Fingerprint;
    }
    // }}}
    
    // {{{ tlsOptions
    /**
     * Retrive TLS-Options for this server
     * 
     * @access public
     * @return array
     **/
    public function tlsOptions () {
      return $this->tlsOptions;
    }
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
    public function listen ($Type, $Port = null, $Host = null, $Backlog = null) {
      // Handle Context
      if ($Backlog !== null)
        $Context = stream_context_create (array ('backlog' => $Backlog));
      else
        $Context = stream_context_create (array ());
      
      if ($Host === null)
        $Host = '[::]';
      
      // Create the socket
      if ($Type == self::TYPE_UDP) {
        $Proto = 'udp';
        $Flags = STREAM_SERVER_BIND;
      } elseif ($Type == self::TYPE_TCP) {
        $Proto = 'tcp';
        $Flags = STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
      } else
        return false;
      
      if ($Port === null)
        $Port = 0;
      
      if (!is_resource ($Socket = stream_socket_server ($Proto . '://' . $Host . ':' . $Port, $ErrNo, $ErrStr, $Flags, $Context)))
        return false;
      
      $this->setServerSocket ($Socket, $Type, true);
      
      // Fire callback
      $this->___callback ('serverOnline');
      
      return true;
    }
    // }}}
    
    // {{{ setServerSocket
    /**
     * Internally override our server-socket
     * 
     * @param resource $Socket
     * @param enum $Type
     * @param bool $Listening
     * 
     * @access protected
     * @return void
     **/
    protected function setServerSocket ($Socket, $Type, $Listening) {
      // Update ourself
      $this->Socket = $Socket;
      $this->Type = $Type;
      $this->Listening = $Listening;
      
      // Update our parent
      if ($this->eventLoop)
        $this->eventLoop->updateEvent ($this);
    }
    // }}}
    
    // {{{ close
    /**
     * Close this event-interface
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function close () : qcEvents_Promise {
      // Check wheter to really close the server
      if ($Open = $this->Socket) {
        fclose ($this->Socket);
        
        $this->Socket = null;
      }
      
      // Raise Callbacks
      if ($Open)
        $this->___callback ('serverOffline');
      
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    // {{{ raiseRead
    /**
     * Handle events on our server-socket
     * 
     * @access public
     * @return void
     **/
    public final function raiseRead () {
      // Handle UDP-Events
      if ($this->Type == self::TYPE_UDP) {
        if (($Data = stream_socket_recvfrom ($this->Socket, qcEvents_Socket::READ_UDP_BUFFER, 0, $Remote)) === false)
          # TODO: What to do here?
          return false;
        
        if (substr ($Remote, 0, 7) == '::ffff:')
          $Remote = '[' . substr ($Remote, 0, strrpos ($Remote, ':')) . ']' . substr ($Remote, strrpos ($Remote, ':'));
        
        // Create a client-handle if there is none yet
        if (!isset ($this->Clients [$Remote])) {
          // Fire callback first
          if ($this->___callback ('serverClientAccept', $Remote, null) === false)
            return;
          
          // Create the client
          $this->Clients [$Remote] = $Client = $this->serverCreateChild ($Remote);
          
          // Make sure we have a timer
          if (!$this->udpTimer && $this->eventLoop) {
            $this->udpTimer = $this->eventLoop->addTimeout (max (2, intval (self::CHILD_UDP_TIMEOUT / 4)), true);
            $this->udpTimer->then (
              function () {
                // Retrive the actual time
                $Now = time ();
                
                // Check all clients
                foreach ($this->Clients as $Client)
                  if ($Now - $Client->getLastEvent () > self::CHILD_UDP_TIMEOUT)
                    $Client->close ();
              }
            );
          }
        
        // Peek the client from storage
        } else
          $Client = $this->Clients [$Remote];
        
        // Forward the data to the client
        return $Client->readUDPServer ($Data, $this);
      
      // Handle TCP-Events (accept an incoming connection)
      } elseif ($this->Type == self::TYPE_TCP) {
        // Accept incoming connection
        if (!is_resource ($Connection = stream_socket_accept ($this->Socket, 0, $Remote)))
          return false;
        
        stream_context_set_option ($Connection, array ('ssl' => $this->tlsOptions));
        
        // Fire callback first
        if ($this->___callback ('serverClientAccept', $Remote, $Connection) === false)
          return;
        
        // Create new Client if neccessary
        $this->Clients [$Remote] = $this->serverCreateChild ($Remote, $Connection);
      }
    }
    // }}}
    
    // {{{ raiseWrite
    /**
     * Callback: Handle Write-Events (not gonna happen, but the interface wants us to have this function)
     * 
     * @access public
     * @return void
     **/
    public function raiseWrite () { }
    // }}}
    
    // {{{ raiseError
    /**
     * Callback: There was an error on our socket
     * 
     * @param resource $fd
     * 
     * @access public
     * @return void
     **/
    public function raiseError ($fd) {
      trigger_error ('Error on server-socket');
      
      $this->close ();
    }
    // }}}
    
    // {{{ serverCreateChild
    /**
     * Create a new child-class
     * 
     * @param string $Remote
     * @param resource $Connection (optional)
     * 
     * @access private
     * @return qcEvents_Socket
     **/
    private function serverCreateChild ($Remote, $Connection = null) {
      // Create Socket and client
      if ($this->childClassPiped) {
        $socketClass = $this::CHILD_CLASS_BASE;
        $Socket = new $socketClass ($this->getEventBase ());
        $Client = new $this->childClass ($this->getEventBase ());
      } else
        $Client = $Socket = new $this->childClass ($this->getEventBase ());
      
      // Register hooks at the child
      foreach ($this->childHooks as $Hook=>$Hooks)
        foreach ($Hooks as $Info) {
          @$Client->addHook ($Hook, $Info [0], $Info [1]);
          
          if ($Client !== $Socket)
            @$Socket->addHook ($Hook, $Info [0], $Info [1]);
        }
      
      // Check wheter to enable TLS
      $Options = stream_context_get_options ($Connection);
      $enableTLS = (isset ($Options ['ssl']) && (isset ($Options ['ssl']['local_cert']) || isset ($Options ['ssl']['SNI_server_certs'])));
      
      // Register ourself at the child
      $Socket->connectServer ($this, $Remote, $Connection, $enableTLS);
      
      // Pipe if client and socket are not the same
      if ($Socket !== $Client) {
        if ($Client instanceof qcEvents_Interface_Stream_Consumer)
          $Socket->pipeStream ($Client);
        else
          $Socket->pipe ($Client);
      }
      
      $this->___callback ('serverClientNew', $Socket, $Client);
      
      return $Socket;
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
    
    
    // {{{ serverOnline
    /**
     * Callback: The server went into listening state
     * 
     * @access protected
     * @return void
     **/
    protected function serverOnline () { }
    // }}}
    
    // {{{ serverOffline
    /**
     * Callback: The server was closed
     * 
     * @access protected
     * @return void
     **/
    protected function serverOffline () { }
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
    protected function serverClientAccept ($Remote, $Socket = null) { }
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
    protected function serverClientNew (qcEvents_Socket $Socket, $Consumer = null) { }
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
    protected function serverClientClosed ($Remote, qcEvents_Socket $Socket) { }
    // }}}
  }

?>