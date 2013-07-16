<?PHP

  /**
   * qcEvents - Asyncronous Sockets
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
  
  /**
   * Event Socket
   * ------------
   * Generic implementation to handle internet-based connections
   * 
   * @class qcEvents_Socket
   * @package qcEvents
   * @revision 02
   **/
  class qcEvents_Socket extends qcEvents_Event {
    /* Socket-Types */
    const TYPE_TCP = 0;
    const TYPE_UDP = 1;
    const TYPE_UDP_SERVER = 2;
    
    /* Timeouts */
    const CONNECT_TIMEOUT = 5;
    
    /* Buffers */
    const READ_TCP_BUFFER = 4096;
    const READ_UDP_BUFFER = 1500;
    
    /* Defaults */
    const DEFAULT_TYPE = qcEvents_Socket::TYPE_TCP;
    const DEFAULT_PORT = null;
    
    const FORCE_TYPE = null;
    const FORCE_PORT = null;
    
    /* Our connection-state */
    private $Connected = false;
    
    /* Socket-Type of this connection */
    private $Type = self::TYPE_TCP;
    
    /* Any assigned server-handle */
    private $serverParent = null;
    
    /* Set of addresses we are trying to connectl to */
    private $socketAddresses = null;
    
    /* The current address we are trying to connect to */
    private $socketAddress = null;
    
    /* Our current remote hostname */
    private $remoteHost = '';
    
    /* Address of our current remote host */
    private $remoteAddr = '';
    
    /* Out current remote port */
    private $remotePort = 0;
    
    /* Short-hand of remote hostname and port (for UDP-Server-Mode) */
    private $remoteName = '';
    
    /* Our current TLS-Status */
    private $tlsEnabled = false;
    
    /* Our desired TLS-Status */
    private $tlsStatus = null;
    
    /* Callback fired when tls-status was changed */
    private $tlsCallback = null;
    
    /* Private Data for TLS-Callback */
    private $tlsPrivate = null;
    
    /* Size for Read-Requests */
    private $bufferSize = 0;
    
    /* Run in buffered mode */
    private $isBuffered = false;
    
    /* Local read-buffer */
    private $readBuffer = '';
    
    /* Local write-buffer */
    private $writeBuffer = '';
    
    /* Time of last event on this socket */
    private $lastEvent = 0;
    
    /* Use our own internal resolver (which works asyncronously as well) */
    private $internalResolver = true;
    
    // {{{ isIPv4
    /**
     * Check if a given address is valid IPv4
     * 
     * @param string $Address
     * 
     * @access public
     * @return bool
     **/
    public static function isIPv4 ($Address) {
      // Split the address into its pieces
      $Check = explode ('.', $Address);
      
      // Check if there are exactly 4 blocks
      if (count ($Check) != 4)
        return false;
      
      // Validate each block
      foreach ($Check as $Block)
        if (!is_numeric ($Block) || ($Block < 0) || ($Block > 255))
          return false;
      
      return true;
    }
    // }}}
    
    // {{{ __construct
    /**
     * Create a new event-socket
     * 
     * @param qcEvents_Base $Base (optional)
     * @param string $Host (optional)
     * @param int $Port (optional)
     * @param enum $Type (optional)
     * @param bool $enableTLS (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $Base = null, $Host = null, $Port = null, $Type = null, $enableTLS = false) {
      // Don't do anything withour an events-base
      if ($Base === null)
        return;
      
      // Set our handler
      $this->setEventBase ($Base);
      
      // Check wheter to create a connection
      if ($Host === null)
        return;
      
      $this->connect ($Host, $Port, $Type, $enableTLS);
    }
    // }}}
    
    // {{{ __destruct
    /**
     * Cleanly close our connection upon destruction
     * 
     * @access friendly
     * @return void
     **/
    function __destruct () {
      if ($this->isConnected ())
        $this->disconnect ();
    }
    // }}}
    
    // {{{ __sleep
    /**
     * Close any open connection whenever someone tries to put ourself to sleep
     * 
     * @access friendly
     * @return void
     **/
    function __sleep () {
      if ($this->isConnected ())
        $this->disconnect ();
      
      return array ('Type');
    }
    // }}}
    
    // {{{ __wakeup
    /**
     * Give a warning if someone unserializes us
     * 
     * @access friendly
     * @return void
     **/
    function __wakeup () {
      trigger_error ('Sockets may not be unserialized, remember that this connection is lost now', E_USER_NOTICE);
    }
    // }}}
    
    
    // {{{ connect
    /**
     * Create a connection
     * 
     * @param mixed $Hosts
     * @param int $Port
     * @param enum $Type (optional) TCP is used by default
     * @param bool $enableTLS (optional) Enable TLS-Encryption on connect
     * @param qcEvents_Base $newBase (optional) Try to bind to this event-base
     * 
     * @remark This function is asyncronous! If it returns true this does not securly mean that a connection was established!
     * @todo Add support for "bindto"-Option
     * 
     * @access public
     * @return bool
     **/
    public function connect ($Hosts, $Port = null, $Type = null, $enableTLS = false, qcEvents_Base $newBase = null) {
      // Check wheter to use the default socket-type
      if ($Type === null)
        $Type = $this::DEFAULT_TYPE;
      
      if ($this::FORCE_TYPE !== null)
        $Type = $this::FORCE_TYPE;
      
      // Validate the type-parameter
      if (($Type != self::TYPE_TCP) && ($Type != self::TYPE_UDP))
        return false;
      
      // Check wheter to use a default port
      if ($Port === null) {
        $Port = $this::DEFAULT_PORT;
        
        if ($Port === null)
          return false;
      }
      
      if ($this::FORCE_PORT !== null)
        $Port = $this::FORCE_PORT;
      
      // Make sure we have an event-base assigned
      if (!$this->haveEventBase () && (($newBase === null) || !$this->setEventBase ($newBase)))
        return false;
      
      // Try to close any open connection before creating a new one
      if (!$this->isDisconnected () && !$this->disconnect ())
        return false;
      
      // Reset internal addresses
      $this->socketAddresses = null;
      $this->socketAddress = null;
      $this->tlsStatus = ($enableTLS ? true : null);
      
      // Make sure hosts is an array
      if (!is_array ($Hosts))
        $Hosts = array ($Hosts);
      
      $Resolve = array ();
      
      foreach ($Hosts as $Host) {
        // Check for IPv6
        if ($IPv6 = (strpos ($Host, ':') !== false) && ($Host [0] != '['))
          $Host = '[' . $Host . ']';
        
        // Check for IPv4/v6 or wheter to skip the resolver
        if (!$this->internalResolver || $this->isIPv4 ($Host) || $IPv6)
          $this->socketAddresses = array (array ($Host, $Host, $Port, $Type));
        else
          $Resolve [] = $Host;
      }
      
      // Put ourself into connected-state
      $this->Connected = null;
      
      // Check if we have addresses to connect to
      if (count ($this->socketAddresses) > 0)
        $this->connectMulti ();
      
      // Sanity-Check if to use internal resolver
      if (!$this->internalResolver || (count ($Resolve) == 0))
        return true;
      
      // Perform asyncronous resolve
      return $this->socketResolveDo ($Host, $Port, $Type);
    }
    // }}}
    
    // {{{ connectService
    /**
     * Create a connection to a named service on a given domain
     * by using DNS-SRV
     * 
     * @param string $Domain
     * @param string $Service
     * @param enum $Type (optional)
     * @param bool $enableTLS (optional)
     * @param qcEvents_Base $newBase (optional)
     * 
     * @access public
     * @return void
     **/
    public function connectService ($Domain, $Service, $Type = null, $enableTLS = false, qcEvents_Base $newBase = null) {
      // Check wheter to use the default socket-type
      if ($Type === null)
        $Type = $this::DEFAULT_TYPE;
      
      if ($this::FORCE_TYPE !== null)
        $Type = $this::FORCE_TYPE;
      
      // Validate the type-parameter
      if (($Type != self::TYPE_TCP) && ($Type != self::TYPE_UDP))
        return false;
      
      // Make sure we have an event-base assigned
      if (!$this->haveEventBase () && (($newBase === null) || !$this->setEventBase ($newBase)))
        return false;
      
      // Try to close any open connection before creating a new one
      if (!$this->isDisconnected () && !$this->disconnect ())
        return false;
      
      // Reset internal addresses
      $this->socketAddresses = null;
      $this->socketAddress = null;
      $this->tlsStatus = ($enableTLS ? true : null);
      $this->Connected = null;
      $this->lastEvent = time ();
      
      // Generate label to look up
      $Label = '_' . $Service . '._' . ($Type == self::TYPE_UDP ? 'udp' : 'tcp') . '.' . $Domain;
      
      // Perform syncronous lookup
      if ($this->internalResolver === false) {
        // Fire a callback
        $this->___callback ('socketResolve', array ($Label));
        
        // Do the DNS-Lookup
        if (!is_array ($Result = dns_get_record ($Label, DNS_SRV, $AuthNS, $Addtl)) || (count ($Result) == 0))
          return $this->socketConnectTimeout ();
        
        // Forward the result
        return $this->socketResolverResultArray ($Result, $Addtl, $Domain, null, $Type);
      }
      
      // Perform asyncronous lookup
      require_once ('qcEvents/Socket/Client/DNS.php');
      
      return $this->socketResolveDo ($Label, null, $Type, qcEvents_Socket_Stream_DNS_Message::TYPE_SRV);
    }
    // }}}
     
    // {{{ connectMulti
    /**
     * Try to connect to next host on our list
     * 
     * @access private
     * @return void
     **/
    private function connectMulti () {
      // Check if there are addresses on the queue
      if (!is_array ($this->socketAddresses) || (count ($this->socketAddresses) == 0) || ($this->socketAddress !== null))
        return false;
      
      // Get the next address
      $this->socketAddress = array_shift ($this->socketAddresses);
      
      // Fire a callback for this
      $this->___callback ('socketTryConnect', $this->socketAddress [0], $this->socketAddress [1], $this->socketAddress [2], $this->socketAddress [3]);
      
      // Create new client-socket
      $URI = ($this->socketAddress [3] === self::TYPE_TCP ? 'tcp' : 'udp') . '://' . $this->socketAddress [1] . ':' . $this->socketAddress [2];
      
      if (!is_resource ($Socket = stream_socket_client ($URI, $errno, $err, 5, STREAM_CLIENT_ASYNC_CONNECT)))
        return false;
        
      // Set our new status
      if (!$this->setFD ($Socket, true, true, true))
        return false;
      
      // Make sure we are bound
      $this->bind ();
      
      $this->bufferSize = ($this->socketAddress [3] === self::TYPE_UDP ? self::READ_UDP_BUFFER : self::READ_TCP_BUFFER);
      $this->lastEvent = time ();
      
      // Set our connection-state
      if ($this->socketAddress [3] === self::TYPE_UDP ? true : null)
        $this->socketHandleConnected ();
      else
        $this->addTimeout (self::CONNECT_TIMEOUT, false, array ($this, 'socketConnectTimeout'));
      
      return true;
    }
    // }}}
    
    // {{{ connectServer
    /**
     * Use this connection as Server-Slave
     * 
     * @param qcEvents_Socket_Server $Server
     * @param string $Remote
     * @param resource $Connection (optional)
     * @param bool $enableTLS (optional)
     * 
     * @remark This is for internal use only!
     * 
     * @access public
     * @return void
     **/
    public final function connectServer (qcEvents_Socket_Server $Server, $Remote, $Connection = null, $enableTLS = false) {
      // Set our internal buffer-size
      if ($Connection === null) {
        $this->bufferSize = self::READ_UDP_BUFFER;
        
        // Store short-hand for UDP-Writes
        $this->remoteName = $Remote;
      } else {
        $this->bufferSize = self::READ_TCP_BUFFER;
        
        // Store the connection
        $this->setFD ($Connection, true, false);
      }
      
      // Store our parent server-handle
      $this->serverParent = $Server;
      
      // Fake remote socket-settings
      $p = strrpos ($Remote, ':');
      
      $this->socketAddress = array (
        substr ($Remote, 0, $p),
        substr ($Remote, 0, $p),
        intval (substr ($Remote, $p + 1)),
        ($Connection === null ? self::TYPE_UDP_SERVER : self::TYPE_TCP)
      );
      
      // Put ourself into connected state
      $this->socketHandleConnected ();
    }
    // }}}
    
    // {{ socketHandleConnected
    /**
     * Internal Callback: Our socket is now in connected state
     * 
     * @access private
     * @return void
     **/
    private function socketHandleConnected () {
      if ($this->Connected !== true) {
        // Set connection-status
        $this->Connected = true;
        
        // Set runtime-information
        $this->Type = $this->socketAddress [3];
        $this->remoteHost = $this->socketAddress [0];
        $this->remoteAddr = $this->socketAddress [1];
        $this->remotePort = $this->socketAddress [2];
        
        // Free some space now
        $this->socketAddress = null;
        $this->socketAddresses = null;
        
        // Destroy our resolver
        if (is_object ($this->internalResolver)) {
          $this->internalResolver->unbind ();
          $this->internalResolver = true;
        }
        
        // Check wheter to enable TLS
        if (($this->tlsStatus === true) && !$this->tlsEnable ())
          return $this->tlsEnable (true, array ($this, 'socketHandleConnected'));
      }
      
      // Fire the callback
      $this->___callback ('socketConnected');
    }
    // }}}
    
    // {{{ socketHandleConnectFailed
    /**
     * Internal Callback: Pending connection could not be established
     * 
     * @access private
     * @return void
     **/
    private function socketHandleConnectFailed () {
      // Mark this host as failed
      if ($this->socketAddress !== null) {
        $this->___callback ('socketTryConnectFailed', $this->socketAddress [0], $this->socketAddress [1], $this->socketAddress [2], $this->socketAddress [3]);
        $this->socketAddress = null;
      }
      
      // Check if there are more hosts on our list
      if (!is_array ($this->socketAddresses) || (count ($this->socketAddresses) == 0)) {
        $this->___callback ('socketConnectionFailed');
        
        return $this->disconnect ();
      }
      
      // Try the next host
      return $this->connectMulti ();
    }
    // }}}
    
    // {{{ socketResolveDo
    /**
     * Resolve a given hostname
     * 
     * @param string $Hostname
     * @param int $Port
     * @param enum $Type
     * @param array $Types (optional)
     * 
     * @access private
     * @return void
     **/
    private function socketResolveDo ($Hostname, $Port, $Type, $Types = null) {
      // Don't do further resolves if we are already connected
      if ($this->isConnected ())
        return false;
      
      // Create a new resolver
      if (!is_object ($this->internalResolver)) {
        require_once ('qcEvents/Socket/Client/DNS.php');
        
        $this->internalResolver = new qcEvents_Socket_Client_DNS ($this->getEventBase ());
      }
      
      // Check which types to resolve
      if ($Types === null)
        $Types = array (  
          qcEvents_Socket_Stream_DNS_Message::TYPE_A,
          qcEvents_Socket_Stream_DNS_Message::TYPE_AAAA,
          qcEvents_Socket_Stream_DNS_Message::TYPE_CNAME,
        );
      elseif (!is_array ($Types))
        $Types = array ($Types);
      
      // Enqueue Hostnames
      $Private = array (
        $Hostname,
        $Port,
        $Type
      );
      
      foreach ($Types as $Type)
        $this->internalResolver->resolve ($Hostname, $Type, null, array ($this, 'socketResolverResult'), $Private);
      
      // Update last action
      $this->lastEvent = time ();
      
      // Fire a callback
      $this->___callback ('socketResolve', array ($Hostname));
      
      // Setup a timeout
      $this->addTimeout (self::CONNECT_TIMEOUT, false, array ($this, 'socketConnectTimeout'));
    }
    // }}}
    
    // {{{ socketResolverResult
    /**
     * Internal Callback: Our resolver returned a result
     * 
     * @param qcEvents_Socket_Client_DNS $Resolver
     * @param string $orgHostname
     * @param array $Answers
     * @param array $Authorities
     * @param $Additionals
     * 
     * @access public
     * @return void
     **/
    public function socketResolverResult (qcEvents_Socket_Client_DNS $Resolver, $orgHostname, $Answers, $Authorities, $Additionals, $Private, $Message) {
      // Discard any result if we are connected already
      if ($this->isConnected ())
        return;
      
      // Check if the given resolver is our own
      if ($Resolver !== $this->internalResolver)
        return false;
      
      // Update our last event (to prevent a pending disconnect)
      $this->lastEvent = time ();
      
      // Handle all results
      if ($Message !== null)
        $Result = $Resolver->dnsConvertPHP ($Message, $AuthNS, $Addtl);
      else
        $Result = $Addtl = array ();
      
      return $this->socketResolverResultArray ($Result, $Addtl, $Private [0], $Private [1], $Private [2]);
    }
    
    // {{{ socketResolverResultArray
    /**
     * Handle the result of from any resolve-process
     * 
     * @param array $Results Results returned from the resolver
     * @param array $Addtl Additional results returned from the resolver
     * @param string $Hostname The Hostname we are looking for
     * @param int $Port The port we want to connect to
     * @param enum $Type The type of socket we wish to create
     * 
     * @access private
     * @return void
     **/
    private function socketResolverResultArray ($Results, $Addtl, $Hostname, $Port, $Type) {
      // Check if there are no results
      if ((count ($Results) == 0) && (!is_object ($this->internalResolver) || !$this->internalResolver->isActive ())) {
        // Mark connection as failed if there are no addresses pending and no current address
        if ((!is_array ($this->socketAddresses) || (count ($this->socketAddresses) == 0)) && ($this->socketAddress === null))
          return $this->socketHandleConnectFailed ();
        
        return;
      }
      
      // Handle all results
      $Addrs = array ();
      $Resolve = array ();
      
      while (count ($Results) > 0) {
        $Record = array_shift ($Results);

        // Check for a normal IP-Address
        if (($Record ['type'] == 'A') || ($Record ['type'] == 'AAAA')) {
          if (!is_array ($this->socketAddresses))
            $this->socketAddresses = array ();
          
          $Addrs [] = $Addr = ($Record ['type'] == 'AAAA' ? $Record ['ipv6'] : $Record ['ip']);
          $this->socketAddresses [] = array ($Hostname, $Addr, (isset ($Record ['port']) ? $Record ['port'] : $Port), $Type);
        
        // Handle canonical names
        } elseif ($Record ['type'] == 'CNAME') {
          // Check additionals
          $Found = false;
          
          foreach ($Addtl as $Record2)
            if ($Record2 ['host'] == $Record ['target']) {
              $Results [] = $Record2;
              $Found = true;
            }
          
          // Check wheter to enqueue this name as well
          if (!$Found) {
            $Resolve [] = $Record ['target'];
            $this->socketResolveDo ($Record ['target'], $Port, $Type);
          }
        
        // Handle SRV-Records
        } elseif ($Record ['type'] == 'SRV') {
          // Check additionals
          $Found = false;
       
          foreach ($Addtl as $Record2)
            if ($Record2 ['host'] == $Record ['target']) {
              $Record2 ['port'] = $Record ['port'];
              $Results [] = $Record2;
              $Found = true;
            }
          
          // Resolve deeper
          if (!$Found) {
            $Resolve [] = $Record ['target'];
            $this->socketResolveDo ($Record ['target'], $Record ['port'], $Type);
          }
        }
      }
      
      // Fire up new callback
      $this->___callback ('socketResolved', $Hostname, $Addrs, array_keys ($Resolve));
      
      // Check wheter to try to connect
      if (is_array ($this->socketAddresses) && (count ($this->socketAddresses) > 0))
        $this->connectMulti ();
    }
    // }}}
    
    // {{{ socketConnectTimeout
    /**
     * Timeout a pending connection
     * 
     * @remark This is for internal use only! It does not have any effect when called directly! :-P
     * 
     * @access public
     * @return void
     **/
    public function socketConnectTimeout () {
      // Check if we are still trying to connect
      if (!$this->isConnecting ())
        return;
      
      // Check if the timeout is ready
      if ((time () - $this->lastEvent) + 1 < self::CONNECT_TIMEOUT)
        return;
      
      // Mark this connection as failed
      $this->socketHandleConnectFailed ();
    }
    // }}}
    
    // {{{ useInternalResolver
    /**
     * Set wheter to use internal resolver for connects
     * 
     * @param bool $Toggle (optional)
     * 
     * @access public
     * @return bool
     **/
    public function useInternalResolver ($Toggle = null) {
      if ($Toggle === null)
        return ($this->internalResolver !== false);
      
      if (!$Toggle)
        $this->internalResolver = false;
      elseif (!$this->internalResolver)
        $this->internalResolver = true;
      
      return true;
    }
    // }}}
    
    // {{{ disconnect
    /**
     * Gracefully close our connection
     * 
     * @access public
     * @return bool
     **/
    public function disconnect () {
      // Check if we are connected/connecting
      if ($this->isDisconnected ())
        return true;
      
      // Check wheter to terminate the connection at any parent entity
      if ($this->Type == self::TYPE_UDP_SERVER) {
        if (!is_object ($this->serverParent))
          return false;
        
        $this->serverParent->disconnectChild ($this);
      
      // Close our own connection
      } else {
        @fclose ($this->getFD ());
        
        if (is_object ($this->serverParent))
          $this->serverParent->___callback ('serverClientClosed', $this->getRemoteName (), $this);
      }
      
      $this->Connected = false;
      
      // Unbind from our event-base
      $this->unbind ();
      
      // Destroy our resolver
      if (is_object ($this->internalResolver)) {
        $this->internalResolver->unbind ();
        $this->internalResolver = true;
      }
      
      // Fire up callback
      $this->___callback ('socketDisconnected');
      
      // Clean up buffers
      $this->readBuffer = '';
      $this->writeBuffer = '';
      
      return true;
    }
    // }}}
    
    // {{{ isDisconnected
    /**
     * Check if we are not connected at the moment and do not make any attemps to get connected
     * 
     * @access public
     * @return bool
     **/
    public function isDisconnected () {
      return ($this->Connected === false);
    }
    // }}}
    
    // {{{ isConnecting
    /**
     * Check if we are trying to connect to a remote party
     * 
     * @access public
     * @return bool
     **/
    public function isConnecting () {
      return ($this->Connected === null);
    }
    // }}}
    
    // {{{ isConnected
    /**
     * Check if our connection was established successfully
     * 
     * @access public
     * @return bool
     **/
    public function isConnected () {
      return ($this->Connected === true);
    }
    // }}}
    
    // {{{ isUDP
    /**
     * Check if this is an UDP-Socket
     * 
     * @access public
     * @return bool
     **/
    public function isUDP () {
      return (($this->Type == self::TYPE_UDP) || ($this->Type == self::TYPE_UDP_SERVER));
    }
    // }}}
    
    // {{{ getRemoteHost
    /**
     * Retrive the hostname of the remote party
     * 
     * @access public
     * @return string
     **/
    public function getRemoteHost () {
      return $this->remoteHost;
    }
    // }}}
    
    // {{{ getRemotePort
    /**
     * Retrive the port we are connected to
     * 
     * @access public
     * @return int
     **/
    public function getRemotePort () {
      return $this->remotePort;
    }
    // }}}
    
    // {{{ getRemoteName
    /**
     * 
     **/
    public function getRemoteName () {
      if ($this->remoteName !== null)
        return $this->remoteName;
      
      return $this->remoteHost . ':' . $this->remotePort;
    }
    // }}}
    
    
    // {{{ isBuffered
    /**
     * Check or set if we are in buffered mode
     * 
     * @param bool $Toggle (optional)
     * 
     * @access public
     * @return bool
     **/
    public function isBuffered ($Toggle = null) {
      // Check wheter to set a new status
      if ($Toggle !== null) {
        // Set the new status
        $this->isBuffered = ($Toggle ? true : false);
        
        // Flush the read-buffer if there is data waiting
        if (!$this->isBuffered && (strlen ($this->readBuffer) > 0)) {
          $this->___callback ('socketReceive', $this->readBuffer);
          $this->readBuffer = '';
        }
      }
      
      return $this->isBuffered;
    }
    // }}}
    
    // {{{ readBuffer
    /**
     * Read from our internal buffer
     * 
     * @param int $Size (optional)
     * 
     * @remark We have to be in buffered mode for this to work
     * 
     * @access public
     * @return string
     **/
    public function readBuffer ($Size = null) {
      // Check if we are in buffered mode
      if (!$this->isBuffered)
        return false;
      
      // Retrive the requested data from the buffer
      if ($Size === null) {
        $Buffer = $this->readBuffer;
        $this->readBuffer = '';
      } else {
        $Buffer = substr ($this->readBuffer, 0, $Size);
        $this->readBuffer = substr ($this->readBuffer, $Size);
      }
      
      return $Buffer;
    }
    // }}}
    
    // {{{ write
    /**
     * Write data to our connection
     * 
     * @param string $Data
     * 
     * @access public
     * @return bool  
     **/
    public function write ($Data) {
      // Check wheter to write to our internal buffer first
      if ($this->isBuffered) {
        $this->writeBuffer .= $Data;
        
        if ((strlen ($this->writeBuffer) > 0) && !$this->watchWrite ())
          $this->watchWrite (true);
        
        return;
      }
      
      // Perform a normal unbuffered write
      return ($this->writeInternal ($Data) > 0);
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
      // Just pass single chunks in UDP-Mode
      if ($this->Type == self::TYPE_UDP) {
        foreach (func_get_args () as $Message)
          if (!$this->write ($Message))
            return false;
        
        return true;
      }
      
      // Write out the whole in one large packet
      return $this->write (implode ('', func_get_args ()));
    }
    // }}}
    
    // {{{ writeInternal
    /**
     * Forward data for writing to our socket
     * 
     * @param string $Data
     * 
     * @access private
     * @return int Number of bytes written
     **/
    private function writeInternal ($Data) {
      // Make sure we have a socket available
      if ((($this->Type == self::TYPE_UDP_SERVER) && (!is_object ($this->serverParent) || !is_resource ($fd = $this->serverParent->getFD ()))) ||
          (($this->Type != self::TYPE_UDP_SERVER) && !is_resource ($fd = $this->getFD ())))
        return false;
      
      // Perform a normal unbuffered write
      $this->lastEvent = time ();
      
      if ($this->Type == self::TYPE_UDP_SERVER)
        return stream_socket_sendto ($fd, $Data, 0, $this->remoteName);
      
      return fwrite ($fd, $Data);
    }
    // }}}
    
    // {{{ tlsSupported
    /**
     * Check if we have TLS-Support available
     * 
     * @access public
     * @return bool  
     **/
    public function tlsSupported () {
      return (function_exists ('stream_socket_enable_crypto') && extension_loaded ('openssl'));
    }
    // }}}
    
    // {{{ tlsEnable
    /**
     * Check/Set TLS on this connection
     * 
     * @param bool $Toggle (optional) Set the TLS-Status
     * @param callback $Callback (optional) Fire this callback after negotiation
     * @param mixed $Private (optional) Private data passed to the callback
     * 
     * @access public
     * @return bool  
     **/
    public function tlsEnable ($Toggle = null, $Callback = null, $Private = null) {
      // Check wheter to change the status
      if ($Toggle !== null) {
        // Check if we are in an unclean status at the moment
        if ($this->tlsEnabled === null)
          return false;
        
        # TODO: No clue at the moment how to do this on UDP-Server
        # TODO: Check if this simply works - we are doing this in non-blocking mode,
        #       so it might be possible to distinguish by normal peer-multiplexing
        if ($this->Type == self::TYPE_UDP_SERVER)
          return false;
        
        // Clean up the flag
        $Toggle = ($Toggle ? true : false);
        
        // Check wheter to do anything
        if ($Toggle === $this->tlsEnabled)
          return true;
        
        // Set internal status
        if (($Callback !== null) && !is_callable ($Callback))
          $Callback = null;
        
        $this->tlsEnabled = null;
        $this->tlsCallback = $Callback;
        $this->tlsPrivate = $Private;
        
        # TODO: Add external API for this!
        if ($this->tlsStatus = $Toggle)
          stream_context_set_option ($this->getFD (), array (
            'ssl' => array (
              // General settings
              # 'ciphers' => '',
              'capture_peer_cert' => true,
              'capture_peer_cert_chain' => true,
              'SNI_enabled' => true,
              # 'SNI_server_name' => '', // Domainname for SNI
              
              // Parameters for verification
              'verify_peer' => false,
              # 'verify_depth' => 1, // How many levels to check
              'allow_self_signed' => true,
              # 'cafile' => null, // CAfile for verify_peer
              # 'capath' => null, // See cafile
              # 'CN_match' => null, // Expected commonname
              
              // Remote authentication
              # 'local_cert' => null, // PEM of local certificate
              # 'local_pk' => null, // Undocumented: Path to private key
              # 'passphrase' => null, // Passphrase for local_cert
            )
          ));
        
        // Forward the request
        $this->setTLSMode ();
        
        return true;
      }
      
      return ($this->tlsEnabled == true);
    }
    // }}}
    
    // {{{ setTLSMode
    /**
     * Try to setup an TLS-secured connection
     * 
     * @access private
     * @return void
     **/
    private function setTLSMode () {
      // Make sure we know our connection
      if (!is_resource ($fd = $this->getFD ()))
        return false;
      
      // Issue the request to enter or leave TLS-Mode
      stream_set_blocking ($fd, false);
      
      if ($this->tlsStatus)
        $tlsRequest = stream_socket_enable_crypto ($fd, $this->tlsStatus, STREAM_CRYPTO_METHOD_TLS_CLIENT);
      else
        $tlsRequest = stream_socket_enable_crypto ($fd, $this->tlsStatus);
      
      stream_set_blocking ($fd, true);
      
      // Check if the request succeeded
      if ($tlsRequest === true) {
        $this->tlsEnabled = $this->tlsStatus;
        
        if ($this->tlsCallback !== null)
          call_user_func ($this->tlsCallback, $this->tlsStatus, $this->tlsPrivate);
        
        $this->tlsCallback = null;
        $this->tlsPrivate = null;
        
        if ($this->tlsEnabled)
          $this->___callback ('tlsEnabled');
        else
          $this->___callback ('tlsDisabled');
      
      // Check if the request failed
      } elseif ($tlsRequest === false) {
        $this->tlsEnabled = false;
        
        if ($this->tlsCallback !== null)
          call_user_func ($this->tlsCallback, null, $this->tlsPrivate);
        
        $this->tlsCallback = null;
        $this->tlsPrivate = null;
        
        $this->___callback ('tlsFailed');
      }
    }
    // }}}
    
    // {{{ getLastEvent
    /** 
     * Retrive the timestamp when the last read/write-Event happened on this socket
     *    
     * @access public   
     * @return int   
     **/  
    public function getLastEvent () {
      return $this->lastEvent;
    }
    // }}}
    
    
    // {{{ readEvent
    /**
     * Handle incoming events
     * 
     * @access public
     * @return void  
     **/
    public final function readEvent () {
      // Let TLS intercept here
      if ($this->tlsEnabled === null)
        return $this->setTLSMode ();
      
      // Read incoming data from socket
      if (($Data = fread ($this->getFD (), $this->bufferSize)) == '') {
        if ($this->isConnecting ())
          return $this->socketHandleConnectFailed ();
        
        return $this->disconnect ();
      }
      
      // Check if we are in connecting state
      if ($this->isConnecting ())
        $this->socketHandleConnected ();
      
      // Forward this internally
      $this->receiveInternal ($Data);
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
    public function readUDPServer ($Data, $Server) {
      // Validate the incoming request
      if (($this->Type !== self::TYPE_UDP_SERVER) || ($Server !== $this->serverParent))
        return false;
      
      // Forward internally
      $this->receiveInternal ($Data);
    }  
    // }}}
    
    // {{{ receiveInternal
    /**
     * Receive and process incoming data
     * 
     * @param string $Data
     * 
     * @access private
     * @return void
     **/
    private function receiveInternal ($Data) {
      // Set the last event
      $this->lastEvent = time ();
      
      // Forward to our handler
      if ($this->isBuffered) {
        $this->readBuffer .= $Data;
       
        $this->___callback ('socketReadable');
      } else
        $this->___callback ('socketReceive', $Data);
    }
    // }}}
    
    // {{{ writeEvent
    /**
     * Recognize when the socket becomes writeable
     * 
     * @access public
     * @return void  
     **/
    public final function writeEvent () {
      // Check if we are currently connecting
      if ($this->isConnecting ()) {
        // Remove the write-events
        if (!$this->isBuffered || (strlen ($this->writeBuffer) == 0))
          $this->watchWrite (false);
        
        // Fire up the callback
        $this->socketHandleConnected ();
      }
      
      // Let TLS intercept here
      if ($this->tlsEnabled === null)
        return $this->setTLSMode ();
      
      // Handle buffered writes
      if ($this->isBuffered && (strlen ($this->writeBuffer) > 0)) {
        $length = $this->writeInternal ($this->writeBuffer);
        
        // Check if the write succeded
        if ($length > 0) {
          $this->lastEvent = time ();
          
          // Remove the written bytes from the buffer
          $this->writeBuffer = substr ($this->writeBuffer, $length);
          
          // Check if the buffer is empty now
          if (strlen ($this->writeBuffer) == 0) {
            $this->watchWrite (false);
            $this->___callback ('socketDrained');
          }
        }
      } else
        $this->watchWrite (false);
    }
    // }}}
    
    public final function errorEvent () {
      echo 'Error Event', "\n";
    }
    
    // {{{ socketTryConnect
    /**
     * Callback: We are trying to connect to a given host
     * 
     * @param string $desiredHost
     * @param string $Host
     * @param int $Port
     * @param enum $Type
     * 
     * @access protected
     * @return void
     **/
    protected function socketTryConnect ($desiredHost, $Host, $Port, $Type) { }
    // }}}
    
    // {{{ socketTryConnectFailed
    /**
     * Callback: One try to connect to a host failed
     * 
     * @param string $desiredHost
     * @param string $Host
     * @param int $Port   
     * @param enum $Type  
     * 
     * @access protected
     * @return void
     **/
    protected function socketTryConnectFailed ($desiredHost, $Host, $Port, $Type) { }
    // }}}
    
    // {{{ socketResolve
    /**
     * Callback: Internal resolver started to look for Addresses
     * 
     * @param array $Hostnames
     * 
     * @access protected
     * @return void
     **/
    protected function socketResolve ($Hostnames) { }
    // }}}
    
    // {{{ socketResolved
    /**
     * Callback: Internal resolver returned a result for something
     * 
     * @param string $Hostname
     * @param array $Addresses
     * @param array $otherNames
     * 
     * @access protected
     * @return void
     **/
    protected function socketResolved ($Hostname, $Addresses, $otherNames) { }
    // }}}
    
    // {{{ socketConnected
    /**
     * Callback: Connection was successfully established
     * 
     * @access protected
     * @return void
     **/
    protected function socketConnected () { }
    // }}}
    
    // {{{ socketConnectionFailed
    /**
     * Callback: Connection could not be established
     * 
     * @access protected
     * @return void
     **/
    protected function socketConnectionFailed () { }
    // }}}
    
    // {{{ socketReadable
    /**
     * Callback: Received data is available on internal buffer
     * 
     * @access protected
     * @return void
     **/
    protected function socketReadable () { }
    // }}}
    
    // {{{ socketReceive
    /**
     * Callback: Data was received on this connection
     * 
     * @param string $Data
     * 
     * @access protected
     * @return void
     **/
    protected function socketReceive ($Data) { }
    // }}}
    
    // {{{ socketDrained
    /**
     * Callback: Write-Buffer is now empty
     * 
     * @access protected
     * @return void
     **/
    protected function socketDrained () { }
    // }}}
    
    // {{{ socketDisconnected
    /**
     * Callback: The connection was closed
     * 
     * @remark This is always called after an attempt to establish a connection, socketConnectionFailed() may be raised in advance
     * 
     * @access protected
     * @return void
     **/
    protected function socketDisconnected () { }
    // }}}
    
    // {{{ tlsEnabled
    /**
     * Callback: TLS was successfully enabled
     * 
     * @access protected
     * @return void
     **/
    protected function tlsEnabled () { }
    // }}}
    
    // {{{ tlsDisabled
    /**
     * Callback: TLS was disabled
     * 
     * @access protected
     * @return void
     **/
    protected function tlsDisabled () { }
    // }}}
    
    // {{{ tlsFailed
    /**
     * Callback: TLS-Negotiation failed
     * 
     * @access protected
     * @return void
     **/
    protected function tlsFailed () { }
    // }}}
  }
  // }}}

?>