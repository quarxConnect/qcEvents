<?php

  /**
   * quarxConnect Events - Asynchronous Sockets
   * Copyright (C) 2014-2024 Bernd Holzmueller <bernd@quarxconnect.de>
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

  use InvalidArgumentException;
  use quarxConnect\Events\Promise\Deferred;
  use RuntimeException;
  use Throwable;
  use quarxConnect\Events\Exception\Socket as SocketException;

  class Socket extends IOStream
  {
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

    /**
     * NAT64-Prefix - if set map failed IPv4-connections to IPv6
     *
     * @var string|null
     */
    public static string|null $nat64Prefix = null;

    /**
     * Known unreachable addresses (IP + Port + Proto)
     *
     * @var array
     */
    private static array $unreachableAddresses = [];

    /**
     * Internal DNS-Resolver
     *
     * @var Client\DNS|null
     **/
    private static Client\DNS|null $dnsResolver = null;

    /**
     * Our connection-state
     *
     * @var bool|null
     **/
    private bool|null $socketConnected = false;

    /**
     * Socket-Type of this connection
     *
     * @var int
     **/
    private int $socketType = self::TYPE_TCP;

    /**
     * Parent Socket-Server if in UDP-Server-Mode
     *
     * @var Socket\Server|null
     **/
    private Socket\Server|null $serverParent = null;

    /**
     * Bind local socket to this ip-address
     *
     * @var string|null
     **/
    private string|null $socketBindAddress = null;

    /**
     * Bind local socket to this port
     *
     * @var int|null
     **/
    private int|null $socketBindPort = null;

    /**
     * Set of addresses we are trying to connect to
     *
     * @var array|null
     **/
    private array|null $socketAddresses = null;

    /**
     * The current address we are trying to connect to
     *
     * @var array|null
     **/
    private array|null $socketAddress = null;

    /**
     * Deferred Promise for current connection-attempt
     *
     * @var Promise\Deferred|null
     **/
    private Promise\Deferred|null $connectPromise = null;

    /**
     * Timer for connection-timeouts
     *
     * @var Timer|null
     **/
    private Timer|null $socketConnectTimer = null;

    /**
     * Local address (IP-Address) of our socket
     *
     * @var string|null
     **/
    private string|null $localAddress = null;

    /**
     * Local port of our socket
     *
     * @var int|null
     **/
    private int|null $localPort = null;

    /**
     * Our current remote hostname
     *
     * @var string|null
     **/
    private string|null $remoteHost = null;

    /**
     * Address of our current remote host
     *
     * @var string|null
     **/
    private string|null $remoteAddress = null;

    /**
     * Out current remote port
     *
     * @var int|null
     **/
    private int|null $remotePort = 0;

    /**
     * Shorthand of remote hostname and port (for UDP-Server-Mode)
     *
     * @var string|null
     **/
    private string|null $remoteName = null;

    /**
     * Preset of TLS-Options
     *
     * @var array
     **/
    private array $tlsOptions = [
      'ciphers'                 => 'ECDHE:!aNULL:!WEAK',
      'verify_peer'             => true,
      'verify_peer_name'        => true,
      'capture_peer_cert'       => true,
      'capture_peer_cert_chain' => true,
      'SNI_enabled'             => true,
      'disable_compression'     => true,
    ];

    /**
     * Our current TLS-Status
     *
     * @var bool|null
     **/
    private bool|null $tlsEnabled = false;

    /**
     * Promise for TLS-Initialization
     *
     * @var Deferred|null
     **/
    private Deferred|null $tlsPromise = null;

    /**
     * Size for Read-Requests
     *
     * @var int
     **/
    private int $bufferSize = 0;

    /**
     * Local read-buffer
     *
     * @var string
     **/
    private string $readBuffer = '';

    /**
     * Length of read-buffer
     *
     * @var int
     **/
    private int $readBufferLength = 0;

    /**
     * Time of last event on this socket
     *
     * @var int
     **/
    private int $lastEvent = 0;

    /**
     * Counter for DNS-Actions
     *
     * @var int
     **/
    private int $dnsQuestions = 0;

    /**
     * Idle-Timer
     * 
     * @var Timer|null
     **/
    private Timer|null $idleTimer = null;

    // {{{ isIPv4
    /**
     * Check if a given address is valid IPv4
     *
     * @param string $ipAddress
     *
     * @access public
     * @return bool
     **/
    public static function isIPv4 (string $ipAddress): bool
    {
      // Split the address into its pieces
      $ipOctets = explode ('.', $ipAddress);

      // Check if there are exactly 4 blocks
      if (count ($ipOctets) != 4)
        return false;

      // Validate each block
      foreach ($ipOctets as $ipOctet)
        if (
          !is_numeric ($ipOctet) ||
          ($ipOctet < 0) ||
          ($ipOctet > 255)
        )
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
    public static function isIPv6 (string $ipAddress): bool
    {
      if (strlen ($ipAddress) == 0)
        return false;

      if ($ipAddress [0] == '[')
        $ipAddress = substr ($ipAddress, 1, -1);

      return (filter_var ($ipAddress, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false);
    }
    // }}}

    // {{{ ip6toBinary
    /**
     * Convert an IP-Address into an IPv6 binary address
     *
     * @param string $ipAddress
     *
     * @access public
     * @return string
     *
     * @throws InvalidArgumentException
     +*/
    public static function ip6toBinary (string $ipAddress): string
    {
      // Check for an empty ip
      if (strlen ($ipAddress) == 0)
        return "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00";

      // Check whether to convert IPv4 to mapped IPv6
      if (self::isIPv4 ($ipAddress)) {
        $ipOctets = explode ('.', $ipAddress);
        $ipAddress = sprintf ('::ffff:%02x%02x:%02x%02x', (int)$ipOctets [0], (int)$ipOctets [1], (int)$ipOctets [2], (int)$ipOctets [3]);
      }

      // Check for square brackets
      if ($ipAddress [0] == '[')
        $ipAddress = substr ($ipAddress, 1, -1);

      // Split into pieces
      $ipOctets = explode (':', $ipAddress);
      $octetCount = count ($ipOctets);

      if ($octetCount < 2)
        throw new InvalidArgumentException ('Invalid IPv6-Address supplied');

      // Check for ugly mapped IPv4
      if (
        ($octetCount === 4) &&
        (strlen ($ipOctets [0]) == 0) &&
        (strlen ($ipOctets [1]) == 0) &&
        ($ipOctets [2] == 'ffff') &&
        self::isIPv4 ($ipOctets [3])
      ) {
        $IPv4 = explode ('.', array_pop ($ipOctets));

        $ipOctets [] = dechex (((int)$IPv4 [0] << 8) | ((int)$IPv4 [1]));
        $ipOctets [] = dechex (((int)$IPv4 [2] << 8) | ((int)$IPv4 [3]));
      }

      // Make sure the IPv6 is fully qualified
      if ($octetCount != 8)
        for ($i = 1; $i < $octetCount; $i++) {
          if (strlen ($ipOctets [$i]) != 0)
            continue;

          $ipOctets = array_merge (
            array_slice ($ipOctets, 0, $i),
            array_fill (0, (8 - count ($ipOctets)), '0'),
            array_slice ($ipOctets, $i)
          );

          break;
        }

      // Return binary
      return pack (
        'nnnnnnnn',
        hexdec ($ipOctets [0]),
        hexdec ($ipOctets [1]),
        hexdec ($ipOctets [2]),
        hexdec ($ipOctets [3]),
        hexdec ($ipOctets [4]),
        hexdec ($ipOctets [5]),
        hexdec ($ipOctets [6]),
        hexdec ($ipOctets [7])
      );
    }
    // }}}

    // {{{ ip6fromBinary
    /** 
     * Create a human-readable IPv6-Address from its binary representation
     *
     * @param string $ipAddress
     *
     * @access public
     * @return string
     *
     * @throws InvalidArgumentException
     **/
    public static function ip6fromBinary (string $ipAddress): string
    {
      // Make sure all bits are in place
      if (strlen ($ipAddress) != 16)
        throw new InvalidArgumentException ('Invalid binary IPv6 supplied');

      // Unpack as hex-digits
      $ipAddress = array_values (unpack ('H4a/H4b/H4c/H4d/H4e/H4f/H4g/H4h', $ipAddress));

      // Try to remove zero blocks
      $blockStart = $bestBlock = $blockLength = $bestLength =  null;

      for ($i = 0; $i < 8; $i++) {
        // Remove zeros in front of the address-word
        $ipAddress [$i] = ltrim ($ipAddress [$i], '0');

        // Check if the word is empty
        if (strlen ($ipAddress [$i]) == 0) {
          // Set start of block
          if ($blockStart === null) {
            $blockStart = $i;
            $blockLength = 1;

          // Just increase the counter
          } else
            $blockLength++;
        } elseif ($blockStart !== null) {
          if ($blockLength > $bestLength) {
            for ($j = $bestBlock; $j < $bestBlock + $bestLength; $j++)
              $ipAddress [$j] = '0';

            $bestBlock = $blockStart;
            $bestLength = $blockLength;
          } else
            for ($j = $blockStart; $j < $blockStart + $blockLength; $j++)
              $ipAddress [$j] = '0';

          $blockStart = null;
          $blockLength = null;
        }
      }

      if (
        ($blockStart !== null) &&
        ($blockLength > $bestLength)
      ) {
        for ($j = $bestBlock; $j < $bestBlock + $bestLength; $j++)
          $ipAddress [$j] = '0';

        $bestBlock = $blockStart;
        $bestLength = $blockLength;
      }

      if (
        ($bestBlock !== null) &&
        ($bestLength > 1)
      )
        $ipAddress = array_merge (
          array_slice ($ipAddress, 0, $bestBlock + ($bestBlock === 0 ? 2 : 1)),
          array_slice ($ipAddress, $bestBlock + $bestLength)
        );

      // Return the IPv6
      return '[' . implode (':', $ipAddress) . ']';
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
    public static function setDNSResolver (Client\DNS $dnsResolver): void
    {
      self::$dnsResolver = $dnsResolver;
    }
    // }}}

    // {{{ __construct
    /**
     * Create a new event-socket
     *
     * @param Base|null $eventBase (optional)
     * @param string|array|null $remoteHost (optional)
     * @param int|null $remotePort (optional)
     * @param int|null $socketType (optional)
     * @param bool $enableTLS (optional)
     *
     * @access friendly
     * @return void
     **/
    public function __construct (Base $eventBase = null, string|array|null $remoteHost = null, int $remotePort = null, int $socketType = null, bool $enableTLS = false)
    {
      // Inherit to parent first
      parent::__construct ($eventBase);

      // Don't do anything without an events-base
      if ($eventBase === null)
        return;
      
      // Check whether to create a connection
      if ($remoteHost === null)
        return;
      
      $this->connect ($remoteHost, $remotePort, $socketType, $enableTLS);
    }
    // }}}

    // {{{ __destruct
    /**
     * Cleanly close our connection upon destruction
     *
     * @access friendly
     * @return void
     **/
    public function __destruct ()
    {
      if ($this->isConnected ())
        $this->close ();
    }
    // }}}

    // {{{ __sleep
    /**
     * Close any open connection whenever someone tries to put ourselves to sleep
     *
     * @access friendly
     * @return array
     **/
    public function __sleep (): array
    {
      if ($this->isConnected ())
        $this->close ();

      return [ 'Type' ];
    }
    // }}}

    // {{{ __wakeup
    /**
     * Give a warning if someone un-serializes us
     *
     * @access friendly
     * @return void
     **/
    public function __wakeup (): void
    {
      trigger_error ('Sockets may not be un-serialized, remember that this connection is lost now');
    }
    // }}}

    // {{{ bind
    /**
     * Try to bind our sockets to this source-address
     *
     * @param string|null $localIP (optional)
     * @param int|null $localPort (optional)
     *
     * @access public
     * @return void
     *
     * @throws InvalidArgumentException
     **/
    public function bind (string $localIP = null, int $localPort = null): void
    {
      // Make sure the IP-Address is valid
      if ($localIP !== null) {
        $isIPv6 = $this::isIPv6 ($localIP);

        if (
          !$this::isIPv4 ($localIP) &&
          !$isIPv6
        )
          throw new InvalidArgumentException ('Not an IP-Address: ' . $localIP);

        if (
          $isIPv6 &&
          !str_starts_with ($localIP, '[')
        )
          $localIP = '[' . $localIP . ']';
      }

      // Make sure the Port is valid
      if (
        ($localPort !== null) &&
        (($localPort < 1) || ($localPort > 0xFFFF))
      )
        throw new InvalidArgumentException ('Invalid port: ' . $localPort);

      // Remember the values
      $this->socketBindAddress = $localIP;
      $this->socketBindPort = $localPort;
    }
    // }}}

    // {{{ connect
    /**
     * Create a connection
     *
     * @param string|array $remoteHosts
     * @param int|null $remotePort
     * @param int|null $socketType (optional) TCP is used by default
     * @param bool $enableTLS (optional) Enable TLS-Encryption on connect
     *
     * @access public
     * @return Promise
     **/
    public function connect (string|array $remoteHosts, int $remotePort = null, int $socketType = null, bool $enableTLS = false): Promise
    {
      // Check whether to use the default socket-type
      if ($socketType === null)
        $socketType = $this::DEFAULT_TYPE;

      if ($this::FORCE_TYPE !== null)
        $socketType = $this::FORCE_TYPE;

      // Validate the type-parameter
      if (
        ($socketType != self::TYPE_TCP) &&
        ($socketType != self::TYPE_UDP)
      )
        return Promise::reject (new InvalidArgumentException ('Unsupported socket-type'));

      // Check whether to use a default port
      if ($this::FORCE_PORT !== null)
        $remotePort = $this::FORCE_PORT;

      if ($remotePort === null) {
        $remotePort = $this::DEFAULT_PORT;

        if ($remotePort === null)
          return Promise::reject (new InvalidArgumentException ('No port specified'));
      }

      // Make sure we have an event-base assigned
      if (!$this->getEventBase ())
        return Promise::reject (new RuntimeException ('No Event-Base assigned or could not assign Event-Base'));

      // Try to close any open connection before creating a new one
      # TODO: Make disconnect async
      if (
        !$this->isDisconnected () &&
        !$this->close ()
      )
        return Promise::reject ('Disconnect before connect failed');

      // Reset internal addresses
      $this->socketAddresses = null;
      $this->socketAddress = null;
      $this->connectPromise = new Deferred ($this->getEventBase ());
      $this->tlsEnabled = ($enableTLS ? null : false);

      $connectPromise = $this->connectPromise->getPromise ();

      // Make sure hosts is an array
      if (!is_array ($remoteHosts))
        $remoteHosts = [ $remoteHosts ];

      $domainNames = [];

      foreach ($remoteHosts as $remoteHost) {
        // Check for IPv6
        $isIPv6 = $this::isIPv6 ($remoteHost);

        if (
          $isIPv6 &&
          ($remoteHost [0] != '[')
        )
          $remoteHost = '[' . $remoteHost . ']';

        // Check for IPv4/v6 or whether to skip the resolver
        if (
          ($this->dnsQuestions < 0) ||
          $this::isIPv4 ($remoteHost) ||
          $isIPv6
        )
          $this->socketAddresses [] = [
            'target'   => $remoteHost,
            'hostname' => $remoteHost,
            'port'     => $remotePort,
            'type'     => $socketType,
          ];
        else
          $domainNames [] = $remoteHost;
      }

      // Put ourselves into connected-state
      $this->socketConnected = null;

      // Check if we have addresses to connect to
      if (
        $this->socketAddresses &&
        (count ($this->socketAddresses) > 0)
      )
        $this->socketConnectMulti ();

      // Perform asynchronous resolve
      # TODO: This only takes the first domainname and discards everything else
      if (
        ($this->dnsQuestions >= 0) &&
        (count ($domainNames) > 0)
      )
        $this->socketResolveDo (array_shift ($domainNames), $remotePort, $socketType);

      return $connectPromise;
    }
    // }}}

    // {{{ connectService
    /**
     * Create a connection to a named service on a given domain
     * by using DNS-SRV

     * @param string $serviceDomain
     * @param string $serviceName
     * @param int|null $socketType (optional)
     * @param bool $enableTLS (optional)
     *
     * @access public
     * @return Promise
     **/
    public function connectService (string $serviceDomain, string $serviceName, int $socketType = null, bool $enableTLS = false): Promise
    {
      // Check whether to use the default socket-type
      if ($socketType === null)
        $socketType = $this::DEFAULT_TYPE;

      if ($this::FORCE_TYPE !== null)
        $socketType = $this::FORCE_TYPE;

      // Validate the type-parameter
      if (
        ($socketType != self::TYPE_TCP) &&
        ($socketType != self::TYPE_UDP)
      )
        return Promise::reject (new InvalidArgumentException ('Unsupported socket-type'));

      // Make sure we have an event-base assigned
      if (!$this->getEventBase ())
        return Promise::reject ('Failed to get event-base');

      // Try to close any open connection before creating a new one
      # TODO: close() returns a Promise
      if (
        !$this->isDisconnected () &&
        !$this->close ()
      )
        return Promise::reject ('Failed to disconnect socket before connect');

      // Reset internal addresses
      $this->socketAddresses      = null;
      $this->socketAddress        = null;
      $this->connectPromise       = new Deferred ($this->getEventBase ());

      $this->tlsEnabled = ($enableTLS ? null : false);
      $this->socketConnected = null;
      $this->lastEvent = time ();

      $connectPromise = $this->connectPromise->getPromise ();

      // Generate label to look up
      $dnsLabel = '_' . $serviceName . '._' . ($socketType == self::TYPE_UDP ? 'udp' : 'tcp') . '.' . $serviceDomain;

      // Perform synchronous lookup
      if ($this->dnsQuestions < 0) {
        // Fire a callback
        $this->___callback ('socketResolve', [ $dnsLabel ], [ Stream\DNS\Message::TYPE_SRV ]);

        // Do the DNS-Lookup
        $dnsAdditionalRecords = [];
        $dnsAuthoritative = [];

        $dnsResult = dns_get_record ($dnsLabel, DNS_SRV, $dnsAuthoritative, $dnsAdditionalRecords);

        if (
          !is_array ($dnsResult) ||
          (count ($dnsResult) == 0)
        )
          $this->socketHandleConnectFailed ($this::ERROR_NET_DNS_FAILED, 'Failed to resolve destination (SRV) with dns_get_record()');

        // Forward the result
        else
          $this->socketResolverResultArray ($dnsResult, $dnsAdditionalRecords, $serviceDomain, DNS_SRV, 0, $socketType);

      // Perform asynchronous lookup
      } else
        $this->socketResolveDo ($dnsLabel, 0, $socketType, [ Stream\DNS\Message::TYPE_SRV ]);

      return $connectPromise;
    }
    // }}}

    // {{{ socketConnectMulti
    /**
     * Try to connect to next host on our list
     *
     * @access private
     * @return void
     **/
    private function socketConnectMulti (): void
    {
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
      $unreachableKey = $this->socketAddress ['hostname'] . ':' . $this->socketAddress ['port'] . ':' . $this->socketAddress ['type'];

      if (isset (self::$unreachableAddresses [$unreachableKey])) {
        if (time () - self::$unreachableAddresses [$unreachableKey] < $this::UNREACHABLE_TIMEOUT) {
          $this->socketHandleConnectFailed (self::ERROR_NET_UNREACHABLE, 'Destination is marked as unreachable on cache');

          return;
        }

        unset (self::$unreachableAddresses [$unreachableKey]);
      }

      unset ($unreachableKey);

      // Create new client-socket
      $socketUri = ($this->socketAddress ['type'] === self::TYPE_TCP ? 'tcp' : 'udp') . '://' . $this->socketAddress ['hostname'] . ':' . $this->socketAddress ['port'];

      if (
        ($this->socketBindAddress !== null) ||
        ($this->socketBindPort !== null)
      )
        /** @noinspection SpellCheckingInspection */
        $socketContext = stream_context_create ([
          'socket' => [
            'bindto' => ($this->socketBindAddress ?? '[::]') . ':' . (int)$this->socketBindPort,
          ]
        ]);
      else
        $socketContext = stream_context_create ();

      $streamSocket = @stream_socket_client (
        $socketUri,
        $errorNumber,
        $errorMessage,
        $this::CONNECT_TIMEOUT,
        STREAM_CLIENT_ASYNC_CONNECT,
        $socketContext
      );

      if (!is_resource ($streamSocket)) {
        $this->socketHandleConnectFailed (-$errorNumber, 'connect() failed: ' . $errorMessage);

        return;
      }

      stream_set_blocking ($streamSocket, false);

      // Set our new status
      try {
        $this->setStreamFD ($streamSocket);
      } catch (Throwable $streamError) {
        $this->socketHandleConnectFailed (self::ERROR_NET_UNKNOWN, 'setStreamFD() failed: ' . $streamError->getMessage ());
      }

      // Make sure we are watching events
      $this->watchWrite (true);
      $this->isWatching (true);

      // Set up our internal buffer-size
      $this->bufferSize = ($this->socketAddress ['type'] === self::TYPE_UDP ? self::READ_UDP_BUFFER : self::READ_TCP_BUFFER);
      $this->lastEvent = time ();

      // Set our connection-state
      if ($this->socketAddress ['type'] !== (self::TYPE_UDP ? true : null)) {
        /** @noinspection PhpUnhandledExceptionInspection */
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
     * @param Socket\Server $socketServer
     * @param string $remoteHost
     * @param resource $streamSocket (optional)
     * @param bool $enableTLS (optional)
     *
     * @remark This is for internal use only!
     *
     * @access public
     * @return void
     **/
    final public function connectServer (Socket\Server $socketServer, string $remoteHost, $streamSocket = null, bool $enableTLS = false): void
    {
      // Set our internal buffer-size
      if ($streamSocket === null) {
        $this->bufferSize = self::READ_UDP_BUFFER;

        // Store shorthand for UDP-Writes
        $this->remoteName = $remoteHost;
      } else {
        $this->bufferSize = self::READ_TCP_BUFFER;

        // Switch connection into non-blocking mode
        stream_set_blocking ($streamSocket, false);
        
        // Store the connection
        $this->setStreamFD ($streamSocket);
        $this->tlsEnabled = ($enableTLS ? null : false);
      }

      // Store our parent server-handle
      $this->serverParent = $socketServer;
      
      // Fake remote socket-settings
      $p = strrpos ($remoteHost, ':');

      $this->socketAddress = [
        'target'   => substr ($remoteHost, 0, $p),
        'hostname' => substr ($remoteHost, 0, $p),
        'port'     => (int)substr ($remoteHost, $p + 1),
        'type'     => ($streamSocket=== null ? self::TYPE_UDP_SERVER : self::TYPE_TCP),
      ];

      // Put ourselves into connected state
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
    public function socketHandleConnected (): void
    {
      // Make sure we don't time out
      if ($this->socketConnectTimer) {
        $this->socketConnectTimer->cancel ();
        $this->socketConnectTimer = null;
      }

      // Unwatch writes - as we are buffered all the time, this should be okay
      $this->watchWrite (false);

      if ($this->socketConnected !== true) {
        // Set connection-status
        $this->socketConnected = true;

        if ($this->socketAddress !== null) {
          // Set runtime-information
          $fd = $this->getReadFD ();

          if ($fd)
            $localName = stream_socket_get_name ($fd, false);
          elseif ($this->serverParent)
            $localName = $this->serverParent->getLocalName ();
          else
            $localName = '';

          $this->socketType       = $this->socketAddress ['type'];
          $this->localAddress  = substr ($localName, 0, strrpos ($localName, ':'));
          $this->localPort  = (int)substr ($localName, strrpos ($localName, ':') + 1);
          $this->remoteHost = $this->socketAddress ['target'];
          $this->remoteAddress = $this->socketAddress ['hostname'];
          $this->remotePort = $this->socketAddress ['port'];

          $this->tlsOptions ['peer_name'] = $this->remoteHost;
        }

        // Free some space now
        $this->socketAddress = null;
        $this->socketAddresses = null;

        // Check whether to enable TLS
        if (
          ($this->tlsEnabled === null) &&
          !$this->isTLS ()
        ) {
          $this->tlsEnable ()->then (
            function (): void {
              $this->socketHandleConnected ();
            },
            function (): void {
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
      ) {
        $this->socketHandleConnectFailed ($this::ERROR_NET_TLS_FAILED, 'Failed to enable TLS');

        return;
      }

      // Fire custom callback
      if ($this->connectPromise !== null) {
        $this->connectPromise->resolve ();
        $this->connectPromise = null;
      }

      // Fire the callback
      $this->___callback ('socketConnected');
    }
    // }}}

    // {{{ socketHandleConnectFailed
    /**
     * Internal Callback: Pending connection could not be established
     *
     * @param int $errorNumber (optional)
     * @param string|null $errorMessage (optional)
     *
     * @access private
     * @return void
     **/
    private function socketHandleConnectFailed (int $errorNumber = self::ERROR_NET_UNKNOWN, string $errorMessage = null): void
    {
      // Mark this host as failed
      if ($this->socketAddress !== null) {
        // Reset the address
        $Address = $this->socketAddress;
        $this->socketAddress = null;

        // Mark destination as unreachable
        self::$unreachableAddresses [$Address ['hostname'] . ':' . $Address ['port'] . ':' . $Address ['type']] = time ();

        // Check whether to retry using IPv6/NAT64
        if ($this::$nat64Prefix !== null)
          $nat64Prefix = $this::$nat64Prefix;
        elseif (
          (($nat64Prefix = getenv ('NAT64_PREFIX')) === false) ||
          (strlen ($nat64Prefix) == 0)
        )
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
        $this->___callback ('socketTryConnectFailed', $Address ['target'], $Address ['hostname'], $Address ['port'], $Address ['type'], $errorNumber);
      }
      
      // Check if there are more hosts on our list
      if (
        (
          !is_array ($this->socketAddresses) ||
          (count ($this->socketAddresses) == 0)
        ) &&
        ($this->dnsQuestions <= 0)
      ) {
        // Fire custom callback
        if ($this->connectPromise !== null) {
          static $errorStringMap = [
            self::ERROR_NET_UNKNOWN     => 'Unknown error',
            self::ERROR_NET_DNS_FAILED  => 'DNS failed',
            self::ERROR_NET_TLS_FAILED  => 'TLS failed',
            self::ERROR_NET_TIMEOUT     => 'Connection timed out',
            self::ERROR_NET_REFUSED     => 'Connection refused',
            self::ERROR_NET_UNREACHABLE => 'Destination unreachable',
          ];
          
          if ($errorMessage === null)
            $errorMessage = ($errorStringMap [$errorNumber] ?? $errorStringMap [self::ERROR_NET_UNKNOWN]);

          $this->connectPromise->reject ($errorMessage, $errorNumber);
          $this->connectPromise = null;
        }

        // Fire the callback
        $this->___callback ('socketConnectionFailed', $errorMessage, $errorNumber);

        // Disconnect cleanly
        $this->close ();

        return;
      }

      // Try the next host
      $this->socketConnectMulti ();
    }
    // }}}

    // {{{ socketResolveDo
    /**
     * Resolve a given hostname
     *
     * @param string $hostName
     * @param int $remotePort
     * @param int $socketType
     * @param array|null $dnsRecordTypes (optional)
     *
     * @access private
     * @return void
     **/
    private function socketResolveDo (string $hostName, int $remotePort, int $socketType, array $dnsRecordTypes = null): void
    {
      // Don't do further resolves if we are already connected
      if ($this->isConnected ())
        return;

      // Create a new resolver
      if (!is_object (self::$dnsResolver))
        # TODO: This is bound to our event-base
        self::$dnsResolver = new Client\DNS ($this->getEventBase ());

      // Check which types to resolve
      if ($dnsRecordTypes === null)
        $dnsRecordTypes = [
          Stream\DNS\Message::TYPE_AAAA,
          Stream\DNS\Message::TYPE_A,
        ];

      // Enqueue Hostnames
      $this->dnsQuestions += count ($dnsRecordTypes);

      foreach ($dnsRecordTypes as $dnsRecordType)
        self::$dnsResolver->resolve ($hostName, $dnsRecordType)->then (
          function (Stream\DNS\Recordset $Answers, Stream\DNS\Recordset $Authorities, Stream\DNS\Recordset $Additional, Stream\DNS\Message $Response)
          use ($hostName, $remotePort, $socketType, $dnsRecordType): void {
            // Decrease counter
            $this->dnsQuestions--;

            // Discard any result if we are connected already
            if ($this->isConnected ())
              return;

            // Update our last event (to prevent a pending disconnect)
            $this->lastEvent = time ();

            // Convert the result
            $dnsAuthorities = [];
            $dnsAdditional = [];
            $dnsResult = self::$dnsResolver->dnsConvertPHP ($Response, $dnsAuthorities, $dnsAdditional);

            // Forward
            $this->socketResolverResultArray ($dnsResult, $dnsAdditional, $hostName, $dnsRecordType, $remotePort, $socketType);
          },
          function () use ($hostName, $remotePort, $socketType, $dnsRecordType): void {
            // Decrease counter
            $this->dnsQuestions--;

            $this->socketResolverResultArray ([], [], $hostName, $dnsRecordType, $remotePort, $socketType);
          }
        );

      // Update last action
      $this->lastEvent = time ();

      // Fire a callback
      $this->___callback ('socketResolve', [ $hostName ], $dnsRecordTypes);

      // Set up a timeout
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
    private function socketSetupConnectionTimeout (): void
    {
      // Check if we are already set up
      if ($this->socketConnectTimer) {
        $this->socketConnectTimer->restart ();

        return;
      }

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
     * @param array $dnsResults Results returned from the resolver
     * @param array $dnsAdditional Additional results returned from the resolver
     * @param string $hostName The Hostname we are looking for
     * @param int $dnsRecordType DNS-Record-Type we are looking for
     * @param int $remotePort The port we want to connect to
     * @param int $socketType The type of socket we wish to create
     *
     * @access private
     * @return void
     **/
    private function socketResolverResultArray (array $dnsResults, array $dnsAdditional, string $hostName, int $dnsRecordType, int $remotePort, int $socketType): void
    {
      // Check if there are no results
      if (
        (count ($dnsResults) == 0) &&
        ($this->dnsQuestions <= 0)
      ) {
        // Mark connection as failed if there are no addresses pending and no current address
        if (
          (
            !is_array ($this->socketAddresses) ||
            (count ($this->socketAddresses) == 0)
          ) &&
          ($this->socketAddress === null)
        )
          $this->socketHandleConnectFailed ($this::ERROR_NET_DNS_FAILED, 'Failed to resolve destination "' . $hostName . '"');

        return;
      }

      // Handle all results
      $resolvedAddresses = [];
      $dnsQueries = [];

      while (count ($dnsResults) > 0) {
        $dnsRecord = array_shift ($dnsResults);

        // Check for a normal IP-Address
        if (
          ($dnsRecord ['type'] == 'A') ||
          ($dnsRecord ['type'] == 'AAAA')
        ) {
          if (!is_array ($this->socketAddresses))
            $this->socketAddresses = [ ];

          $resolvedAddress = ($dnsRecord ['type'] == 'AAAA' ? '[' . $dnsRecord ['ipv6'] . ']' : $dnsRecord ['ip']);
          $resolvedAddresses [] = $resolvedAddress;

          $this->socketAddresses [] = [
            'target'   => $hostName,
            'hostname' => $resolvedAddress,
            'port'     => ($dnsRecord ['port'] ?? $remotePort),
            'type'     => $socketType,
          ];

        // Handle canonical names
        } elseif ($dnsRecord ['type'] == 'CNAME') {
          // Check additional
          $recordFound = false;

          foreach ($dnsResults as $dnsOtherRecord) {
            $recordFound = ($dnsOtherRecord ['host'] == $dnsRecord ['target']);

            if ($recordFound)
              break;
          }

          foreach ($dnsAdditional as $dnsOtherRecord)
            if ($dnsOtherRecord ['host'] === $dnsRecord ['target']) {
              $dnsResults [] = $dnsOtherRecord;
              $recordFound = true;
            }

          // Check whether to enqueue this name as well
          if ($recordFound)
            continue;

          if (!in_array ($dnsRecord ['target'], $dnsQueries)) {
            $dnsQueries [] = $dnsRecord ['target'];
            $this->socketResolveDo ($dnsRecord ['target'], $remotePort, $socketType, [ $dnsRecordType ]);
          }
        // Handle SRV-Records
        } elseif ($dnsRecord ['type'] == 'SRV') {
          // Check additional
          $recordFound = false;

          foreach ($dnsAdditional as $dnsOtherRecord)
            if ($dnsOtherRecord ['host'] === $dnsRecord ['target']) {
              $dnsOtherRecord ['port'] = $dnsRecord ['port'];
              $dnsResults [] = $dnsOtherRecord;
              $recordFound = true;
            }

          // Resolve deeper
          if (
            !$recordFound &&
            !in_array ($dnsRecord ['target'], $dnsQueries)
          ) {
            $dnsQueries [] = $dnsRecord ['target'];
            $this->socketResolveDo ($dnsRecord ['target'], $dnsRecord ['port'], $socketType);
          }
        }
      }

      // Fire up new callback
      $this->___callback ('socketResolved', $hostName, $resolvedAddresses, $dnsQueries);

      // Check whether to try to connect
      if (
        is_array ($this->socketAddresses) &&
        (count ($this->socketAddresses) > 0)
      )
        $this->socketConnectMulti ();
    }
    // }}}

    // {{{ useInternalResolver
    /**
     * Set whether to use internal resolver for connects
     *
     * @param bool|null $useInternalResolver (optional)
     *
     * @access public
     * @return bool
     **/
    public function useInternalResolver (bool $useInternalResolver = null): bool
    {
      if ($useInternalResolver === null)
        return ($this->dnsQuestions >= 0);

      if ($useInternalResolver === false)
        $this->dnsQuestions = -1;
      elseif ($this->dnsQuestions < 0)
        $this->dnsQuestions = 0;

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
    public function ___close ($closeFD = null): bool
    {
      // Check if we are connected/connecting
      if ($this->isDisconnected ())
        return true;

      // Check whether to terminate the connection at any parent entity
      if ($this->socketType === self::TYPE_UDP_SERVER) {
        if (!is_object ($this->serverParent))
          return false;

      // Close our own connection
      } else {
        if ($closeFD)
          fclose ($closeFD);
      }

      if (is_object ($this->serverParent))
        $this->serverParent->disconnectChild ($this);

      // Reset our status
      $this->socketConnected = false;
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
     * Check if we are not connected at the moment and do not make any attempts to get connected
     *
     * @access public
     * @return bool
     **/
    public function isDisconnected (): bool
    {
      return ($this->socketConnected === false);
    }
    // }}}

    // {{{ isConnecting
    /**
     * Check if we are trying to connect to a remote party
     *
     * @access public
     * @return bool
     **/
    public function isConnecting (): bool
    {
      return ($this->socketConnected === null);
    }
    // }}}

    // {{{ isConnected
    /**
     * Check if our connection was established successfully
     *
     * @access public
     * @return bool
     **/
    public function isConnected (): bool
    {
      return ($this->socketConnected === true);
    }
    // }}}

    // {{{ isUDP
    /**
     * Check if this is a UDP-Socket
     *
     * @access public
     * @return bool
     **/
    public function isUDP (): bool
    {
      return (
        ($this->socketType === self::TYPE_UDP) ||
        ($this->socketType === self::TYPE_UDP_SERVER)
      );
    }
    // }}}

    // {{{ isServer
    /**
     * Check if this is a Server-Socket
     *
     * @access public
     * @return bool
     **/
    public function isServer (): bool
    {
      return ($this->serverParent !== null);
    }
    // }}}

    // {{{ getType
    /**
     * Retrieve the type of this socket
     *
     * @access public
     * @return integer
     **/
    public function getType (): int
    {
      return $this->socketType;
    }
    // }}}

    // {{{ getLocalName
    /**
     * Retrieve the local name of our socket
     *
     * @access public
     * @return string
     **/
    public function getLocalName (): string
    {
      return ($this::isIPv6 ($this->localAddress ?? '::') ? '[' . ($this->localAddress ?? '::') . ']' : $this->localAddress) . ':' . ($this->localPort ?? 0);
    }
    // }}}

    // {{{ getLocalAddress
    /**
     * Retrieve the local address of our socket
     *
     * @access public
     * @return string
     **/
    public function getLocalAddress (): string
    {
      return ($this->localAddress ?? '[::]');
    }
    // }}}

    // {{{ getLocalPort
    /**
     * Retrieve the local port of our socket
     *
     * @access public
     * @return int|null
     **/
    public function getLocalPort (): ?int
    {
      return $this->localPort;
    }
    // }}}

    // {{{ getRemoteHost
    /**
     * Retrieve the hostname of the remote party
     *
     * @access public
     * @return string|null
     **/
    public function getRemoteHost (): ?string
    {
      return $this->remoteHost;
    }
    // }}}

    // {{{ getRemoteAddress
    /**
     * @access public
     * @return string|null
     */
    public function getRemoteAddress (): ?string
    {
      return $this->remoteAddress;
    }
    // }}}

    // {{{ getRemotePort
    /**
     * Retrieve the port we are connected to
     *
     * @access public
     * @return int|null
     **/
    public function getRemotePort (): ?int
    {
      return $this->remotePort;
    }
    // }}}

    // {{{ getRemoteName
    /**
     * Retrieve the hostname/ip-address and port of the remote party
     *
     * @access public
     * @return string|null
     **/
    public function getRemoteName (): ?string
    {
      if ($this->remoteName !== null)
        return $this->remoteName;

      if ($this->remoteHost === null)
        return null;

      return $this->remoteHost . ':' . $this->remotePort;
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
    public function write (string $writeData): Promise
    {
      // Bypass write-buffer in UDP-Server-Mode
      if ($this->socketType === self::TYPE_UDP_SERVER) {
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
     * @return int|null Number of bytes written
     **/
    protected function ___write (string $writeData): ?int
    {
      // Make sure we have a socket available
      if ($this->socketType !== self::TYPE_UDP_SERVER)
        $socketDescriptor = $this->getWriteFD ();
      elseif (is_object ($this->serverParent))
        $socketDescriptor = $this->serverParent->getWriteFDforClient ($this);
      else
        return null;

      if (!is_resource ($socketDescriptor))
        return null;

      // Perform a normal unbuffered write
      $this->lastEvent = time ();

      if ($this->socketType === self::TYPE_UDP_SERVER)
        $writtenLength = stream_socket_sendto ($socketDescriptor, $writeData, 0, $this->remoteName);
      else
        $writtenLength = fwrite ($socketDescriptor, $writeData);

      if (
        (
          ($writtenLength === false) ||
          ($writtenLength === 0)
        ) &&
        feof ($socketDescriptor)
      )
        $this->close (true);

      if ($writtenLength === false)
        return null;

      return $writtenLength;
    }
    // }}}

    // {{{ tlsSupported
    /**
     * Check if we have TLS-Support available
     *
     * @access public
     * @return bool  
     **/
    public function tlsSupported (): bool
    {
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
    public function tlsCiphers (array $tlsCiphers): void
    {
      $this->tlsOptions ['ciphers'] = implode (':', $tlsCiphers);

      if ($this->getReadFD ())
        stream_context_set_option ($this->getReadFD (), [ 'ssl' => $this->tlsOptions ]);
    }
    // }}}

    // {{{ tlsCertificate
    /**
     * Setup TLS-Certificates for this end of the stream
     *
     * The Certificate-File has to contain both key and certificate in PEM-Format,
     * an optional CA-Chain may be included as well.
     *
     * @param string $certFile
     * @param array|null $sniCerts (optional)
     *
     * @access public
     * @return void
     **/
    public function tlsCertificate (string $certFile, array $sniCerts = null): void
    {
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
     * @param bool $allowSelfSigned (optional) Allow self-signed certificates
     * @param string|null $caFile (optional) File or Directory containing CA-Certificates
     * @param int|null $verifyDepth (optional) Verify-Depth
     * @param string|null $expectedFingerprint (optional) Expected fingerprint of peers certificate
     *
     * @access public
     * @return void
     **/
    public function tlsVerify (bool $verifyPeer = true, bool $verifyName = true, bool $allowSelfSigned = false, string $caFile = null, int $verifyDepth = null, string $expectedFingerprint = null): void
    {
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
        $this->tlsOptions ['peer_fingerprint'] = $expectedFingerprint;

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
    public function tlsEnable (): Promise
    {
      // Check if we are in an unclean status at the moment
      if (
        ($this->tlsEnabled === null) &&
        $this->tlsPromise
      )
        return $this->tlsPromise->getPromise ();

      // Make sure we have promise for this
      if (!$this->tlsPromise)
        $this->tlsPromise = new Promise\Deferred ();

      # TODO: No clue at the moment how to do this on UDP-Server
      # TODO: Check if this simply works - we are doing this in non-blocking mode,
      #       so it might be possible to distinguish by normal peer-multiplexing
      if ($this->socketType === self::TYPE_UDP_SERVER)
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
    public function isTLS (): bool
    {
      return ($this->tlsEnabled === true);
    }
    // }}}

    // {{{ setTLSMode
    /**
     * Try to set up an TLS-secured connection
     *
     * @access private
     * @return void
     **/
    private function setTLSMode (): void
    {
      // Make sure we know our connection
      $socketFD = $this->getReadFD ();

      if (!is_resource ($socketFD))
        return;

      // Issue the request to enter or leave TLS-Mode
      if ($this->serverParent)
        $tlsMethod = STREAM_CRYPTO_METHOD_TLSv1_1_SERVER | STREAM_CRYPTO_METHOD_TLSv1_2_SERVER;
      else
        $tlsMethod = STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;

      $tlsRequest = @stream_socket_enable_crypto ($socketFD, true, $tlsMethod);

      // Check if the request succeeded
      if ($tlsRequest === true) {
        $this->tlsEnabled = true;

        $this->___callback ('tlsEnabled');

        $this->tlsPromise?->resolve ();

      // Check if the request failed
      } elseif ($tlsRequest === false) {
        $this->tlsEnabled = false;

        $this->___callback ('tlsFailed');

        if ($this->tlsPromise) {
          $lastError = error_get_last ();

          if ($lastError) {
            if (str_starts_with ($lastError ['message'], 'stream_socket_enable_crypto(): '))
              $lastError ['message'] = substr ($lastError ['message'], 31);

            $this->tlsPromise->reject (new SocketException\Encryption ('Failed to enable TLS: ' . $lastError ['message']));
          } else
            $this->tlsPromise->reject (new SocketException\Encryption ('Failed to enable TLS'));
        }
      }
    }
    // }}}

    // {{{ getLastEvent
    /** 
     * Retrieve the timestamp when the last read/write-Event happened on this socket
     *
     * @access public   
     * @return int   
     **/  
    public function getLastEvent (): int
    {
      return $this->lastEvent;
    }
    // }}}

    // {{{ getReadFD
    /**
     * Retrieve the stream-resource to watch for reads
     *
     * @access public
     * @return resource May return NULL if no reads should be watched
     **/
    public function getReadFD ()
    {
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
    final public function raiseRead (): void
    {
      // Check the idle-timer
      $this->idleTimer?->restart ();

      // Let TLS intercept here
      if ($this->tlsEnabled === null) {
        $this->setTLSMode ();

        return;
      }

      // Check if our buffer reached the watermark
      if ($this->readBufferLength >= $this::READ_BUFFER_SIZE) {
        if ($eventBase = $this->getEventBase ())
          $eventBase->updateEvent ($this);

        return;
      }

      // Read incoming data from socket
      $incomingData = fread ($this->getReadFD (), $this->bufferSize);

      if (
        ($incomingData === '') ||
        ($incomingData === false)
      ) {
        if ($this->isConnecting ()) {
          $this->socketHandleConnectFailed ($this::ERROR_NET_REFUSED, 'Connection refused');

          return;
        }

        // Check if the socket is really closed
        if (!feof ($this->getReadFD ()))
          return;

        // Close the socket on this side
        $this->close ();

        return;
      }

      // Check if we are in connecting state
      if ($this->isConnecting ())
        $this->socketHandleConnected ();

      // Forward this internally
      $this->receiveInternal ($incomingData);

      if ($this->readBufferLength > 0) {
        $eventBase = $this->getEventBase ();

        $eventBase?->forceCallback (
          fn () => $this->___callback ('eventReadable')
        );
      }
    }
    // }}}

    // {{{ readUDPServer
    /**
     * Receive Data from a UDP-Server
     *
     * @param string $readData
     * @param Socket\Server $parentServer
     *
     * @access public
     * @return void
     *
     * @throws RuntimeException
     * @throws InvalidArgumentException
     **/
    public function readUDPServer (string $readData, Socket\Server $parentServer): void
    {
      // Validate the incoming request
      if ($this->socketType !== self::TYPE_UDP_SERVER)
        throw new RuntimeException ('Not an UDP-Server-Socket');

      if ($parentServer !== $this->serverParent)
        throw new InvalidArgumentException ('Invalid parent UDP-Server');

      // Forward internally
      $this->receiveInternal ($readData);
    }  
    // }}}

    // {{{ receiveInternal
    /**
     * Receive and process incoming data
     *
     * @param string $incomingData
     *
     * @access private
     * @return void
     **/
    private function receiveInternal (string $incomingData): void
    {
      // Set the last event
      $this->lastEvent = time ();

      // Forward to read-buffer
      $this->readBuffer .= $incomingData;
      $this->readBufferLength += strlen ($incomingData);

      unset ($incomingData);

      // Fire up the callbacks
      $this->___callback ('eventReadable');
      $this->___callback ('socketReadable');
    }
    // }}}

    // {{{ ___read
    /**
     * Read data from our internal buffer
     *
     * @param int|null $readLength (optional)
     *
     * @access protected
     * @return string
     **/
    protected function ___read (int $readLength = null): string
    {
      // Check if the buffer was full before reading
      $readBufferFull = ($this->readBufferLength >= $this::READ_BUFFER_SIZE);

      // Check how many bytes to read
      if (
        ($readLength === null) ||
        ($readLength >= $this->readBufferLength)
      ) {
        $readBuffer = $this->readBuffer;
        $this->readBuffer = '';
        $this->readBufferLength = 0;
      } else {
        $readBuffer = substr ($this->readBuffer, 0, $readLength = abs ($readLength));
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

      return $readBuffer;
    }
    // }}}

    // {{{ raiseWrite
    /**
     * Callback: The Event-Loop detected a write-event
     *
     * @access public
     * @return void
     **/
    public function raiseWrite (): void
    {
      // Check the idle-timer
      $this->idleTimer?->restart ();

      // Inherit to our parent for the rest
      parent::raiseWrite ();
    }
    // }}}

    // {{{ setIdleTimeout
    /**
     * Set Idle-Timeout for this socket.
     *
     * Once a socket was idle for the amount of time set it will be closed automatically.
     *
     * @param float $idleTimeout
     *
     * @access public
     * @return Timer
     **/
    public function setIdleTimeout (float $idleTimeout): Timer
    {
      if ($this->idleTimer === null) {
        $this->idleTimer = $this->getEventBase ()->addTimeout ($idleTimeout);

        $this->idleTimer->then (
          fn () => $this->close ()
        );
      } else {
        $this->idleTimer->setInterval ($idleTimeout);
        $this->idleTimer->restart ();
      }

      return $this->idleTimer;
    }
    // }}}

    // {{{ socketTryConnect
    /**
     * Callback: We are trying to connect to a given host
     *
     * @param string $desiredHost
     * @param string $actualHost
     * @param int $remotePort
     * @param int $socketType
     *
     * @access protected
     * @return void
     **/
    protected function socketTryConnect (string $desiredHost, string $actualHost, int $remotePort, int $socketType): void
    {
      // No-Op
    }
    // }}}

    // {{{ socketTryConnectFailed
    /**
     * Callback: One try to connect to a host failed
     *
     * @param string $desiredHost
     * @param string $actualHost
     * @param int $remotePort
     * @param int $socketType
     * @param int $errorNumber
     *
     * @access protected
     * @return void
     **/
    protected function socketTryConnectFailed (string $desiredHost, string $actualHost, int $remotePort, int $socketType, int $errorNumber): void
    {
      // No-Op
    }
    // }}}

    // {{{ socketResolve
    /**
     * Callback: Internal resolver started to look for Addresses
     *
     * @param array $hostNames
     * @param array $dnsRecordTypes
     *
     * @access protected
     * @return void
     **/
    protected function socketResolve (array $hostNames, array $dnsRecordTypes): void
    {
      // No-Op
    }
    // }}}

    // {{{ socketResolved
    /**
     * Callback: Internal resolver returned a result for something
     *
     * @param string $hostName
     * @param array $resolvedAddresses
     * @param array $otherNames
     *
     * @access protected
     * @return void
     **/
    protected function socketResolved (string $hostName, array $resolvedAddresses, array $otherNames): void
    {
      // No-Op
    }
    // }}}

    // {{{ socketConnected
    /**
     * Callback: Connection was successfully established
     *
     * @access protected
     * @return void
     **/
    protected function socketConnected (): void
    {
      // No-Op
    }
    // }}}

    // {{{ socketConnectionFailed
    /**
     * Callback: Connection could not be established
     *
     * @param string $errorMessage
     * @param int $errorNumber
     *
     * @access protected
     * @return void
     **/
    protected function socketConnectionFailed (string $errorMessage, int $errorNumber): void
    {
      // No-Op
    }
    // }}}

    // {{{ socketReadable
    /**
     * Callback: Received data is available on internal buffer
     *
     * @access protected
     * @return void
     **/
    protected function socketReadable (): void
    {
      // No-Op
    }
    // }}}

    // {{{ socketDrained
    /**
     * Callback: Write-Buffer is now empty
     *
     * @access protected
     * @return void
     **/
    protected function socketDrained (): void
    {
      // No-Op
    }
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
    protected function socketDisconnected (): void
    {
      // No-Op
    }
    // }}}

    // {{{ tlsEnabled
    /**
     * Callback: TLS was successfully enabled
     *
     * @access protected
     * @return void
     **/
    protected function tlsEnabled (): void
    {
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
    protected function tlsFailed (): void
    {
      // No-Op
    }
    // }}}
  }
