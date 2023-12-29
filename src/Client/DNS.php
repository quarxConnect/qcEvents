<?php

  /**
   * quarxConnect Events - Asyncronous DNS Resolver
   * Copyright (C) 2014-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  use quarxConnect\Events\Stream;
  use quarxConnect\Events;
  
  /**
   * Asyncronous DNS Resolver
   * ------------------------
   * 
   * @class DNS
   * @extends Events\Hookable
   * @package quarxConnect\Events
   * @revision 02
   **/
  class DNS extends Events\Hookable {
    use Events\Feature\Based;
    
    /* DNS64-Prefix-Hack */
    public static $DNS64_Prefix = null;
    
    /* Cached DNS-Results */
    private static $dnsCache = [ ];
    
    /* Our assigned event-base */
    private $eventBase = null;
    
    /* Our registered nameservers */
    private $dnsNameservers = [ ];
    
    /* Our active queries */
    private $dnsQueries = [ ];
    
    /* Our active queries */
    private $queriesActive = [ ];
    
    /* Timeout for DNS-Queried */
    private $dnsQueryTimeout = 5;
    
    // {{{ __construct
    /**
     * Create a new HTTP-Client Pool   
     * 
     * @param Events\Base $eventBase
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Events\Base $eventBase) {
      $this->setEventBase ($eventBase);
    }
    // }}}
    
    // {{{ getNameservers
    /**
     * Retrive configured nameservers for this client
     * 
     * @access public
     * @return array
     **/
    public function getNameservers () : array {
      return $this->dnsNameservers;
    }
    // }}}
    
    // {{{ setNameserver
    /**
     * Set the nameserver we should use
     * 
     * @param string $serverIP
     * @param int $serverPort (optional)
     * @param enum $serverProto (optional)
     * 
     * @access public
     * @return void
     **/
    public function setNameserver (string $serverIP, int $serverPort = null, int $serverProto = null) : void {
      if (
        !Events\Socket::isIPv4 ($serverIP) &&
        !Events\Socket::isIPv6 ($serverIP)
      )
        throw new \Error ('Invalid DNS-Server IP-address');
      
      $this->dnsNameservers = [
        [
          'ip' => $serverIP,
          'port' => $serverPort ?? 53,
          'proto' => $serverProto ?? Events\Socket::TYPE_UDP,
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
     **/
    public function setNameservers (iterable $dnsNameservers) : void {
      $newServers = [ ];
      
      foreach ($dnsNameservers as $dnsNameserver) {
        $newServer = [
          'ip' => $dnsNameserver ['ip'] ?? 'x',
          'port' => $dnsNameserver ['port'] ?? 53,
          'proto' => $dnsNameserver ['proto'] ?? Events\Socket::TYPE_UDP,
        ];
        
        if (
          !Events\Socket::isIPv4 ($newServer ['ip']) &&
          !Events\Socket::isIPv6 ($newServer ['ip'])
        )
          throw new \Error ('Invalid DNS-Server IP-address');
        
        if (!is_int ($newServer ['port']))
          throw new \Error ('Invalid server-port');
        
        if (!is_int ($newServer ['proto']))
          throw new \Error ('Invalid server-protocol');
        
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
     **/
    public function useSystemNameserver () : void {
      // Check if the registry exists
      if (!is_file ('/etc/resolv.conf'))
        throw new \Exception ('Missing /etc/resolv.conf');
      
      // Try to load it into an array
      if (!is_array ($confLines = @file ('/etc/resolv.conf')))
        throw new \Exception ('Failed to read /etc/resolv.conf');
      
      // Extract nameservers
      $dnsNameservers = [ ];
      
      foreach ($confLines as $confLine) {
        // Check for nameserver-line
        if (substr ($confLine, 0, 11) != 'nameserver ')
          continue;
        
        // Extract IP-Address
        $serverIP = trim (substr ($confLine, 11));
        
        // Sanatize IP-Address
        if (
          !Events\Socket::isIPv4 ($serverIP) &&
          !Events\Socket::isIPv6 ($serverIP)
        )
          continue;
        
        // Push to available nameservers
        $dnsNameservers [] = [
          'ip' => trim (substr ($confLine, 11)),
          'port' => 53,
          'proto' => Events\Socket::TYPE_UDP,
        ];
      }
      
      // Check if any valid nameserver was read from configuration
      if (count ($dnsNameservers) == 0)
        throw new \Exception ('No nameservers read from /etc/resolv.conf');
      
      // Set the nameservers
      $this->dnsNameservers = $dnsNameservers;
    }
    // }}}
    
    // {{{ resolve
    /**
     * Perform DNS-Resolve
     * 
     * @param string $dnsName
     * @param enum $dnsType (optional)
     * @param enum $dnsClass (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function resolve ($dnsName, $dnsType = null, $dnsClass = null) : Events\Promise {
      // Sanatize parameters
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
        
        foreach (self::$dnsCache [$dnsKey]['answers'] as $dnsRecord)
          if ($dnsInvalid = ($dnsRecord->getTTL () < $timeDiff))
            break;
        
        if (!$dnsInvalid)
          foreach (self::$dnsCache [$dnsKey]['authorities'] as $dnsRecord)
            if ($dnsInvalid = ($dnsRecord->getTTL () < $timeDiff))
              break;
        
        if (!$dnsInvalid)
          foreach (self::$dnsCache [$dnsKey]['additionals']as $dnsRecord)
            if ($dnsInvalid = ($dnsRecord->getTTL () < $timeDiff))
              break;
        
        if ($dnsInvalid)
          unset (self::$dnsCache [$dnsKey]);
        else
          return Events\Promise::resolve (self::$dnsCache [$dnsKey]['answers'], self::$dnsCache [$dnsKey]['authorities'], self::$dnsCache [$dnsKey]['additionals'], self::$dnsCache [$dnsKey]['response']);
      }
      
      // Create a DNS-Query
      $dnsMessage = new Stream\DNS\Message ();
      $dnsMessage->isQuestion (true);
      
      $dnsMessage->addQuestion (new Stream\DNS\Question ($dnsName, $dnsType, $dnsClass));
      
      // Enqueue the query
      return $this->enqueueQuery ($dnsMessage)->then (
        function (Stream\DNS\Recordset $dnsAnswers, Stream\DNS\Recordset $dnsAuthorities, Stream\DNS\Recordset $dnsAdditionnals, Stream\DNS\Message $dnsResponse) use ($dnsKey) {
          self::$dnsCache [$dnsKey] = [
            'timestamp' => time (),
            'answers' => $dnsAnswers,
            'authorities' => $dnsAuthorities,
            'additionals' => $dnsAdditionnals,
            'response' => $dnsResponse,
          ];
          
          // Just pass the result
          return new Events\Promise\Solution (func_get_args ());
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
     * @return Events\Promise
     **/
    public function enqueueQuery (Stream\DNS\Message $dnsQuery) : Events\Promise {
      try {
        // Make sure we have nameservers registered
        if (count ($this->dnsNameservers) == 0)
          $this->useSystemNameserver ();
        
        // Make sure the message is a question
        if (!$dnsQuery->isQuestion ())
          throw new \Error ('Message must be a question');
      } catch (\Throwable $error) {
        return Events\Promise::reject ($error, $this->getEventBase ());
      }
      
      // Prepare everything
      $dnsPromise = new Events\Promise\Deferred ($this->getEventBase ());
      $dnsTimer = $this->getEventBase ()->addTimeout (2, true);
      $nameserverIndex = 0;
      
      $startQuery = function (int $nextIndex) use ($dnsPromise, $dnsQuery, $dnsTimer, &$nameserverIndex) {
        $this->askNameserver ($this->dnsNameservers [$nextIndex], clone $dnsQuery)->then (
          function () use ($dnsPromise, $dnsTimer) {
            // Cancel the timer
            $dnsTimer->cancel ();
            
            // Forward the result
            call_user_func_array ([ $dnsPromise, 'resolve' ], func_get_args ());
          },
          function () use ($dnsPromise, $dnsTimer, $nextIndex, &$nameserverIndex) {
            // Check if there are other nameservers remaining
            if ($nextIndex < count ($this->dnsNameservers) - 1) {
              // Check wheter to query next nameserver
              if ($nextIndex == $nameserverIndex)
                $dnsTimer->run ();
              
              return;
            }
            
            // Forward the rejection
            call_user_func_array ([ $dnsPromise, 'reject' ], func_get_args ());
          }
        );
      };
      
      $dnsTimer->then (
        function () use (&$nameserverIndex, $startQuery, $dnsTimer) {
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
     * @return Events\Promise
     **/
    private function askNameserver (array $dnsNameserver, Stream\DNS\Message $dnsQuery) : Events\Promise {
      // Create a socket and a stream for this query
      $dnsSocket = new Events\Socket ($this->getEventBase ());
      $dnsSocket->useInternalResolver (false);
    
      return $dnsSocket->connect (
        $dnsNameserver ['ip'],
        $dnsNameserver ['port'],
        $dnsNameserver ['proto']
      )->then (
        function () use ($dnsSocket, $dnsQuery) {
          // Create a DNS-Stream
          $dnsStream = new Stream\DNS ();
          $dnsSocket->pipe ($dnsStream);
          
          // Pick a free message-id
          if (
            !($queryID = $dnsQuery->getID ()) ||
            isset ($this->dnsQueries [$queryID]) ||
            isset ($this->queriesActive [$queryID])
          )
            while ($queryID = $dnsQuery->setRandomID ())
              if (!isset ($this->dnsQueries [$queryID]) && !isset ($this->queriesActive [$queryID]))
                break;
          
          // Enqueue the query
          $this->dnsQueries [$queryID] = $dnsQuery;
          
          // Write out the message
          $dnsStream->dnsStreamSendMessage ($dnsQuery);
          
          return Events\Promise::race ([
            $dnsStream->once (
              'dnsResponseReceived'
            )->then (
              function (Stream\DNS\Message $dnsResponse)
              use ($dnsQuery) {
                // Check if an error was received
                if (($errorCode = $dnsResponse->getError ()) != $dnsResponse::ERROR_NONE)
                  throw new \exception ('Error-Code recevied: ' . $errorCode); # , $dnsResponse);
                
                // Post-process answers
                $Answers = $dnsResponse->getAnswers ();
                
                if ($this::$DNS64_Prefix !== null)
                  foreach ($Answers as $Answer)
                    if ($Answer instanceof Stream\DNS\Record\A) {
                      $Answers [] = $AAAA = new Stream\DNS\Record\AAAA ($Answer->getLabel (), $Answer->getTTL (), null, $Answer->getClass ());
                      $Addr = dechex (ip2long ($Answer->getAddress ()));
                      $AAAA->setAddress ('[' . $this::$DNS64_Prefix . (strlen ($Addr) > 4 ? substr ($Addr, 0, -4) . ':' : '') . substr ($Addr, -4, 4) . ']');
                    }
                
                // Fire callbacks
                $Hostname = $dnsQuery->getQuestions ();
                
                if (count ($Hostname) > 0) {
                  $Hostname = array_shift ($Hostname);
                  $Hostname->getLabel ();
                } else
                  $Hostname = null;
                
                $this->___callback ('dnsResult', $Hostname, $Answers, $dnsResponse->getAuthorities (), $dnsResponse->getAdditionals (), $dnsResponse);
                
                return new Events\Promise\Solution ([ $Answers, $dnsResponse->getAuthorities (), $dnsResponse->getAdditionals (), $dnsResponse ]);
              }
            ),
            $dnsStream->once (
              'dnsQuestionTimeout'
            )->then (
              function () {
                // Forward the error
                throw new \exception ('Query timed out');
              }
            )
          ])->catch (
            function (\Throwable $error) use ($dnsQuery) {
              // Fire callbacks
              $Hostname = $dnsQuery->getQuestions ();
              
              if (count ($Hostname) > 0) {
                $Hostname = array_shift ($Hostname);
                $Hostname->getLabel ();
              } else
                $Hostname = null;
              
              $this->___callback ('dnsResult', $Hostname, null, null, null);
              
              // Just pass the message
              throw new Events\Promise\Solution (func_get_args ());
            }
          )->finally (
            function () use ($dnsSocket, $dnsStream, $dnsQuery) {
              // Retrive the ID of that message
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
    public function isActive () {
      return (count ($this->dnsQueries) > 0);
    }
    // }}}
    
    // {{{ dnsConvertPHP
    /**
     * Create an array compatible to php's dns_get_records from a given response
     * 
     * @param Stream\DNS\Message $Response
     * @param array &&authns (optional)
     * @param array &$addtl (optional)
     * 
     * @access public
     * @return array
     **/
    public function dnsConvertPHP (Stream\DNS\Message $Response, &$authns = null, &$addtl = null) : array {
      // Make sure this is a response
      if ($Response->isQuestion ())
        throw new \exception ('DNS-Response is actually a question');
      
      // Convert authns and addtl first
      $authns = [ ];
      $addtl = [ ];
      
      foreach ($Response->getAuthorities () as $Record)
        if ($arr = $this->dnsConvertPHPRecord ($Record))
          $authns [] = $arr;
      
      foreach ($Response->getAdditionals () as $Record)
        if ($arr = $this->dnsConvertPHPRecord ($Record))
          $addtl [] = $arr;
      
      // Convert answers
      $Result = [ ];
      
      foreach ($Response->getAnswers () as $Record) {
        if (!($arr = $this->dnsConvertPHPRecord ($Record)))
          continue;
        
        $Result [] = $arr;
      }
      
      return $Result;
    }
    // }}}
    
    // {{{ dnsConvertPHPRecord
    /**
     * Create an array from a given DNS-Record
     * 
     * @param Stream\DNS\Record $Record
     * 
     * @access private
     * @return array
     **/
    private function dnsConvertPHPRecord (Stream\DNS\Record $Record) : ?array {
      // Only handle IN-Records
      if ($Record->getClass () != Stream\DNS\Message::CLASS_INTERNET)
        return null;
      
      static $Types = [
        Stream\DNS\Message::TYPE_A => 'A',
        Stream\DNS\Message::TYPE_MX => 'MX',
        Stream\DNS\Message::TYPE_CNAME => 'CNAME',
        Stream\DNS\Message::TYPE_NS => 'NS',
        Stream\DNS\Message::TYPE_PTR => 'PTR',
        Stream\DNS\Message::TYPE_TXT => 'TXT',
        Stream\DNS\Message::TYPE_AAAA => 'AAAA',
        Stream\DNS\Message::TYPE_SRV => 'SRV',
        # Skipped: SOA, HINFO, NAPTR and A6
      ];
      
      // Create preset
      $Type = $Record->getType ();
      
      if (!isset ($Types [$Type]))
        return null;
      
      $Result = [
        'host' => $Record->getLabel (),
        'class' => 'IN',
        'type' => $Types [$Type],
        'ttl' => $Record->getTTL (),
      ];
      
      // Add data depending on type
      switch ($Type) {
        case Stream\DNS\Message::TYPE_A:
          $Result ['ip'] = ($Record instanceof Stream\DNS\Record\A ? $Record->getAddress () : null);
          
          break;
        case Stream\DNS\Message::TYPE_AAAA:
          $Result ['ipv6'] = ($Record instanceof Stream\DNS\Record\AAAA ? substr ($Record->getAddress (), 1, -1) : null);
          
          break;
        case Stream\DNS\Message::TYPE_NS:
        case Stream\DNS\Message::TYPE_CNAME:
        case Stream\DNS\Message::TYPE_PTR:
          $Result ['target'] = $Record->getHostname ();
          
          break;
        case Stream\DNS\Message::TYPE_MX:
          $Result ['pri'] = $Record->getPriority ();
          $Result ['target'] = $Record->getHostname ();
          
          break;
        case Stream\DNS\Message::TYPE_SRV:
          $Result ['pri'] = $Record->getPriority ();
          $Result ['weight'] = $Record->getWeight ();
          $Result ['port'] = $Record->getPort ();
          $Result ['target'] = $Record->getHostname ();
          
          break;
        case Stream\DNS\Message::TYPE_TXT:
          $Result ['txt'] = $Record->getPayload ();
          $Result ['entries'] = explode ("\n", $Result ['txt']);
          
          break;
        default:
          return null;
      }
      
      return $Result;
    }
    // }}}
    
    // {{{ dnsResult
    /**
     * Callback: A queued hostname was resolved
     * 
     * @param string $askedHostname
     * @param Stream\DNS\Recordset $Answers (optional)
     * @param Stream\DNS\Recordset $Authorities (optional)
     * @param Stream\DNS\Recordset $Additional (optional)
     * @param Stream\DNS\Message $wholeMessage (optional)
     * 
     * @access protected
     * @return void
     **/
    protected function dnsResult ($askedHostname, Stream\DNS\Recordset $Answers = null, Stream\DNS\Recordset $Authorities = null, Stream\DNS\Recordset $Additionals = null, Stream\DNS\Message $wholeMessage = null) { }
    // }}}
  }
