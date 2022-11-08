<?php

  /**
   * quarxConnect Events - Asyncronous Sockets
   * Copyright (C) 2014-2022 Bernd Holzmueller <bernd@quarxconnect.de>
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

  namespace quarxConnect\Events;
  
  /**
   * Event Socket
   * ------------
   * Generic implementation to handle internet-based connections
   * 
   * @class Socket
   * @package quarxConnect\Events
   * @revision 03
   **/
  class Socket extends IOStream {
    /* Error-types */
    public const ERROR_NET_UNKNOWN     =  0;
    public const ERROR_NET_DNS_FAILED  = -1;
    public const ERROR_NET_TLS_FAILED  = -2;
    public const ERROR_NET_TIMEOUT     = -110;
    public const ERROR_NET_REFUSED     = -111;
    public const ERROR_NET_UNREACHABLE = -101;
    
    /* Socket-Types */
    public const TYPE_TCP = 0;
    public const TYPE_UDP = 1;
    public const TYPE_UDP_SERVER = 2;
    
    /* Timeouts */
    public const CONNECT_TIMEOUT = 5;
    public const UNREACHABLE_TIMEOUT = 10;
    
    /* Buffers */
    public const READ_TCP_BUFFER = 4096;
    public const READ_UDP_BUFFER = 1500;
    
    protected const READ_BUFFER_SIZE = 10485760;
    
    /* Defaults */
    protected const DEFAULT_TYPE = Socket::TYPE_TCP;
    protected const DEFAULT_PORT = null;
    
    protected const FORCE_TYPE = null;
    protected const FORCE_PORT = null;
    
    /* NAT64-Prefix - if set map failed IPv4-connections to IPv6 */
    public static $nat64Prefix = null;
    
    /* Known unreachable addresses */
    private static $Unreachables = [ ];
    
    /* Internal resolver */
    private static $dnsResolver = null;
    
    /* Our connection-state */
    private $Connected = false;
    
    /* Socket-Type of this connection */
    private $Type = self::TYPE_TCP;
    
    /* Any assigned server-handle */
    private $serverParent = null;
    
    /* Bind local socket to this ip-address */
    private $socketBindAddress = null;
    
    /* Bind local socket to this port */
    private $socketBindPort = null;
    
    /* Set of addresses we are trying to connectl to */
    private $socketAddresses = null;
    
    /* The current address we are trying to connect to */
    private $socketAddress = null;
    
    /* Resolve-Function of connect-promise */
    private $socketConnectResolve = null;
    
    /* Reject-Function of connect-promise */
    private $socketConnectReject = null;
    
    /* Socket-Connect-Timeout */
    private $socketConnectTimer = null;
    
    private $localAddr = null;
    private $localPort = null;
    
    /* Our current remote hostname */
    private $remoteHost = '';
    
    /* Address of our current remote host */
    private $remoteAddr = '';
    
    /* Out current remote port */
    private $remotePort = 0;
    
    /* Short-hand of remote hostname and port (for UDP-Server-Mode) */
    private $remoteName = null;
    
    /* Preset of TLS-Options */
    private $tlsOptions = [
      'ciphers'                 => 'ECDHE:!aNULL:!WEAK',
      'verify_peer'             => true,
      'verify_peer_name'        => true,
      'capture_peer_cert'       => true,
      'capture_peer_cert_chain' => true,
      'SNI_enabled'             => true,
      'disable_compression'     => true,
    ];
    
    /* Our current TLS-Status */
    private $tlsEnabled = false;
    
    /* Promise for TLS-Initialization */
    private $tlsPromise = null;
    
    /* Size for Read-Requests */
    private $bufferSize = 0;
    
    /* Local read-buffer */
    private $readBuffer = '';
    private $readBufferLength = 0;
    
    /* Local write-buffer */
    private $writeBuffer = '';
    
    /* Time of last event on this socket */
    private $lastEvent = 0;
    
    /* Counter for DNS-Actions */
    private $Resolving = 0;
    
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
    
    // {{{ isIPv6
    /**
     * Check if a given address is valid IPv6
     * 
     * @param string $ipAddress
     * 
     * @access public
     * @return bool
     **/
    public static function isIPv6 (string $ipAddress) : bool {
      if (strlen ($ipAddress) == 0)
        return false;
      
      if ($ipAddress [0] == '[')
        $ipAddress = substr ($ipAddress, 1, -1);
      
      return (filter_var ($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false);
    }
    // }}}
    
    // {{{ ip6toBinary
    /**
     * Convert an IP-Adress into an IPv6 binary address
     * 
     * @param string $IP
     * 
     * @access public
     * @return string
     +*/
    public static function ip6toBinary ($IP) {
      // Check for an empty ip
      if (strlen ($IP) == 0)
        return "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";
      
      // Check wheter to convert IPv4 to mapped IPv6
      if (self::isIPv4 ($IP)) {
        $N = explode ('.', $IP);
        $IP = sprintf ('::ffff:%02x%02x:%02x%02x', (int)$N [0], (int)$N [1], (int)$N [2], (int)$N [3]);
      }
      
      // Check for square brackets
      if ($IP [0] == '[')
        $IP = substr ($IP, 1, -1);
      
      // Split into pieces
      $N = explode (':', $IP);
      $C = count ($N);
      
      if ($C < 2)
        return false;
      
      // Check for ugly mapped IPv4
      if (($C == 4) && (strlen ($N [0]) == 0) && (strlen ($N [1]) == 0) && ($N [2] == 'ffff') && self::isIPv4 ($N [3])) {
        $IPv4 = explode ('.', array_pop ($N));
        
        $N [] = dechex (((int)$IPv4 [0] << 8) | ((int)$IPv4 [1]));
        $N [] = dechex (((int)$IPv4 [2] << 8) | ((int)$IPv4 [3]));
      }
      
      // Make sure the IPv6 is fully qualified
      if ($C != 8)
        for ($i = 1; $i < $C; $i++) {
          if (strlen ($N [$i]) != 0)
            continue;
          
          $N = array_merge (array_slice ($N, 0, $i), array_fill (0, (8 - count ($N)), '0'), array_slice ($N, $i));
          
          break;
        }
      
      // Return binary
      return pack ('nnnnnnnn', hexdec ($N [0]), hexdec ($N [1]), hexdec ($N [2]), hexdec ($N [3]), hexdec ($N [4]), hexdec ($N [5]), hexdec ($N [6]), hexdec ($N [7]));
    }
    // }}}
    
    // {{{ ip6fromBinary
    /** 
     * Create a human readbale IPv6-Address from its binary representation
     * 
     * @param string
     * 
     * @access public
     * @return string
     **/
    public static function ip6fromBinary ($IP) {
      // Make sure all bits are in place
      if (strlen ($IP) != 16)
        return false;
      
      // Unpack as hex-digits
      $IP = array_values (unpack ('H4a/H4b/H4c/H4d/H4e/H4f/H4g/H4h', $IP));
      
      // Try to remove zero blocks
      $b = $s = $c = $m =  null;
      
      for ($i = 0; $i < 8; $i++) {
        $IP [$i] = ltrim ($IP [$i], '0');
        
        if (strlen ($IP [$i]) == 0) {
          if ($s === null) {
            $s = $i;
            $c = 1;
          } else
            $c++;
        } elseif ($s !== null) {
          if ($c > $m) {
            for ($j = $b; $j < $b + $m; $j++)
              $IP [$j] = '0';
            
            $m = $c;
            $b = $s;
          } else
            for ($j = $s; $j < $s + $c; $j++)
              $IP [$j] = '0';
          
          $s = $c = null;
        }
      }
      
      if (($s !== null) && ($c > $m)) {
        for ($j = $b; $j < $b + $m; $j++)
          $IP [$j] = '0';
        
        $m = $c;
        $b = $s;
      }
      
      if (($b !== null) && ($m > 1))
        $IP = array_merge (array_slice ($IP, 0, $b + ($b == 0 ? 2 : 1)), array_slice ($IP, $b + $m));
      
      // Return the IPv6
      return '[' . implode (':', $IP) . ']';
    }
    // }}}
    
    // {{{ setDNSResolver
    /**
     * Store a custom DNS-Resolver
     * 
     * @param Client\DNS $dnsResolver
     * 
     * @access public
     * @return void
     **/
    public static function setDNSResolver (Client\DNS $dnsResolver) {
      self::$dnsResolver = $dnsResolver;
    }
    // }}}
    
    
    // {{{ __construct
    /**
     * Create a new event-socket
     * 
     * @param Base $Base (optional)
     * @param mixed $Host (optional)
     * @param int $Port (optional)
     * @param enum $Type (optional)
     * @param bool $enableTLS (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Base $Base = null, $Host = null, $Port = null, $Type = null, $enableTLS = false) {
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
        $this->close ();
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
        $this->close ();
      
      return [ 'Type' ];
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
    
    // {{{ bind
    /**
     * Try to bind our sockets to this source-address
     * 
     * @param string $IP (optional)
     * @param int $Port (optional)
     * 
     * @access public
     * @return bool
     **/
    public function bind ($IP = null, $Port = null) {
      // Make sure the IP-Address is valid
      if (($IP !== null) && !$this::isIPv4 ($IP) && !$this::isIPv6 ($IP)) {
        trigger_error ('Not an IP-Address: ' . $IP);
        
        return false;
      }
      
      // Make sure the Port is valid
      if (($Port !== null) && (($Port < 1) || ($Port > 0xFFFF))) {
        trigger_error ('Invalid port: ' . $Port);
        
        return false;
      }
      
      // Remember the values
      $this->socketBindAddress = $IP;
      $this->socketBindPort = (int)$Port;
      
      return true;
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
     * 
     * @access public
     * @return Promise
     **/
    public function connect ($Hosts, $Port = null, $Type = null, $enableTLS = false) : Promise {
      // Check wheter to use the default socket-type
      if ($Type === null)
        $Type = $this::DEFAULT_TYPE;
      
      if ($this::FORCE_TYPE !== null)
        $Type = $this::FORCE_TYPE;
      
      // Validate the type-parameter
      if (($Type != self::TYPE_TCP) && ($Type != self::TYPE_UDP))
        return Promise::reject ('Unsupported socket-type');
      
      // Check wheter to use a default port
      if ($Port === null) {
        $Port = $this::DEFAULT_PORT;
        
        if ($Port === null)
          return Promise::reject ('No port specified');
      }
      
      if ($this::FORCE_PORT !== null)
        $Port = $this::FORCE_PORT;
      
      // Make sure we have an event-base assigned
      if (!$this->getEventBase ())
        return Promise::reject ('No Event-Base assigned or could not assign Event-Base');
      
      // Try to close any open connection before creating a new one
      # TODO: Make disconnect async
      if (!$this->isDisconnected () && !$this->close ())
        return Promise::reject ('Disconnect before connect failed');
      
      // Reset internal addresses
      $this->socketAddresses = null;
      $this->socketAddress = null;
      $this->socketConnectResolve = null;
      $this->socketConnectReject = null;
      $this->tlsEnabled = ($enableTLS ? null : false);
      
      // Create a new promise
      return new Promise (
        function (callable $resolve, callable $reject) use ($Hosts, $Port, $Type) {
          // Remember promises
          $this->socketConnectResolve = $resolve;
          $this->socketConnectReject = $reject;
          
          // Make sure hosts is an array
          if (!is_array ($Hosts))
            $Hosts = [ $Hosts ];
          
          $Resolve = [ ];
          
          foreach ($Hosts as $Host) {
            // Check for IPv6
            if (($IPv6 = $this::isIPv6 ($Host)) && ($Host [0] != '['))
              $Host = '[' . $Host . ']';
            
            // Check for IPv4/v6 or wheter to skip the resolver
            if (($this->Resolving < 0) || $this::isIPv4 ($Host) || $IPv6)
              $this->socketAddresses [] = [
                'target'   => $Host,
                'hostname' => $Host,
                'port'     => $Port,
                'type'     => $Type,
              ];
            else
              $Resolve [] = $Host;
          }
          
          // Put ourself into connected-state
          $this->Connected = null;
          
          // Check if we have addresses to connect to
          if ($this->socketAddresses && (count ($this->socketAddresses) > 0))
            $this->socketConnectMulti ();
          
          // Sanity-Check if to use internal resolver
          if (($this->Resolving < 0) || (count ($Resolve) == 0))
            return;
          
          // Perform asyncronous resolve
          return $this->socketResolveDo ($Host, $Port, $Type);
        }
      );
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
     * 
     * @access public
     * @return Promise
     **/
    public function connectService ($Domain, $Service, $Type = null, $enableTLS = false) : Promise {
      // Check wheter to use the default socket-type
      if ($Type === null)
        $Type = $this::DEFAULT_TYPE;
      
      if ($this::FORCE_TYPE !== null)
        $Type = $this::FORCE_TYPE;
      
      // Validate the type-parameter
      if (($Type != self::TYPE_TCP) && ($Type != self::TYPE_UDP))
        return Promise::reject ('Unsupported socket-type');
      
      // Make sure we have an event-base assigned
      if (!$this->getEventBase ())
        return Promise::reject ('Failed to get event-base');
      
      // Try to close any open connection before creating a new one
      if (!$this->isDisconnected () && !$this->close ())
        return Promise::reject ('Failed to disconnect socket before connect');
      
      // Reset internal addresses
      $this->socketAddresses      = null;
      $this->socketAddress        = null;
      $this->socketConnectResolve = null;
      $this->socketConnectReject  = null;
      
      $this->tlsEnabled = ($enableTLS ? null : false);
      $this->Connected = null;
      $this->lastEvent = time ();
      
      // Generate label to look up
      $Label = '_' . $Service . '._' . ($Type == self::TYPE_UDP ? 'udp' : 'tcp') . '.' . $Domain;
      
      return new Promise (function ($resolve, $reject) use ($Label, $Type, $Domain) {
        // Remember promises
        $this->socketConnectResolve = $resolve;
        $this->socketConnectReject = $reject;
        
        // Perform syncronous lookup
        if ($this->Resolving < 0) {
          // Fire a callback
          $this->___callback ('socketResolve', [ $Label ], [ Stream\DNS\Message::TYPE_SRV ]);
          
          // Do the DNS-Lookup
          if (!is_array ($Result = dns_get_record ($Label, \DNS_SRV, $AuthNS, $Addtl)) || (count ($Result) == 0))
            return $this->socketHandleConnectFailed ($this::ERROR_NET_DNS_FAILED, 'Failed to resolve destination (SRV) with dns_get_record()');
          
          // Forward the result
          return $this->socketResolverResultArray ($Result, $Addtl, $Domain, \DNS_SRV, null, $Type);
        }
        
        // Perform asyncronous lookup
        return $this->socketResolveDo ($Label, null, $Type, Stream\DNS\Message::TYPE_SRV);
      });
    }
    // }}}
     
    // {{{ socketConnectMulti
    /**
     * Try to connect to next host on our list
     * 
     * @access private
     * @return void
     **/
    private function socketConnectMulti () : void {
      // Check if there are addresses on the queue
      if (
        !is_array ($this->socketAddresses) ||
        (count ($this->socketAddresses) == 0) ||
        ($this->socketAddress !== null)
      )
        return;
      
      // Get the next address
      $this->socketAddress = array_shift ($this->socketAddresses);
      
      // Fire a callback for this
      $this->___callback (
        'socketTryConnect',
        $this->socketAddress ['target'],
        $this->socketAddress ['hostname'],
        $this->socketAddress ['port'],
        $this->socketAddress ['type']
      );
      
      // Check unreachable-cache
      if (isset (self::$Unreachables [$Key = $this->socketAddress ['hostname'] . ':' . $this->socketAddress ['port'] . ':' . $this->socketAddress ['type']])) {
        if (time () - self::$Unreachables [$Key] < $this::UNREACHABLE_TIMEOUT) {
          $this->socketHandleConnectFailed (self::ERROR_NET_UNREACHABLE, 'Destination is marked as unreachable on cache');
          
          return;
        }
        
        unset (self::$Unreachables [$Key]);
      }
      
      // Create new client-socket
      $socketUri = ($this->socketAddress ['type'] === self::TYPE_TCP ? 'tcp' : 'udp') . '://' . $this->socketAddress ['hostname'] . ':' . $this->socketAddress ['port'];
      
      if (
        ($this->socketBindAddress !== null) ||
        ($this->socketBindPort !== null)
      ) {
        $isIPv6 = self::isIPv6 ($this->socketBindAddress);
        
        $socketContext = stream_context_create ([
          'socket' => [
            'bindto' => ($isIPv6 ? '[' : '') . $this->socketBindAddress . ($isIPv6 ? ']' : '') . ':' . (int)$this->socketBindPort,
          ]
        ]);
      } else 
        $socketContext = stream_context_create ();
      
      if (!is_resource ($streamSocket = @stream_socket_client ($socketUri, $errno, $err, $this::CONNECT_TIMEOUT, \STREAM_CLIENT_ASYNC_CONNECT, $socketContext))) {
        $this->socketHandleConnectFailed (-$errno, 'connect() failed: ' . $err);
        
        return;
      }
      
      stream_set_blocking ($streamSocket, false);
      
      // Set our new status
      if (!$this->setStreamFD ($streamSocket))
        return;
      
      // Make sure we are watching events
      $this->watchWrite (true);
      $this->isWatching (true);
      
      // Setup our internal buffer-size
      $this->bufferSize = ($this->socketAddress ['type'] === self::TYPE_UDP ? self::READ_UDP_BUFFER : self::READ_TCP_BUFFER);
      $this->lastEvent = time ();
      
      // Set our connection-state
      if ($this->socketAddress ['type'] !== (self::TYPE_UDP ? true : null)) {
        $this->addHook ('eventWritable', [ $this, 'socketHandleConnected' ], true);
        $this->socketSetupConnectionTimeout ();
      } else
        $this->socketHandleConnected ();
    }
    // }}}
    
    // {{{ connectServer
    /**
     * Use this connection as Server-Slave
     * 
     * @param Socket\Server $Server
     * @param string $Remote
     * @param resource $Connection (optional)
     * @param bool $enableTLS (optional)
     * 
     * @remark This is for internal use only!
     * 
     * @access public
     * @return void
     **/
    final public function connectServer (Socket\Server $Server, $Remote, $Connection = null, bool $enableTLS = false) : void {
      // Set our internal buffer-size
      if ($Connection === null) {
        $this->bufferSize = self::READ_UDP_BUFFER;
        
        // Store short-hand for UDP-Writes
        $this->remoteName = $Remote;
      } else {
        $this->bufferSize = self::READ_TCP_BUFFER;
        
        // Switch connection into non-blocking mode
        stream_set_blocking ($Connection, false);
        
        // Store the connection
        $this->setStreamFD ($Connection);
        $this->tlsEnabled = ($enableTLS ? null : false);
      }
      
      // Store our parent server-handle
      $this->serverParent = $Server;
      
      // Fake remote socket-settings
      $p = strrpos ($Remote, ':');
      
      $this->socketAddress = [
        'target'   => substr ($Remote, 0, $p),
        'hostname' => substr ($Remote, 0, $p),
        'port'     => (int)substr ($Remote, $p + 1),
        'type'     => ($Connection === null ? self::TYPE_UDP_SERVER : self::TYPE_TCP)
      ];
      
      // Put ourself into connected state
      $this->socketHandleConnected ();
    }
    // }}}
    
    // {{ socketHandleConnected
    /**
     * Internal Callback: Our socket is now in connected state
     * 
     * @access public
     * @return void
     **/
    public function socketHandleConnected () {
      // Make sure we don't time out
      if ($this->socketConnectTimer) {
        $this->socketConnectTimer->cancel ();
        $this->socketConnectTimer = null;
      }
      
      // Unwatch writes - as we are buffered all the time, this should be okay
      $this->watchWrite (false);
      
      if ($this->Connected !== true) {
        // Set connection-status
        $this->Connected = true;
        
        if ($this->socketAddress !== null) {
          // Set runtime-information
          if ($fd = $this->getReadFD ())
            $Name = stream_socket_get_name ($fd, false);
          elseif ($this->serverParent)
            $Name = $this->serverParent->getLocalName ();
          else
            $Name = '';
          
          $this->Type       = $this->socketAddress ['type'];
          $this->localAddr  = substr ($Name, 0, strrpos ($Name, ':'));
          $this->localPort  = (int)substr ($Name, strrpos ($Name, ':') + 1);
          $this->remoteHost = $this->socketAddress ['target'];
          $this->remoteAddr = $this->socketAddress ['hostname'];
          $this->remotePort = $this->socketAddress ['port'];
          
          $this->tlsOptions ['peer_name'] = $this->remoteHost;
        }
        
        // Free some space now
        $this->socketAddress = null;
        $this->socketAddresses = null;
        
        // Check wheter to enable TLS
        if (
          ($this->tlsEnabled === null) &&
          !$this->isTLS ()
        ) {
          $this->tlsEnable ()->then (
            function () {
              $this->socketHandleConnected ();
            },
            function () {
              $this->socketHandleConnectFailed ($this::ERROR_NET_TLS_FAILED, 'Failed to enable TLS');
            }
          );
          
          return;
        } elseif ($this->isTLS ())
          $this->tlsEnabled = true;
      }
      
      // Check our TLS-Status and treat as connection failed if required
      if (
        ($this->tlsEnabled !== false) &&
        !$this->isTLS ()
      )
        return $this->socketHandleConnectFailed ($this::ERROR_NET_TLS_FAILED, 'Failed to enable TLS');
      
      // Fire custom callback
      if ($this->socketConnectResolve) {
        call_user_func ($this->socketConnectResolve);
        
        $this->socketConnectResolve = null;
        $this->socketConnectReject = null;
      }
      
      // Fire the callback
      $this->___callback ('socketConnected');
    }
    // }}}
    
    // {{{ socketHandleConnectFailed
    /**
     * Internal Callback: Pending connection could not be established
     * 
     * @param enum $Error (optional)
     * @param string $Message (optional)
     * 
     * @access private
     * @return void
     **/
    private function socketHandleConnectFailed ($Error = self::ERROR_NET_UNKNOWN, $Message = null) {
      // Mark this host as failed
      if ($this->socketAddress !== null) {
        // Reset the address
        $Address = $this->socketAddress;
        $this->socketAddress = null;
        
        // Mark destination as unreachable
        $Key = $Address ['hostname'] . ':' . $Address ['port'] . ':' . $Address ['type'];
        
        if (!isset (self::$Unreachables [$Key]))
          self::$Unreachables [$Key] = time ();
        
        // Check wheter to retry using IPv6/NAT64
        if ($this::$nat64Prefix !== null)
          $nat64Prefix = $this::$nat64Prefix;
        elseif ((($nat64Prefix = getenv ('NAT64_PREFIX')) === false) ||
                (strlen ($nat64Prefix) == 0))
          $nat64Prefix = null;
        
        if (
          ($nat64Prefix !== null) &&
          (
            ($IPv4 = self::isIPv4 ($Address ['hostname'])) ||
            (strtolower (substr ($Address ['hostname'], 0, 8)) == '[::ffff:')
          )
        ) {
          if ($IPv4) {
            $IP = explode ('.', $Address ['hostname']);
            $IP = sprintf ('[%s%02x%02x:%02x%02x]', $nat64Prefix, (int)$IP [0], (int)$IP [1], (int)$IP [2], (int)$IP [3]);
          } else
            $IP = '[' . $nat64Prefix . substr ($Address ['hostname'], 8);
          
          $this->socketAddresses [] = [
            'target'   => $Address ['target'],
            'hostname' => $IP,
            'port'     => $Address ['port'],
            'type'     => $Address ['type']
          ];
        }
        
        // Raise callback
        $this->___callback ('socketTryConnectFailed', $Address ['target'], $Address ['hostname'], $Address ['port'], $Address ['type'], $Error);
      }
      
      // Check if there are more hosts on our list
      if (
        (
          !is_array ($this->socketAddresses) ||
          (count ($this->socketAddresses) == 0)
        ) &&
        ($this->Resolving <= 0)
      ) {
        // Fire custom callback
        if ($this->socketConnectReject) {
          static $errorStringMap = [
            self::ERROR_NET_UNKNOWN     => 'Unknown error',
            self::ERROR_NET_DNS_FAILED  => 'DNS failed',
            self::ERROR_NET_TLS_FAILED  => 'TLS failed',
            self::ERROR_NET_TIMEOUT     => 'Connection timed out',
            self::ERROR_NET_REFUSED     => 'Connection refused',
            self::ERROR_NET_UNREACHABLE => 'Destination unreachable',
          ];
          
          if ($Message === null)
            $Message = ($errorStringMap [$Error] ?? $errorStringMap [self::ERROR_NET_UNKNOWN]);
          
          call_user_func ($this->socketConnectReject, $Message, $Error);
          
          $this->socketConnectResolve = null;
          $this->socketConnectReject = null;
        }
        
        // Fire the callback
        $this->___callback ('socketConnectionFailed', $Message, $Error);
        
        // Disconnect cleanly
        return $this->close ();
      }
      
      // Try the next host
      $this->socketConnectMulti ();
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
    private function socketResolveDo ($Hostname, $Port, $Type, array $Types = null) {
      // Don't do further resolves if we are already connected
      if ($this->isConnected ())
        return false;
      
      // Create a new resolver
      if (!is_object (self::$dnsResolver))
        # TODO: This is bound to our event-base
        self::$dnsResolver = new Client\DNS ($this->getEventBase ());
      
      // Check which types to resolve
      if ($Types === null)
        $Types = [
          Stream\DNS\Message::TYPE_AAAA,
          Stream\DNS\Message::TYPE_A,
        ];
      
      // Enqueue Hostnames
      $this->Resolving += count ($Types);
      
      foreach ($Types as $rType)
        self::$dnsResolver->resolve ($Hostname, $rType)->then (
          function (Stream\DNS\Recordset $Answers, Stream\DNS\Recordset $Authorities, Stream\DNS\Recordset $Additional, Stream\DNS\Message $Response)
          use ($Hostname, $Port, $Type, $rType) {
            // Decrese counter
            $this->Resolving--;
            
            // Discard any result if we are connected already
            if ($this->isConnected ())
              return;
            
            // Update our last event (to prevent a pending disconnect)
            $this->lastEvent = time ();
            
            // Convert the result
            $Result = self::$dnsResolver->dnsConvertPHP ($Response, $AuthNS, $Addtl);
            
            // Forward
            return $this->socketResolverResultArray ($Result, $Addtl, $Hostname, $rType, $Port, $Type);
          },
          function () use ($Hostname, $Port, $Type, $rType) {
            // Decrese counter
            $this->Resolving--;
            
            return $this->socketResolverResultArray ([ ], [ ], $Hostname, $rType, $Port, $Type);
          }
        );
      
      // Update last action
      $this->lastEvent = time ();
      
      // Fire a callback
      $this->___callback ('socketResolve', [ $Hostname ], $Types);
      
      // Setup a timeout
      $this->socketSetupConnectionTimeout ();
    }
    // }}}
    
    // {{{ socketSetupConnectionTimeout
    /**
     * Make sure we have a timer to let connection-attempts time out
     * 
     * @access private
     * @return void
     **/
    private function socketSetupConnectionTimeout () {
      // Check if we are already set up
      if ($this->socketConnectTimer)
        return $this->socketConnectTimer->restart ();
      
      // Create a new timeout
      $this->socketConnectTimer = $this->getEventBase ()->addTimeout (self::CONNECT_TIMEOUT);
      $this->socketConnectTimer->then (
        function () {
          $this->socketHandleConnectFailed ($this::ERROR_NET_TIMEOUT, 'Timeout reached');
          $this->socketConnectTimer = null;
        }
      );
    }
    // }}}
    
    // {{{ socketResolverResultArray
    /**
     * Handle the result of from any resolve-process
     * 
     * @param array $Results Results returned from the resolver
     * @param array $Addtl Additional results returned from the resolver
     * @param string $Hostname The Hostname we are looking for
     * @param enum $rType DNS-Record-Type we are looking for
     * @param int $Port The port we want to connect to
     * @param enum $Type The type of socket we wish to create
     * 
     * @access private
     * @return void
     **/
    private function socketResolverResultArray ($Results, $Addtl, $Hostname, $rType, $Port, $Type) {
      // Check if there are no results
      if ((count ($Results) == 0) && ($this->Resolving <= 0)) {
        // Mark connection as failed if there are no addresses pending and no current address
        if (
          (
            !is_array ($this->socketAddresses) ||
            (count ($this->socketAddresses) == 0)
          ) &&
          ($this->socketAddress === null)
        )
          return $this->socketHandleConnectFailed ($this::ERROR_NET_DNS_FAILED, 'Failed to resolve destination "' . $Hostname . '"');
        
        return;
      }
      
      // Handle all results
      $Addrs = [ ];
      $Resolve = [ ];
      
      while (count ($Results) > 0) {
        $Record = array_shift ($Results);

        // Check for a normal IP-Address
        if (($Record ['type'] == 'A') || ($Record ['type'] == 'AAAA')) {
          if (!is_array ($this->socketAddresses))
            $this->socketAddresses = [ ];
          
          $Addrs [] = $Addr = ($Record ['type'] == 'AAAA' ? '[' . $Record ['ipv6'] . ']' : $Record ['ip']);
          $this->socketAddresses [] = [
            'target'   => $Hostname,
            'hostname' => $Addr,
            'port'     => ($Record ['port'] ?? $Port),
            'type'     => $Type,
          ];
          
        // Handle canonical names
        } elseif ($Record ['type'] == 'CNAME') {
          // Check additionals
          $Found = false;
          
          foreach ($Results as $Record2)
            if ($Found = ($Record2 ['host'] == $Record ['target']))
              break;
          
          foreach ($Addtl as $Record2)
            if ($Record2 ['host'] == $Record ['target']) {
              $Results [] = $Record2;
              $Found = true;
            }
          
          // Check wheter to enqueue this name as well
          if ($Found)
            continue;
          
          $Resolve [] = $Record ['target'];
          $this->socketResolveDo ($Record ['target'], $Port, $Type, [ $rType ]);
        
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
      if (
        is_array ($this->socketAddresses) &&
        (count ($this->socketAddresses) > 0)
      )
        $this->socketConnectMulti ();
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
        return ($this->Resolving >= 0);
      
      if (!$Toggle)
        $this->Resolving = -1;
      elseif ($this->Resolving < 0)
        $this->Resolving = 0;
      
      return true;
    }
    // }}}
    
    // {{{ ___close
    /**
     * Gracefully close our connection
     * 
     * @param resource $closeFD (optional)
     * 
     * @access public
     * @return bool
     **/
    public function ___close ($closeFD = null) : bool {
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
        if ($closeFD)
          fclose ($closeFD);
        
        if (is_object ($this->serverParent))
          $this->serverParent->disconnectChild ($this);
      }
      
      // Reset our status
      $this->Connected = false;
      $this->tlsEnabled = false;
      
      // Unbind from our event-base
      $this->isWatching (false);
      
      // Clean up buffers
      $this->readBuffer = ''; 
      $this->readBufferLength = 0;
      
      // Fire up callback
      $this->___callback ('socketDisconnected');
      
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
    
    // {{{ isServer
    /**
     * Check if this is a Server-Socket
     * 
     * @access public
     * @return bool
     **/
    public function isServer () {
      return ($this->serverParent !== null);
    }
    // }}}
    
    // {{{ getLocalName
    /**
     * Retrive the local name of our socket
     * 
     * @access public
     * @return string
     **/
    public function getLocalName () {
      return ($this::isIPv6 ($this->localAddr) ? '[' . $this->localAddr . ']' : $this->localAddr) . ':' . $this->localPort;
    }
    // }}}
    
    // {{{ getLocalAddress
    /**
     * Retrive the local address of our socket
     * 
     * @access public
     * @return string
     **/
    public function getLocalAddress () {
      return $this->localAddr;
    }
    // }}}
    
    // {{{ getLocalPort
    /**
     * Retrive the local port of our socket
     * 
     * @access public
     * @return int
     **/
    public function getLocalPort () {
      return $this->localPort;
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
    
    // {{{ getRemoteAddress
    public function getRemoteAddress () {
      return $this->remoteAddr;
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
     * Retrive the hostname/ip-address and port of the remote party
     * 
     * @access public
     * @return string
     **/
    public function getRemoteName () {
      if ($this->remoteName !== null)
        return $this->remoteName;
      
      return $this->remoteHost . ':' . $this->remotePort;
    }
    // }}}
    
    
    // {{{ mwrite
    /**
     * Write multiple messages to our connection
     *    
     * @param string ...
     * 
     * @access public
     * @return Promise
     **/
    public function mwrite () : Promise {
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
    
    // {{{ write
    /**
     * Write data to this sink
     * 
     * @param string $writeData The data to write to this sink
     * 
     * @access public
     * @return Promise
     **/
    public function write (string $writeData) : Promise {
      // Bypass write-buffer in UDP-Server-Mode
      if ($this->Type == self::TYPE_UDP_SERVER) {
        if ($this->___write ($writeData) === false)
          return Promise::reject ('Write failed');
        
        # TODO: We don't honor length here
        
        return Promise::resolve ();
      }
      
      // Let our parent class handle the write-stuff
      return parent::write ($writeData);
    }
    // }}}
    
    // {{{ ___write
    /**
     * Forward data for writing to our socket
     * 
     * @param string $writeData
     * 
     * @access private
     * @return int Number of bytes written
     **/
    protected function ___write (string $writeData) : ?int {
      // Make sure we have a socket available
      if (
        (($this->Type == self::TYPE_UDP_SERVER) && (!is_object ($this->serverParent) || !is_resource ($fd = $this->serverParent->getWriteFDforClient ($this)))) ||
        (($this->Type != self::TYPE_UDP_SERVER) && !is_resource ($fd = $this->getWriteFD ()))
      )
        return null;
      
      // Perform a normal unbuffered write
      $this->lastEvent = time ();
      
      if ($this->Type == self::TYPE_UDP_SERVER)
        $writeLength = stream_socket_sendto ($fd, $writeData, 0, $this->remoteName);
      else
        $writeLength = fwrite ($fd, $writeData);
      
      if (
        (
          ($writeLength === false) ||
          ($writeLength === 0)
        ) &&
        feof ($fd)
      )
        $this->close (true);
      
      if ($writeLength === false)
        return null;
      
      return $writeLength;
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
    
    // {{{ tlsCiphers
    /**
     * Set a list of supported TLS-Ciphers
     * 
     * @param array $tlsCiphers
     * 
     * @access public
     * @return void
     **/
    public function tlsCiphers (array $tlsCiphers) : void {
      $this->tlsOptions ['ciphers'] = implode (':', $tlsCiphers);
      
      if ($this->getReadFD ())
        stream_context_set_option ($this->getReadFD (), [ 'ssl' => $this->tlsOptions ]);
    }
    // }}}
    
    // {{{ tlsCertificate
    /**
     * Setup TLS-Certificates for this end of the stream
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
      
      # TODO: local_pk passphrase
      if ($this->getReadFD ())
        stream_context_set_option ($this->getReadFD (), [ 'ssl' => $this->tlsOptions ]);
    }
    // }}}
    
    // {{{ tlsVerify
    /**
     * Set verification-options for TLS-secured connections
     * 
     * @param bool $verifyPeer (optional) Verify the peer (default)
     * @param bool $verifyName (optional) Verify peers name (default)
     * @param bool $allowSelfSigned (optional) Allow self signed certificates
     * @param string $caFile (optional) File or Directory containing CA-Certificates
     * @param int $verifyDepth (optional) Verify-Depth
     * @param string $expectedFingerprint (optional) Expected fingerprint of peers certificate
     * 
     * @access public
     * @return void
     **/
    public function tlsVerify (bool $verifyPeer = true, bool $verifyName = true, bool $allowSelfSigned = false, string $caFile = null, int $verfiyDepth = null, string $expectedFingerprint = null) : void {
      if ($verifyPeer !== null)
        $this->tlsOptions ['verify_peer'] = $verifyPeer;
      
      if ($verifyName !== null)
        $this->tlsOptions ['verify_peer_name'] = $verifyName;
      
      if ($allowSelfSigned !== null)
        $this->tlsOptions ['allow_self_signed'] = $allowSelfSigned;
      
      if ($caFile !== null) {
        if (is_dir ($caFile))
          $this->tlsOptions ['capath'] = $caFile;
        else
          $this->tlsOptions ['cafile'] = $caFile;
      }

      if ($verifyDepth !== null)
        $this->tlsOptions ['verify_depth'] = $verifyDepth;

      if ($expectedFingerprint !== null)
        $this->tlsOptions ['peer_fingerprint'] = $expcectedFingerprint;
      
      // Forward the options to the stream if there is one
      if ($this->getReadFD ())
        stream_context_set_option ($this->getReadFD (), [ 'ssl' => $this->tlsOptions ]);
    }
    // }}}
    
    // {{{ tlsEnable
    /**
     * Check/Set TLS on this connection
     * 
     * @access public
     * @return Promise
     */
    public function tlsEnable () : Promise {
      // Check if we are in an unclean status at the moment
      if (
        ($this->tlsEnabled === null) &&
        $this->tlsPromise
      )
        return $this->tlsPromise->getPromise ();
      
      // Make sure we have promise for this
      if (!$this->tlsPromise)
        $this->tlsPromise = new Promise\Defered ();
      
      # TODO: No clue at the moment how to do this on UDP-Server
      # TODO: Check if this simply works - we are doing this in non-blocking mode,
      #       so it might be possible to distinguish by normal peer-multiplexing
      if ($this->Type == self::TYPE_UDP_SERVER)
        return Promise::reject ('Unable to negotiate TLS in UDP-Server-Mode');
      
      // Set internal status
      $this->tlsEnabled = null;
      
      stream_context_set_option ($this->getReadFD (), [ 'ssl' => $this->tlsOptions ]);
      
      // Forward the request
      $this->setTLSMode ();
      
      return $this->tlsPromise->getPromise ();
    }
    // }}}
    
    // {{{ isTLS
    /**
     * Check if TLS is enabled
     * 
     * @access public
     * @return bool
     **/
    public function isTLS () : bool {
      return ($this->tlsEnabled === true);
    }
    // }}}
    
    // {{{ setTLSMode
    /**
     * Try to setup an TLS-secured connection
     * 
     * @access private
     * @return void
     **/
    private function setTLSMode () : void {
      // Make sure we know our connection
      if (!is_resource ($fd = $this->getReadFD ()))
        return;
      
      // Issue the request to enter or leave TLS-Mode
      if ($this->serverParent)
        $tlsMethod = STREAM_CRYPTO_METHOD_TLSv1_1_SERVER | STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;
      else
        $tlsMethod = STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
      
      $tlsRequest = @stream_socket_enable_crypto ($fd, true, $tlsMethod);
      
      // Check if the request succeeded
      if ($tlsRequest === true) {
        $this->tlsEnabled = true;
        
        $this->___callback ('tlsEnabled');
        
        if ($this->tlsPromise)
          $this->tlsPromise->resolve ();
      
      // Check if the request failed
      } elseif ($tlsRequest === false) {
        $this->tlsEnabled = false;
        
        $this->___callback ('tlsFailed');
        
        if ($this->tlsPromise) {
          $lastError = error_get_last ();
          
          if ($lastError) {
            if (substr ($lastError ['message'], 0, 31) == 'stream_socket_enable_crypto(): ')
              $lastError ['message'] = substr ($lastError ['message'], 31);
            
            $this->tlsPromise->reject (new Exception\Socket\Encryption ('Failed to enable TLS: ' . $lastError ['message']));
          } else
            $this->tlsPromise->reject (new Exception\Socket\Encryption ('Failed to enable TLS'));
        }
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
    
    // {{{ getReadFD
    /**
     * Retrive the stream-resource to watch for reads
     * 
     * @access public
     * @return resource May return NULL if no reads should be watched
     **/
    public function getReadFD () {
      if ($this->readBufferLength >= $this::READ_BUFFER_SIZE)
        return null;
      
      return parent::getReadFD ();
    }
    // }}}
    
    // {{{ raiseRead
    /**
     * Handle incoming read-events
     * 
     * @access public
     * @return void  
     **/
    final public function raiseRead () {
      // Let TLS intercept here
      if ($this->tlsEnabled === null)
        return $this->setTLSMode ();
      
      // Check if our buffer reached the watermark
      if ($this->readBufferLength >= $this::READ_BUFFER_SIZE) {
        if ($eventBase = $this->getEventBase ())
          $eventBase->updateEvent ($this);
        
        return;
      }
      
      // Read incoming data from socket
      if ((($Data = fread ($this->getReadFD (), $this->bufferSize)) === '') || ($Data === false)) {
        if ($this->isConnecting ())
          return $this->socketHandleConnectFailed ($this::ERROR_NET_REFUSED, 'Connection refused');
        
        // Check if the socket is really closed
        if (!feof ($this->getReadFD ()))
          return;
        
        // Close the socket on this side
        return $this->close ();
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
     * @param Socket\Server $Server
     * 
     * @access public
     * @return void
     **/
    public function readUDPServer ($Data, Socket\Server $Server) {
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
      
      // Forward to read-buffer
      $this->readBuffer .= $Data;
      $this->readBufferLength += strlen ($Data);
      
      // Fire up the callbacks
      $this->___callback ('eventReadable');
      $this->___callback ('socketReadable');
    }
    // }}}
    
    // {{{ ___read
    /**
     * Read data from our internal buffer
     * 
     * @param int $readLength (optional)
     * 
     * @access protected
     * @return string
     **/
    protected function ___read (int $readLength = null) : ?string {
      // Check if the buffer was full before reading
      $readBufferFull = ($this->readBufferLength >= $this::READ_BUFFER_SIZE);
      
      // Check how many bytes to read
      if (
        ($readLength === null) ||
        ($readLength >= $this->readBufferLength)
      ) {
        $Buffer = $this->readBuffer;
        $this->readBuffer = '';
        $this->readBufferLength = 0;
      } else {
        $Buffer = substr ($this->readBuffer, 0, $readLength = abs ($readLength));
        $this->readBuffer = substr ($this->readBuffer, $readLength);
        $this->readBufferLength -= $readLength;
      }
      
      // Restart reading if buffer was full but has space now
      if (
        $readBufferFull &&
        ($this->readBufferLength < $this::READ_BUFFER_SIZE) &&
        ($eventBase = $this->getEventBase ())
      )
        $eventBase->updateEvent ($this);
      
      return $Buffer;
    }
    // }}}
    
    
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
     * @param enum $Error
     * 
     * @access protected
     * @return void
     **/
    protected function socketTryConnectFailed ($desiredHost, $Host, $Port, $Type, $Error) { }
    // }}}
    
    // {{{ socketResolve
    /**
     * Callback: Internal resolver started to look for Addresses
     * 
     * @param array $Hostnames
     * @param array $Types
     * 
     * @access protected
     * @return void
     **/
    protected function socketResolve (array $Hostnames, array $Types) { }
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
    protected function socketResolved ($Hostname, array $Addresses, array $otherNames) { }
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
     * @param string $Message
     * @param enum $Error
     * 
     * @access protected
     * @return void
     **/
    protected function socketConnectionFailed ($Message, $Error) { }
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
    protected function tlsEnabled () : void {
      // No-Op
    }
    // }}}
    
    // {{{ tlsFailed
    /**
     * Callback: TLS-Negotiation failed
     * 
     * @access protected
     * @return void
     **/
    protected function tlsFailed () : void {
      // No-Op
    }
    // }}}
  }
  // }}}
