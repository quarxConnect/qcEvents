<?php

  /**
   * quarxConnect Events - Multi-Purpose Server Interface
   * Copyright (C) 2013-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  declare (strict_types=1);

  namespace quarxConnect\Events\Socket;
  use \quarxConnect\Events;
  
  /**
   * Server-Socket
   * -------------
   * Event-based Server-Sockets
   * 
   * @class Server
   * @package \quarxConnect\Events
   * @revision 03
   **/
  class Server implements Events\ABI\Loop, Events\ABI\Server {
    use Events\Feature\Hookable;
    use Events\Feature\Based;
    
    /* Base-Class for Child-Connections */
    protected const CHILD_CLASS_BASE = Events\Socket::class;
    
    /* Timeout-Values */
    protected const CHILD_UDP_TIMEOUT = 20;
    
    /* Server-Socket-Types */
    public const TYPE_TCP = Events\Socket::TYPE_TCP;
    public const TYPE_UDP = Events\Socket::TYPE_UDP;
    
    /* Our server-socket */
    private $Socket = null;
    
    /* Type of our server-socket */
    private $Type = Server::TYPE_TCP;
    
    /* Class for new child-connections (should be at least qcEvents_Socket) */
    private $childClass = Server::CHILD_CLASS_BASE;
    
    /* Use Child-Class as pipe-consumer, not as socket */
    private $childClassPiped = false;
    
    /* Registered hooks for our children */
    private $childHooks = [ ];
    
    // Are we listening at the moment
    private $Listening = false;
    
    // All connections we handle (only in UDP-Mode)
    private $Clients = [ ];
    
    /* Timer to time-out UDP-Connections */
    private $udpTimer = null;
    
    /* Preset of TLS-Options */
    private $tlsOptions = [
      'ciphers' => 'ECDHE:!aNULL:!WEAK',
      'verify_peer' => false,
      'verify_peer_name' => false,
    ];
    
    // {{{ __construct
    /**
     * Create a new server-process
     * 
     * @param Events\Base $Base (optional) Event-Base to bind to
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
    function __construct (Events\Base $Base = null, string $Host = null, int $Port = null, int $Type = null, string $Class = null, bool $Piped = false, array $Hooks = null) {
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
      if ($Type === null)
        return;
      
      // Put ourself into listenng-state
      $this->listen ($Type, $Port, $Host);
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
    public function getWriteFD () {
      return null;
    }
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
     * @param Events\Socket $Client
     * 
     * @access public
     * @return resource
     **/
    public function getWriteFDforClient (Events\Socket $Client) {
      if (($this->Type != self::TYPE_UDP) || !in_array ($Client, $this->Clients, true))
        return null;
      
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
    public function setChildClass (string $Classname, bool $Piped = false) : bool {
      // Verify the class
      if ((!$Piped && !is_a ($Classname, $this::CHILD_CLASS_BASE, true)) ||
          ($Piped && !is_a ($Classname, Events\ABI\Consumer::class, true) && !is_a ($Classname, Events\ABI\Stream_Consumer::class, true))) {
        trigger_error ($Classname . ' has to implement ' . ($Piped ? Events\ABI\Consumer::class . ' or ' . Events\ABI\Stream\Consumer::class : $this::CHILD_CLASS_BASE));
        
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
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function addChildHook (string $Name, callable $Callback, $Private = null) : void {
      // Register the hook
      if (!isset ($this->childHooks [$Name]))
        $this->childHooks [$Name] = [ [ $Callback, $Private ] ];
      else
        $this->childHooks [$Name][] = [ $Callback, $Private ];
    }
    // }}}
    
    // {{{ getLocalName
    /**
     * Retrive the local sock-addr-spec of this server
     * 
     * @access public
     * @return string
     **/
    public function getLocalName () : string {
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
    public function getLocalPort () : int {
      $Local = stream_socket_get_name ($this->Socket, false);
      
      if (($p = strrpos ($Local, ':')) === false)
        throw new \ValueError ('Missing separator on socket-name');
      
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
    public function tlsCiphers (array $Ciphers) : void {
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
    public function tlsCertificate (string $certFile, array $sniCerts = null) : void {
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
    public function tlsVerify (bool $Verify = false, bool $VerifyName = false, bool $SelfSigned = false, string $caFile = null, int $Depth = null, string $Fingerprint = null) : void {
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
    public function tlsOptions () : array {
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
    public function listen (int $Type, int $Port = null, string $Host = null, int $Backlog = null) : bool {
      // Handle Context
      if ($Backlog !== null)
        $Context = stream_context_create ([ 'backlog' => $Backlog ]);
      else
        $Context = stream_context_create ([ ]);
      
      if ($Host === null)
        $Host = '[::]';
      
      // Create the socket
      if ($Type == self::TYPE_UDP) {
        $Proto = 'udp';
        $Flags = \STREAM_SERVER_BIND;
      } elseif ($Type == self::TYPE_TCP) {
        $Proto = 'tcp';
        $Flags = \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN;
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
    protected function setServerSocket ($Socket, int $Type, bool $Listening) : void {
      // Update ourself
      $this->Socket = $Socket;
      $this->Type = $Type;
      $this->Listening = $Listening;
      
      // Update our parent
      if ($eventBase = $this->getEventBase ())
        $eventBase->updateEvent ($this);
    }
    // }}}
    
    // {{{ close
    /**
     * Close this event-interface
     * 
     * @access public
     * @return Events\Promise
     **/
    public function close () : Events\Promise {
      // Check wheter to really close the server
      if ($Open = $this->Socket) {
        fclose ($this->Socket);
        
        $this->Socket = null;
      }
      
      // Raise Callbacks
      if ($Open)
        $this->___callback ('serverOffline');
      
      return Events\Promise::resolve ();
    }
    // }}}
    
    // {{{ raiseRead
    /**
     * Handle events on our server-socket
     * 
     * @access public
     * @return void
     **/
    public final function raiseRead () : void {
      // Handle UDP-Events
      if ($this->Type == self::TYPE_UDP) {
        if (($Data = stream_socket_recvfrom ($this->Socket, qcEvents_Socket::READ_UDP_BUFFER, 0, $Remote)) === false)
          # TODO: What to do here?
          return;
        
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
          if (!$this->udpTimer && ($eventBase = $this->getEventBase ())) {
            $this->udpTimer = $eventBase->addTimeout (max (2, intval (self::CHILD_UDP_TIMEOUT / 4)), true);
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
        $Client->readUDPServer ($Data, $this);
      
      // Handle TCP-Events (accept an incoming connection)
      } elseif ($this->Type == self::TYPE_TCP) {
        // Accept incoming connection
        if (!is_resource ($Connection = stream_socket_accept ($this->Socket, 0, $Remote)))
          return;
        
        stream_context_set_option ($Connection, [ 'ssl' => $this->tlsOptions ]);
        
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
    public function raiseWrite () : void { }
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
    public function raiseError ($fd) : void {
      trigger_error ('Error on server-socket');
      
      $this->close ();
    }
    // }}}
    
    // {{{ serverCreateChild
    /**
     * Create a new child-class
     * 
     * @param string $Remote
     * @param resource $childConnection (optional)
     * 
     * @access private
     * @return Events\Socket
     **/
    private function serverCreateChild (string $Remote, $childConnection = null) : Events\Socket {
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
      if ($childConnection !== null) {
        $contextOptions = stream_context_get_options ($childConnection);
        $enableTLS = (isset ($contextOptions ['ssl']) && (isset ($contextOptions ['ssl']['local_cert']) || isset ($contextOptions ['ssl']['SNI_server_certs'])));
      } else
        $enableTLS = false;
      
      // Register ourself at the child
      $Socket->connectServer ($this, $Remote, $childConnection, $enableTLS);
      
      // Pipe if client and socket are not the same
      if ($Socket !== $Client) {
        if ($Client instanceof Events\ABI\Stream\Consumer)
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
     * @param Events\Socket $Child
     * 
     * @access public
     * @return bool
     **/
    public function disconnectChild (Events\Socket $Child) : bool {
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
    protected function serverOnline () : void { }
    // }}}
    
    // {{{ serverOffline
    /**
     * Callback: The server was closed
     * 
     * @access protected
     * @return void
     **/
    protected function serverOffline () : void { }
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
    protected function serverClientAccept (string $Remote, $Socket = null) : ?bool {
      return null;
    }
    // }}}
    
    // {{{ serverClientNew
    /**
     * Callback: A new client was created
     * 
     * @param Events\Socket $Client
     * @param mixed $Consumer
     * 
     * @access protected
     * @return void
     **/
    protected function serverClientNew (Events\Socket $Socket, $Consumer = null) : void { }
    // }}}
    
    // {{{ serverClientClosed
    /**
     * Callback: Client-Connection was/will be closed
     * 
     * @param string $Remote
     * @param Events\Socket $Client
     * 
     * @access protected
     * @return void
     **/
    protected function serverClientClosed (string $Remote, Events\Socket $Socket) : void { }
    // }}}
  }
