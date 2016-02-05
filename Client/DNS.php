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
    
    /* Our registered nameservers */
    private $Nameservers = array ();
    
    /* Our active queries */
    private $Queries = array ();
    
    /* Our sockets */
    private $Sockets = array ();
    
    /* Our DNS-Streams */
    private $Streams = array ();
    
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
    
    public function getEventBase () {
      return $this->eventBase;
    }
    
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
    public function resolve ($Hostname, $Type = null, $Class = null, callable $Callback = null, $Private = null) {
      // Create a DNS-Query
      $Message = new qcEvents_Stream_DNS_Message;
      $Message->isQuestion (true);
      
      if ($Type === null)
        $Type = qcEvents_Stream_DNS_Message::TYPE_A;
      
      if ($Class === null)
        $Class = qcEvents_Stream_DNS_Message::CLASS_INTERNET;
      
      $Message->addQuestion (new qcEvents_Stream_DNS_Question ($Hostname, $Type, $Class));
      
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
     * The callback will be raised in the form of
     * 
     *   function (string $Hostname, array $Answers, array $Authorities, array $Additionals, qcEvents_Stream_DNS_Message $Response, mixed $Private) { }
     * 
     * @access public
     * @return bool
     **/
    public function enqueueQuery (qcEvents_Stream_DNS_Message $Message, callable $Callback = null, $Private = null) {
      // Make sure we have nameservers registered
      if ((count ($this->Nameservers) == 0) && !$this->useSystemNameserver ())
        return false;
      
      // Make sure the message is a question
      if (!$Message->isQuestion ())
        return false;
      
      // Create a socket and a stream for this query
      $Socket = new qcEvents_Socket ($this->eventBase);
      $Socket->useInternalResolver (false);
      
      $Socket->connect ($this->Nameservers [0][0], $this->Nameservers [0][1], $this->Nameservers [0][2]);
      
      $Stream = new qcEvents_Stream_DNS;
      $Socket->pipe ($Stream);
      
      // Pick a free message-id
      if (!($ID = $Message->getID ()) || isset ($this->Queries [$ID]) || isset ($this->queriesActive [$ID]))
        while ($ID = $Message->setRandomID ())
          if (!isset ($this->Queries [$ID]) && !isset ($this->queriesActive [$ID]))
            break;
      
      // Enqueue the query
      $this->Queries [$ID] = array ($Message, $Callback, $Private);
      $this->Sockets [$ID] = $Socket;
      $this->Streams [$ID] = $Stream;
      
      # TODO: This is merely a hack
      $Stream->addHook ('dnsQuestionTimeout', function (qcEvents_Stream_DNS $S, qcEvents_Stream_DNS_Message $M) use ($Socket, $Stream, $Message) {
        if (($M !== $Message) || ($S !== $Stream))
          return;
        
        $this->dnsClientConnectFailed ($Socket, $Message);
      });
      
      // Register callbacks
      $Stream->addHook ('dnsResponseReceived', array ($this, 'dnsClientResult'), $Message);
      
      if (!$Socket->isConnected ()) {
        $Socket->addHook ('socketConnected', array ($this, 'dnsClientConnected'), $Message);
        $Socket->addHook ('socketConnectionFailed', array ($this, 'dnsClientConnectFailed'), $Message);
        $Socket->addHook ('socketDisconnected', array ($this, 'dnsClientConnectFailed'), $Message);
      } else
        $this->dnsClientConnected ($Socket, $Message);
      
      return true;
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
    
    // {{{ dnsClientConnected
    /**
     * Internal Callback: Our DNS-Client is connected to server, write out the queue
     * 
     * @access public
     * @return void
     **/
    public final function dnsClientConnected (qcEvents_Socket $Socket, qcEvents_Stream_DNS_Message $Message) {
      // Retrive the ID of that message
      $ID = $Message->getID ();
      
      // Make sure the call is authentic
      if (!isset ($this->Queries [$ID]) || !($this->Queries [$ID][0] === $Message))
        return;
      
      // Write out the message
      $this->Streams [$ID]->dnsStreamSendMessage ($Message);
      
      // Remove the hooks again
      $Socket->removeHook ('socketConnected', array ($this, 'dnsClientConnected'), $Message);
      $Socket->removeHook ('socketConnectionFailed', array ($this, 'dnsClientConnectFailed'), $Message);
      $Socket->removeHook ('socketDisconnected', array ($this, 'dnsClientConnectFailed'), $Message);
    }
    // }}}
    
    // {{{ dnsClientConnectFailed
    /**
     * Internal Callback: Connection finally failed - mark all queries as failed
     * 
     * @access public
     * @return void
     **/
    public final function dnsClientConnectFailed (qcEvents_Socket $Socket, $P1, $P2 = null) {
      // Peek the message from parameters
      if ($P2 instanceof qcEvents_Stream_DNS_Message)
        $Message = $P2;
      elseif ($P1 instanceof qcEvents_Stream_DNS_Message)
        $Message = $P1;
      else
        return;
      
      // Retrive the ID of that message
      $ID = $Message->getID ();
      
      // Make sure the call is authentic
      if (!isset ($this->Queries [$ID]) || !($this->Queries [$ID][0] === $Message))
        return;
      
      $Query = $this->Queries [$ID];
      
      unset ($this->Queries [$ID], $this->Streams [$ID], $this->Sockets [$ID]);
      
      // Make sure the socket is closed after error
      $Socket->close (null, null, true);
      
      // Fire callbacks
      $Hostname = $Query [0]->getQuestions ();
      
      if (count ($Hostname) > 0) {
        $Hostname = array_shift ($Hostname);
        $Hostname->getLabel ();
      } else
        $Hostname = null;
      
      if ($Query [1])
        $this->___raiseCallback ($Query [1], $Hostname, null, null, null, null, $Query [2]);
      
      $this->___callback ('dnsResult', $Hostname, null, null, null);
    }
    // }}}
    
    // {{{ dnsClientResult
    /**
     * Internal Callback: DNS-Response was received
     * 
     * @param qcEvents_Stream_DNS $Stream
     * @param qcEvents_Stream_DNS_Message $Response
     * @param qcEvents_Stream_DNS_Message $Question
     * 
     * @access public
     * @return void
     **/
    public final function dnsClientResult (qcEvents_Stream_DNS $Stream, qcEvents_Stream_DNS_Message $Response, qcEvents_Stream_DNS_Message $Question) {
      // Retrive the ID of that message
      $ID = $Question->getID ();
    
      // Make sure the call is authentic
      if (!isset ($this->Queries [$ID]) || !($this->Queries [$ID][0] === $Question))
        return;
      
      // Peek objects before destroying
      $Query = $this->Queries [$ID];
      $Socket = $this->Sockets [$ID]; 
      
      unset ($this->Queries [$ID], $this->Streams [$ID], $this->Sockets [$ID]);
      
      $Socket->close ();
      
      // Fire callbacks
      $Hostname = $Query [0]->getQuestions ();
      
      if (count ($Hostname) > 0) {
        $Hostname = array_shift ($Hostname);
        $Hostname->getLabel ();
      } else
        $Hostname = null;
      
      if ($Query [1])
        $this->___raiseCallback ($Query [1], $Hostname, $Response->getAnswers (), $Response->getAuthorities (), $Response->getAdditionals (), $Response, $Query [2]);
      
      $this->___callback ('dnsResult', $Hostname, $Response->getAnswers (), $Response->getAuthorities (), $Response->getAdditionals (), $Response);
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
     * @param array $Answers
     * @param array $Authorities
     * @param array $Additional
     * @param qcEvents_Stream_DNS_Message $wholeMessage (optional)
     * 
     * @access protected
     * @return void
     **/
    protected function dnsResult ($askedHostname, $Answers, $Authorities, $Additionals, qcEvents_Stream_DNS_Message $wholeMessage = null) { }
    // }}}
  }

?>