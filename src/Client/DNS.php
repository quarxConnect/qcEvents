<?php

  /**
   * quarxConnect Events - Asynchronous DNS Resolver
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

  namespace quarxConnect\Events\Client;

  use InvalidArgumentException;
  use quarxConnect\Events\ABI;
  use quarxConnect\Events\Base;
  use quarxConnect\Events\Emitter;
  use quarxConnect\Events\Feature;
  use quarxConnect\Events\Promise;
  use quarxConnect\Events\Socket;
  use quarxConnect\Events\Stream;

  use quarxConnect\Events\Socket\Exception\InvalidPort;
  use quarxConnect\Events\Socket\Exception\InvalidType;
  use RuntimeException;
  use Throwable;

  /**
   * Asynchronous DNS Resolver
   * ------------------------
   *
   * @class DNS
   * @extends Emitter
   * @package quarxConnect\Events
   * @revision 02
   **/
  class DNS extends Emitter implements ABI\Based
  {
    use Feature\Based;

    /**
     * Prefix for DNS64-Hack
     *
     * @var string|null
     **/
    public static string|null $dns64Prefix = null;

    /**
     * Cached DNS-Results
     *
     * @var array
     **/
    private static array $dnsCache = [];

    /**
     * List of nameservers to use for resolving
     *
     * @var array
     **/
    private array $dnsNameservers = [];

    /**
     * List of active queries
     *
     * @var array
     **/
    private array $dnsQueries = [];

    /**
     * Timeout for DNS-Queried
     *
     * @var float
     **/
    private float $dnsQueryTimeout = 5.00;

    /**
     * Time between trying nameservers
     *
     * @var float
     **/
    private float $dnsQuestionInterval = 1.00;

    // {{{ __construct
    /**
     * Create a new HTTP-Client Pool   
     *
     * @param Base $eventBase
     *
     * @access friendly
     * @return void
     **/
    public function __construct (Base $eventBase)
    {
      $this->setEventBase ($eventBase);
    }
    // }}}

    // {{{ getNameservers
    /**
     * Retrieve configured nameservers for this client
     *
     * @access public
     * @return array
     **/
    public function getNameservers (): array
    {
      return $this->dnsNameservers;
    }
    // }}}
    
    // {{{ setNameserver
    /**
     * Set the nameserver we should use
     *
     * @param string $serverIP
     * @param int|null $serverPort (optional)
     * @param int|null $serverProto (optional)
     *
     * @access public
     * @return void
     *
     * @throws InvalidArgumentException
     **/
    public function setNameserver (string $serverIP, int $serverPort = null, int $serverProto = null): void
    {
      if (
        !Socket::isIPv4 ($serverIP) &&
        !Socket::isIPv6 ($serverIP)
      )
        throw new InvalidArgumentException ('Invalid DNS-Server IP-address');

      if (
        ($serverProto !== null) &&
        ($serverProto !== Socket::TYPE_UDP) &&
        ($serverProto !== Socket::TYPE_TCP)
      )
        throw new InvalidType ();

      if (
        ($serverPort < 0x0001) ||
        ($serverPort > 0xffff)
      )
        throw new InvalidPort ();

      $this->dnsNameservers = [
        [
          'ip' => $serverIP,
          'port' => $serverPort ?? 53,
          'proto' => $serverProto ?? Socket::TYPE_UDP,
        ]
      ];
    }
    // }}}

    // {{{ setNameservers
    /**
     * Set a new set of nameservers
     *
     * @param iterable $dnsNameservers
     *
     * @access public
     * @return void
     *
     * @throws InvalidArgumentException
     **/
    public function setNameservers (iterable $dnsNameservers): void
    {
      $newServers = [];

      foreach ($dnsNameservers as $dnsNameserver) {
        $newServer = [
          'ip' => $dnsNameserver ['ip'] ?? '',
          'port' => $dnsNameserver ['port'] ?? 53,
          'proto' => $dnsNameserver ['proto'] ?? Socket::TYPE_UDP,
        ];

        if (
          !Socket::isIPv4 ($newServer ['ip']) &&
          !Socket::isIPv6 ($newServer ['ip'])
        )
          throw new InvalidArgumentException ('Invalid DNS-Server IP-address');

        if (
          ($newServer ['proto'] !== null) &&
          ($newServer ['proto'] !== Socket::TYPE_UDP) &&
          ($newServer ['proto'] !== Socket::TYPE_TCP)
        )
          throw new InvalidType ();

        if (
          !is_int ($newServer ['port']) ||
          ($newServer ['port'] < 0x0001) ||
          ($newServer ['port'] > 0xffff)
        )
          throw new InvalidPort ();

        $newServers [] = $newServer;
      }

      $this->dnsNameservers = $newServers;
    }
    // }}}

    // {{{ useSystemNameserver
    /**
     * Load nameservers from /etc/resolv.conf
     *
     * @access public
     * @return void
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     **/
    public function useSystemNameserver (): void
    {
      // Check if the registry exists
      if (!is_file ('/etc/resolv.conf'))
        throw new RuntimeException ('Missing /etc/resolv.conf');

      // Try to load it into an array
      $confLines = @file ('/etc/resolv.conf');

      if (!is_array ($confLines))
        throw new RuntimeException ('Failed to read /etc/resolv.conf');

      // Extract nameservers
      $dnsNameservers = [];

      foreach ($confLines as $confLine) {
        // Check for nameserver-line
        if (!str_starts_with ($confLine, 'nameserver '))
          continue;

        // Extract IP-Address
        $serverIP = trim (substr ($confLine, 11));

        // Sanitize IP-Address
        if (
          !Socket::isIPv4 ($serverIP) &&
          !Socket::isIPv6 ($serverIP)
        )
          continue;

        // Push to available nameservers
        $dnsNameservers [] = [
          'ip' => trim (substr ($confLine, 11)),
          'port' => 53,
          'proto' => Socket::TYPE_UDP,
        ];
      }

      // Check if any valid nameserver was read from configuration
      if (count ($dnsNameservers) == 0)
        throw new InvalidArgumentException ('No nameservers read from /etc/resolv.conf');

      // Set the nameservers
      $this->dnsNameservers = $dnsNameservers;
    }
    // }}}

    // {{{ setTimeout
    /**
     * Set timeout for DNS-Queries
     *
     * @param float $queryTimeout
     *
     * @access public
     * @return void
     **/
    public function setTimeout (float $queryTimeout): void
    {
      $this->dnsQueryTimeout = $queryTimeout;
    }
    // }}}

    // {{{ resolve
    /**
     * Perform DNS-Resolve
     *
     * @param string|Stream\DNS\Label $dnsName
     * @param int|null $dnsType (optional)
     * @param int|null $dnsClass (optional)
     *
     * @access public
     * @return Promise
     **/
    public function resolve (string|Stream\DNS\Label $dnsName, int $dnsType = null, int $dnsClass = null): Promise
    {
      // Sanitize parameters
      $dnsName = strtolower ((string)$dnsName);

      if ($dnsType === null)
        $dnsType = Stream\DNS\Message::TYPE_A;

      if ($dnsClass === null)
        $dnsClass = Stream\DNS\Message::CLASS_INTERNET;

      // Check the cache
      $dnsKey = $dnsName . '-' . $dnsType . '-' . $dnsClass;

      if (isset (self::$dnsCache [$dnsKey])) {
        $timeDiff = time () - self::$dnsCache [$dnsKey]['timestamp'];
        $dnsInvalid = false;

        foreach ([ 'answers', 'authorities', 'additional' ] as $dnsSection)
          foreach (self::$dnsCache [$dnsKey][$dnsSection] as $dnsRecord) {
            $dnsInvalid = ($dnsRecord->getTTL () < $timeDiff);

            if ($dnsInvalid)
              break;
          }

        if (!$dnsInvalid)
          return Promise::resolve (
            self::$dnsCache [$dnsKey]['answers'],
            self::$dnsCache [$dnsKey]['authorities'],
            self::$dnsCache [$dnsKey]['additional'],
            self::$dnsCache [$dnsKey]['response']
          );

        unset (self::$dnsCache [$dnsKey]);
      }

      // Create a DNS-Query
      $dnsMessage = new Stream\DNS\Message ();
      $dnsMessage->isQuestion (true);

      $dnsMessage->addQuestion (
        new Stream\DNS\Question ($dnsName, $dnsType, $dnsClass)
      );

      // Enqueue the query
      return $this->enqueueQuery ($dnsMessage)->then (
        function (
          Stream\DNS\Recordset $dnsAnswers,
          Stream\DNS\Recordset $dnsAuthorities,
          Stream\DNS\Recordset $dnsAdditional,
          Stream\DNS\Message $dnsResponse
        ) use ($dnsKey): Promise\Solution {
          // Push the result to cache
          self::$dnsCache [$dnsKey] = [
            'timestamp' => time (),
            'answers' => $dnsAnswers,
            'authorities' => $dnsAuthorities,
            'additional' => $dnsAdditional,
            'response' => $dnsResponse,
          ];

          // And pass the result
          return new Promise\Solution (func_get_args ());
        }
      );
    }
    // }}}

    // {{{ enqueueQuery
    /**
     * Enqueue a prepared dns-message for submission
     *
     * @param Stream\DNS\Message $dnsQuery
     *
     * @access public
     * @return Promise
     **/
    public function enqueueQuery (Stream\DNS\Message $dnsQuery): Promise
    {
      try {
        // Make sure we have nameservers registered
        if (count ($this->dnsNameservers) == 0)
          $this->useSystemNameserver ();
      } catch (Throwable $bootstrapError) {
        return Promise::reject ($bootstrapError, $this->getEventBase ());
      }

      // Make sure the message is a question
      if (!$dnsQuery->isQuestion ())
        return Promise::reject (new InvalidArgumentException ('Message must be a question'));

      // Prepare everything
      $dnsPromise = new Promise\Deferred ($this->getEventBase ());
      $dnsTimer = $this->getEventBase ()->addTimeout (min ($this->dnsQueryTimeout, $this->dnsQuestionInterval), true);
      $nameserverIndex = 0;

      $startQuery = fn (int $nextIndex): Promise => $this->askNameserver (
        $this->dnsNameservers [$nextIndex],
        clone $dnsQuery
      )->then (
        function () use ($dnsPromise, $dnsTimer) {
          // Cancel the timer
          $dnsTimer->cancel ();

          // Forward the result
          call_user_func_array ([ $dnsPromise, 'resolve' ], func_get_args ());
        },
        function () use ($dnsPromise, $dnsTimer, $nextIndex, &$nameserverIndex) {
          // Check if there are other nameservers remaining
          if ($nextIndex < count ($this->dnsNameservers) - 1) {
            // Check whether to query next nameserver
            if ($nextIndex == $nameserverIndex)
              $dnsTimer->run ();

            return;
          }

          // Forward the rejection
          call_user_func_array ([ $dnsPromise, 'reject' ], func_get_args ());
        }
      );

      $dnsTimer->then (
        function () use (&$nameserverIndex, $startQuery, $dnsTimer): void {
          // Check if there are nameservers available
          if (!isset ($this->dnsNameservers [++$nameserverIndex])) {
            $dnsTimer->cancel ();

            return;
          }

          // Start query on next nameserver
          call_user_func ($startQuery, $nameserverIndex);
        }
      );

      // Start query on first nameserver
      call_user_func ($startQuery, $nameserverIndex);

      return $dnsPromise->getPromise ();
    }
    // }}}

    // {{{ askNameserver
    /**
     * Push a dns-question to a given nameserver
     *
     * @param array $dnsNameserver
     * @param Stream\DNS\Message $dnsQuery
     *
     * @access private
     * @return Promise
     **/
    private function askNameserver (array $dnsNameserver, Stream\DNS\Message $dnsQuery): Promise
    {
      // Create a socket and a stream for this query
      $dnsSocket = new Socket ($this->getEventBase ());
      $dnsSocket->useInternalResolver (false);

      $dnsStream = new Stream\DNS ();
      $dnsStream->setTimeout ($this->dnsQueryTimeout);

      return $dnsSocket->connect (
        $dnsNameserver ['ip'],
        $dnsNameserver ['port'],
        $dnsNameserver ['proto']
      )->then (
        fn () => $dnsSocket->pipe ($dnsStream)
      )->then (
        function () use ($dnsSocket, $dnsStream, $dnsQuery) {
          // Pick a free message-id
          if (
            !($queryID = $dnsQuery->getID ()) ||
            isset ($this->dnsQueries [$queryID])
          )
            while ($queryID = $dnsQuery->setRandomID ())
              if (!isset ($this->dnsQueries [$queryID]))
                break;

          // Enqueue the query
          $this->dnsQueries [$queryID] = $dnsQuery;

          // Write out the message
          $dnsStream->dnsStreamSendMessage ($dnsQuery);

          return Promise::race ([
            $dnsStream->once (
              'dnsResponseReceived'
            )->then (
              function (Stream\DNS\Message $dnsResponse)
              use ($dnsQuery) {
                // Check if an error was received
                if (($errorCode = $dnsResponse->getError ()) != $dnsResponse::ERROR_NONE)
                  throw new RuntimeException ('Error-Code received: ' . $errorCode);

                // Dispatch an event for this
                $dnsEvent = new DNS\Event\Result ($dnsQuery, $dnsResponse, $this::$dns64Prefix);

                return $this->dispatch (
                  $dnsEvent
                )->then (
                  fn () => new Promise\Solution ([
                    $dnsEvent->getAnswers (),
                    $dnsResponse->getAuthorities (),
                    $dnsResponse->getAdditionals (),
                    $dnsResponse
                  ])
                );
              }
            ),
            $dnsStream->once (
              'dnsQuestionTimeout'
            )->then (
              // Dispatch timeout-event
              fn (): Promise => $this->dispatch (
                new DNS\Event\Timeout ($dnsQuery)
              )->then (
                fn () => throw new DNS\Exception\Timeout ()
              )
            )
          ])->finally (
            function () use ($dnsSocket, $dnsStream, $dnsQuery) {
              // Retrieve the ID of that message
              $queryID = $dnsQuery->getID ();

              // Remove the active query
              unset ($this->dnsQueries [$queryID]);

              // Close the stream
              $dnsStream->removeHooks ();
              $dnsStream->close ();

              // Close the socket
              $dnsSocket->removeHooks ();
              $dnsSocket->unpipe ($dnsStream);
              $dnsSocket->close ();
            }
          );
        }
      );
    }
    // }}}

    // {{{ isActive
    /**
     * Check if there are active queues at the moment
     *
     * @access public
     * @return bool
     **/
    public function isActive (): bool
    {
      return (count ($this->dnsQueries) > 0);
    }
    // }}}

    // {{{ dnsConvertPHP
    /**
     * Create an array compatible to php's dns_get_records from a given response
     *
     * @param Stream\DNS\Message $dnsResponse
     * @param array|null &$dnsAuthorities (optional)
     * @param array|null &$dnsAdditional (optional)
     *
     * @access public
     * @return array
     *
     * @throws InvalidArgumentException
     **/
    public function dnsConvertPHP (Stream\DNS\Message $dnsResponse, array &$dnsAuthorities = null, array &$dnsAdditional = null): array
    {
      // Make sure this is a response
      if ($dnsResponse->isQuestion ())
        throw new InvalidArgumentException ('DNS-Response is actually a question');

      // Convert Authority- and Additional-Sections first
      $dnsAuthorities = [];
      $dnsAdditional = [];

      foreach ($dnsResponse->getAuthorities () as $Record)
        try {
          if ($arr = $this->dnsConvertPHPRecord ($Record))
            $dnsAuthorities [] = $arr;
        } catch (InvalidArgumentException) {
          // No-Op
        }

      foreach ($dnsResponse->getAdditionals () as $Record)
        try {
          if ($arr = $this->dnsConvertPHPRecord ($Record))
            $dnsAdditional [] = $arr;
        } catch (InvalidArgumentException) {
          // No-Op
        }

      // Convert answers
      $dnsAnswer = [];

      foreach ($dnsResponse->getAnswers () as $Record)
        if ($arr = $this->dnsConvertPHPRecord ($Record))
          $dnsAnswer [] = $arr;

      return $dnsAnswer;
    }
    // }}}

    // {{{ dnsConvertPHPRecord
    /**
     * Create an array from a given DNS-Record
     *
     * @param Stream\DNS\Record $dnsRecord
     *
     * @access private
     * @return array
     *
     * @throws InvalidArgumentException
     **/
    private function dnsConvertPHPRecord (Stream\DNS\Record $dnsRecord): array
    {
      // Only handle IN-Records
      if ($dnsRecord->getClass () != Stream\DNS\Message::CLASS_INTERNET)
        throw new InvalidArgumentException ('Record must have class Internet');

      // Create preset
      $phpRecord = [
        'host' => (string)$dnsRecord->getLabel (),
        'class' => 'IN',
        'type' => substr (get_class ($dnsRecord), strrpos (get_class ($dnsRecord), '\\') + 1),
        'ttl' => $dnsRecord->getTTL (),
      ];

      // Add data depending on type
      if ($dnsRecord instanceof Stream\DNS\Record\A) {
        $phpRecord ['ip'] = $dnsRecord->getAddress ();
      } elseif ($dnsRecord instanceof Stream\DNS\Record\AAAA) {
        $phpRecord ['ipv6'] = substr ($dnsRecord->getAddress (), 1, -1);
      } elseif (
        ($dnsRecord instanceof Stream\DNS\Record\CNAME) ||
        ($dnsRecord instanceof Stream\DNS\Record\NS) ||
        ($dnsRecord instanceof Stream\DNS\Record\PTR)
      ) {
        $phpRecord ['target'] = (string)$dnsRecord->getHostname ();
      } elseif ($dnsRecord instanceof Stream\DNS\Record\MX) {
        $phpRecord ['pri'] = $dnsRecord->getPriority ();
        $phpRecord ['target'] = (string)$dnsRecord->getHostname ();
        $phpRecord ['type'] = 'MX';
      } elseif ($dnsRecord instanceof Stream\DNS\Record\SRV) {
        $phpRecord ['pri'] = $dnsRecord->getPriority ();
        $phpRecord ['weight'] = $dnsRecord->getWeight ();
        $phpRecord ['port'] = $dnsRecord->getPort ();
        $phpRecord ['target'] = (string)$dnsRecord->getHostname ();
        $phpRecord ['type'] = 'SRV';
      } elseif ($dnsRecord instanceof Stream\DNS\Record\TXT) {
        $phpRecord [ 'txt' ] = $dnsRecord->getPayload ();
        $phpRecord [ 'entries' ] = $dnsRecord->getTexts ();
      } elseif ($dnsRecord instanceof Stream\DNS\Record\SOA) {
        $phpRecord ['mname'] = (string)$dnsRecord->getNameserver ();
        $phpRecord ['rname'] = (string)$dnsRecord->getMailbox ();
        $phpRecord ['serial'] = $dnsRecord->getSerial ();
        $phpRecord ['refresh'] = $dnsRecord->getRefresh ();
        $phpRecord ['retry'] = $dnsRecord->getRetry ();
        $phpRecord ['expire'] = $dnsRecord->getExpire ();
        $phpRecord ['minimum-ttl'] = $dnsRecord->getMinimum ();
      } else
       throw new InvalidArgumentException ('Unsupported DNS-Record-Type: ' . get_class ($dnsRecord));

      return $phpRecord;
    }
    // }}}
  }

  // spell-checker: ignore quarx resolv.conf
