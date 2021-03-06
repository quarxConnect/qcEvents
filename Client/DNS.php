<?PHP

  /**
   * qcEvents - Asyncronous DNS Resolver
   * Copyright (C) 2014 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Interface/Timer.php');
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Socket.php');
  require_once ('qcEvents/Stream/DNS.php');
  require_once ('qcEvents/Trait/Timer.php');
  require_once ('qcEvents/Promise.php');
  
  /**
   * Asyncronous DNS Resolver
   * ------------------------
   * 
   * @class qcEvents_Client_DNS
   * @extends qcEvents_Stream_DNS
   * @package qcEvents
   * @revision 02
   **/
  class qcEvents_Client_DNS extends qcEvents_Hookable implements qcEvents_Interface_Timer {
    use qcEvents_Trait_Timer;
    
    /* DNS64-Prefix-Hack */
    public static $DNS64_Prefix = null;
    
    /* Our registered nameservers */
    private $Nameservers = array ();
    
    /* Our active queries */
    private $Queries = array ();
    
    /* Our active queries */
    private $queriesActive = array ();
    
    /* Timeout for DNS-Queried */
    private $dnsQueryTimeout = 5;
    
    // {{{ __construct
    /**
     * Create a new HTTP-Client Pool   
     * 
     * @param qcEvents_Base $eventBase
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $eventBase) {
      $this->eventBase = $eventBase;
    }
    // }}}
    
    // {{{ getEventBase
    /**
     * Retive the assigned event-base of this client
     * 
     * @access public
     * @return qcEvents_Base
     **/
    public function getEventBase () {
      return $this->eventBase;
    }
    // }}}
    
    // {{{ setNameserver
    /**
     * Set the nameserver we should use
     * 
     * @param string $IP
     * @param int $Port (optional)
     * @param enum $Proto (optional)
     * 
     * @access public
     * @return void
     **/
    public function setNameserver ($IP, $Port = null, $Proto = null) {
      if ($Port === null)
        $Port = 53;
      
      if ($Proto === null)
        $Proto = qcEvents_Socket::TYPE_UDP;
      
      $this->Nameservers = array (array ($IP, $Port, $Proto));
    }
    // }}}
    
    // {{{ useSystemNameserver
    /**
     * Load nameservers from /etc/resolv.conf
     * 
     * @access public
     * @return bool
     **/
    public function useSystemNameserver () {
      // Check if the registry exists
      if (!is_file ('/etc/resolv.conf'))
        return false;
      
      // Try to load it into an array
      if (!is_array ($Lines = @file ('/etc/resolv.conf')))
        return false;
      
      // Extract nameservers
      $Nameservers = array ();
      
      foreach ($Lines as $Line)
        if (substr ($Line, 0, 11) == 'nameserver ')
          $Nameservers [] = array (trim (substr ($Line, 11)), 53, qcEvents_Socket::TYPE_UDP);
      
      if (count ($Nameservers) == 0)
        return false;
      
      // Set the nameservers
      $this->Nameservers = $Nameservers;
      
      return true;
    }
    // }}}
    
    // {{{ resolve
    /**
     * Perform DNS-Resolve
     * 
     * @param string $Hostname
     * @param enum $Type (optional)
     * @param enum $Class (optional)
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @remark The callback is specified in enqueueQuery()
     * 
     * @access public
     * @return void
     **/
    public function resolve ($Hostname, $Type = null, $Class = null, callable $Callback = null, $Private = null) : qcEvents_Promise {
      // Create a DNS-Query
      $Message = new qcEvents_Stream_DNS_Message;
      $Message->isQuestion (true);
      
      if ($Type === null)
        $Type = qcEvents_Stream_DNS_Message::TYPE_A;
      
      if ($Class === null)
        $Class = qcEvents_Stream_DNS_Message::CLASS_INTERNET;
      
      $Message->addQuestion (new qcEvents_Stream_DNS_Question ($Hostname, $Type, $Class));
      
      // Enqueue the query
      return $this->enqueueQuery ($Message, $Callback, $Private);
    }
    // }}}
    
    // {{{ enqueueQuery
    /**
     * Enqueue a prepared dns-message for submission
     * 
     * @param qcEvents_Stream_DNS_Message $Message
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function enqueueQuery (qcEvents_Stream_DNS_Message $Message, callable $Callback = null, $Private = null) : qcEvents_Promise {
      // Make sure we have nameservers registered
      if ((count ($this->Nameservers) == 0) && !$this->useSystemNameserver ())
        $Promise = qcEvents_Promise::reject ('No nameservers known');
      
      // Make sure the message is a question
      elseif (!$Message->isQuestion ())
        $Promise = qcEvents_Promise::reject ('Message must be a question');
      
      // Create a socket and a stream for this query
      else {
        $Socket = new qcEvents_Socket ($this->eventBase);
        $Socket->useInternalResolver (false);
        
        $Promise = $Socket->connect (
          $this->Nameservers [0][0],
          $this->Nameservers [0][1],
          $this->Nameservers [0][2]
        )->then (
          function () use ($Socket, $Message) {
            // Create a DNS-Stream
            $Stream = new qcEvents_Stream_DNS;
            $Socket->pipe ($Stream);
            
            // Pick a free message-id
            if (!($ID = $Message->getID ()) || isset ($this->Queries [$ID]) || isset ($this->queriesActive [$ID]))
              while ($ID = $Message->setRandomID ())
                if (!isset ($this->Queries [$ID]) && !isset ($this->queriesActive [$ID]))
                  break;
            
            // Enqueue the query
            $this->Queries [$ID] = $Message;
            
            // Write out the message
            $Stream->dnsStreamSendMessage ($Message);
            
            return new qcEvents_Promise (function ($resolve, $reject) use ($Socket, $Stream, $Message, $ID) {
              # TODO: This is merely a hack
              $Stream->addHook ('dnsQuestionTimeout', function (qcEvents_Stream_DNS $S, qcEvents_Stream_DNS_Message $M) use ($Stream, $Message, $reject) {
                if (($S !== $Stream) || ($M !== $Message))
                  return;
                
                $reject ('Query timed out');
              });
              
              // Register callbacks
              $Stream->addHook (
                'dnsResponseReceived', 
                function (qcEvents_Stream_DNS $Stream, qcEvents_Stream_DNS_Message $Response) use ($resolve, $Socket, $Message, $ID) {
                  // Make sure the call is authentic
                  if (!isset ($this->Queries [$ID]) || ($this->Queries [$ID] !== $Message))
                    return;
                  
                  // Remove the active query
                  unset ($this->Queries [$ID]);
                  
                  // Close the socket
                  $Socket->close ();
                  
                  // Post-process answers
                  $Answers = $Response->getAnswers ();
                  
                  if ($this::$DNS64_Prefix !== null) {
                    foreach ($Answers as $Answer)
                      if ($Answer instanceof qcEvents_Stream_DNS_Record_A) {
                        $Answers [] = $AAAA = new qcEvents_Stream_DNS_Record_AAAA ($Answer->getLabel (), $Answer->getTTL (), null, $Answer->getClass ());
                        $Addr = dechex (ip2long ($Answer->getAddress ()));
                        $AAAA->setAddress ('[' . $this::$DNS64_Prefix . (strlen ($Addr) > 4 ? substr ($Addr, 0, -4) . ':' : '') . substr ($Addr, -4, 4) . ']');
                      }
                  }
                  
                  // Fire callbacks
                  $Hostname = $Message->getQuestions ();
                  
                  if (count ($Hostname) > 0) {
                    $Hostname = array_shift ($Hostname);
                    $Hostname->getLabel ();
                  } else
                    $Hostname = null;
                  
                  $this->___callback ('dnsResult', $Hostname, $Answers, $Response->getAuthorities (), $Response->getAdditionals (), $Response);
                  
                  $resolve ($Answers, $Response->getAuthorities (), $Response->getAdditionals (), $Response);
                }
              );
            });
          }
        )->catch (
          function () use ($Socket, $Message) {
            // Retrive the ID of that message
            $ID = $Message->getID ();
            
            // Make sure the call is authentic
            if (!isset ($this->Queries [$ID]) || !($this->Queries [$ID] === $Message))
              return;
            
            // Remove the active query
            unset ($this->Queries [$ID]);
            
            // Make sure the socket is closed after error
            $Socket->close (null, null, true);
            
            // Fire callbacks
            $Hostname = $Message->getQuestions ();
            
            if (count ($Hostname) > 0) {
              $Hostname = array_shift ($Hostname);
              $Hostname->getLabel ();
            } else
              $Hostname = null;
            
            $this->___callback ('dnsResult', $Hostname, null, null, null);
            
            // Forward the error
            throw new qcEvents_Promise_Solution (func_get_args ());
          }
        );
      }
      
      // Patch in callback
      if ($Callback) {
        trigger_error ('Callback on qcEvents_Client_DNS::enqueueQuery() is deprecated');
        
        // Extract hostname
        $Hostname = $Message->getQuestions ();
            
        if (count ($Hostname) > 0) {
          $Hostname = array_shift ($Hostname);
          $Hostname->getLabel ();
        } else
          $Hostname = null;
        
        $Promise = $Promise->then (
          function (qcEvents_Stream_DNS_Recordset $Answers, qcEvents_Stream_DNS_Recordset $Authorities, qcEvents_Stream_DNS_Recordset $Additional, qcEvents_Stream_DNS_Message $Response) use ($Hostname, $Callback, $Private) {
            // Forward the callback
            $this->___raiseCallback ($Callback, $Hostname, $Answers, $Authorities, $Additional, $Response, $Private);
            
            // Forward the result
            return new qcEvents_Promise_Solution (func_get_args ());
          },
          function () use ($Hostname, $Callback, $Private) {
            // Forward the callback
            $this->___raiseCallback ($Callback, $Hostname, null, null, null, null, $Private);
            
            // Forward the error
            throw new qcEvents_Promise_Solution (func_get_args ());
          }
        );
      }
      
      return $Promise;
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
      return (count ($this->Queries) > 0);
    }
    // }}}
    
    // {{{ dnsConvertPHP
    /**
     * Create an array compatible to php's dns_get_records from a given response
     * 
     * @param qcEvents_Stream_DNS_Message $Response
     * 
     * @access public
     * @return array
     **/
    public function dnsConvertPHP (qcEvents_Stream_DNS_Message $Response, &$authns = null, &$addtl = null, &$raw = false) {
      // Make sure this is a response
      if ($Response->isQuestion ())
        return false;
      
      // Convert authns and addtl first
      $authns = array ();
      $addtl = array ();
      
      foreach ($Response->getAuthorities () as $Record)
        if ($arr = $this->dnsConvertPHPRecord ($Record))
          $authns [] = $arr;
      
      foreach ($Response->getAdditionals () as $Record)
        if ($arr = $this->dnsConvertPHPRecord ($Record))
          $addtl [] = $arr;
      
      // Convert answers
      $Result = array ();
      
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
     * @param qcEvents_Stream_DNS_Record $Record
     * 
     * @access private
     * @return array
     **/
    private function dnsConvertPHPRecord (qcEvents_Stream_DNS_Record $Record) {
      // Only handle IN-Records
      if ($Record->getClass () != qcEvents_Stream_DNS_Message::CLASS_INTERNET)
        return false;
      
      static $Types = array (
        qcEvents_Stream_DNS_Message::TYPE_A => 'A',
        qcEvents_Stream_DNS_Message::TYPE_MX => 'MX',
        qcEvents_Stream_DNS_Message::TYPE_CNAME => 'CNAME',
        qcEvents_Stream_DNS_Message::TYPE_NS => 'NS',
        qcEvents_Stream_DNS_Message::TYPE_PTR => 'PTR',
        qcEvents_Stream_DNS_Message::TYPE_TXT => 'TXT',
        qcEvents_Stream_DNS_Message::TYPE_AAAA => 'AAAA',
        qcEvents_Stream_DNS_Message::TYPE_SRV => 'SRV',
        # Skipped: SOA, HINFO, NAPTR and A6
      );
      
      // Create preset
      $Type = $Record->getType ();
      
      if (!isset ($Types [$Type]))
        return false;
      
      $Result = array (
        'host' => $Record->getLabel (),
        'class' => 'IN',
        'type' => $Types [$Type],
        'ttl' => $Record->getTTL (),
      );
      
      // Add data depending on type
      switch ($Type) {
        case qcEvents_Stream_DNS_Message::TYPE_A:
          $Result ['ip'] = $Record->getAddress ();
          
          break;
        case qcEvents_Stream_DNS_Message::TYPE_AAAA:
          $Result ['ipv6'] = substr ($Record->getAddress (), 1, -1);
          
          break;
        case qcEvents_Stream_DNS_Message::TYPE_NS:
        case qcEvents_Stream_DNS_Message::TYPE_CNAME:
        case qcEvents_Stream_DNS_Message::TYPE_PTR:
          $Result ['target'] = $Record->getHostname ();
          
          break;
        case qcEvents_Stream_DNS_Message::TYPE_MX:
          $Result ['pri'] = $Record->getPriority ();
          $Result ['target'] = $Record->getHostname ();
          
          break;
        case qcEvents_Stream_DNS_Message::TYPE_SRV:
          $Result ['pri'] = $Record->getPriority ();
          $Result ['weight'] = $Record->getWeight ();
          $Result ['port'] = $Record->getPort ();
          $Result ['target'] = $Record->getHostname ();
          
          break;
        case qcEvents_Stream_DNS_Message::TYPE_TXT:
          $Result ['txt'] = $Record->getPayload ();
          $Result ['entries'] = explode ("\n", $Result ['txt']);
          
          break;
        default:
          return false;
      }
      
      return $Result;
    }
    // }}}
    
    // {{{ dnsResult
    /**
     * Callback: A queued hostname was resolved
     * 
     * @param string $askedHostname
     * @param qcEvents_Stream_DNS_Recordset $Answers (optional)
     * @param qcEvents_Stream_DNS_Recordset $Authorities (optional)
     * @param qcEvents_Stream_DNS_Recordset $Additional (optional)
     * @param qcEvents_Stream_DNS_Message $wholeMessage (optional)
     * 
     * @access protected
     * @return void
     **/
    protected function dnsResult ($askedHostname, qcEvents_Stream_DNS_Recordset $Answers = null, qcEvents_Stream_DNS_Recordset $Authorities = null, qcEvents_Stream_DNS_Recordset $Additionals = null, qcEvents_Stream_DNS_Message $wholeMessage = null) { }
    // }}}
  }

?>