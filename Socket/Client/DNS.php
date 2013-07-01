<?PHP

  /**
   * qcEvents - Asyncronous DNS Resolver
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
  
  require_once ('qcEvents/Socket/Stream/DNS.php');
  require_once ('qcEvents/Socket/Stream/DNS/Message.php');
  
  /**
   * Asyncronous DNS Resolver
   * ------------------------
   * 
   * @class qcEvents_Socket_Client_DNS
   * @extends qcEvents_Socket_Stream_DNS
   * @package qcEvents
   * @revision 01
   **/
  class qcEvents_Socket_Client_DNS extends qcEvents_Socket_Stream_DNS {
    /* Our registered nameservers */
    private $Nameservers = array ();
    
    /* Our queued queries */
    private $queriesQueued = array ();
    
    /* Our active queries */
    private $queriesActive = array ();
    
    // {{{ __construct   
    /**
     * Create a new DNS-Client
     * 
     * @access friendly
     * @return void
     **/
    function __construct () {
      // Inherit to our parent
      call_user_func_array ('parent::__construct', func_get_args ());
        
      // Register hooks
      $this->addHook ('socketConnected', array ($this, 'dnsClientConnected'));
      $this->addHook ('socketConnectionFailed', array ($this, 'dnsClientConnectFailed'));
      $this->addHook ('dnsResponseReceived', array ($this, 'dnsClientResult'));
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
        $Proto = self::TYPE_UDP;
      
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
          $Nameservers [] = array (trim (substr ($Line, 11)), 53, self::TYPE_UDP);
      
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
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function resolve ($Hostname, $Type = null, $Class = null, $Callback = null, $Private = null) {
      // Create a DNS-Query
      $Message = new qcEvents_Socket_Stream_DNS_Message;
      $Message->isQuery (true);
      
      while ($ID = $Message->setRandomID ())
        if (!isset ($this->queriesQueued [$ID]) && !isset ($this->queriesActive [$ID]))
          break;
      
      if ($Type === null)
        $Type = qcEvents_Socket_Stream_DNS_Message::TYPE_A;
      
      if ($Class === null)
        $Class = qcEvents_Socket_Stream_DNS_Message::CLASS_INTERNET;
      
      $Message->addQuestion (new qcEvents_Socket_Stream_DNS_Question ($Hostname, $Type, $Class));
      
      // Enqueue the query
      $this->queriesQueued [$ID] = array ($Message, $Hostname, $Callback, $Private);
      
      // Check wheter to connect
      if ($this->isDisconnected ()) {
        // Make sure we have hosts available
        if ((count ($this->Nameservers) == 0) && !$this->useSystemNameserver ())
          return false;
        
        // Issue the connect-request
        $this->useInternalResolver (false);
        $this->connect ($this->Nameservers [0][0], $this->Nameservers [0][1], $this->Nameservers [0][2]);
        $this->bind ();
      
      // Check if connection is already established
      } elseif ($this->isConnected ())
        $this->dnsClientConnected ();
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
      return ((count ($this->queriesQueued) > 0) || (count ($this->queriesActive) > 0));
    }
    // }}}
    
    // {{{ dnsClientConnected
    /**
     * Internal Callback: Our DNS-Client is connected to server, write out the queue
     * 
     * @access protected
     * @return void
     **/
    protected final function dnsClientConnected () {
      foreach ($this->queriesQueued as $ID=>$QueryInfo) {
        // Write out the Query
        if (!$this->dnsSendMessage ($QueryInfo [0]))
          continue;
        
        // Move the query to active-queue
        $this->queriesActive [$ID] = $QueryInfo;
        unset ($this->queriesQueued [$ID]);
      }
    }
    // }}}
    
    // {{{ dnsClientConnectFailed
    /**
     * Internal Callback: Connection finally failed - mark all queries as failed
     * 
     * @access protected
     * @return void
     **/
    protected final function dnsClientConnectFailed () {
      // Move everything to active
      foreach ($this->queriesQueued as $ID=>$Query)
        $this->queriesActive [$ID] = $Query;
      
      $this->queriesQueued = array ();
      
      // Fire results
      $queriesActive = $this->queriesActive;
      $this->queriesActive = array ();
      
      foreach ($queriesActive as $ID=>$Query) {
        if (($Query [2] !== null) && is_callable ($Query [2]))
          call_user_func ($Query [2], $this, $Query [1], null, null, null, $Query [3], null);
        
        $this->___callback ('dnsResult', $Query [1], null, null, null, null);
      }
    }
    // }}}
    
    // {{{ dnsClientResult
    /**
     * Internal Callback: DNS-Response was received
     * 
     * @param qcEvents_Socket_Stream_DNS_Message $Message
     * 
     * @access protected
     * @return void
     **/
    protected final function dnsClientResult ($Message) {
      // Retrive the ID of this one
      $ID = $Message->getID ();
      
      // Check if we have a query for this
      if (!isset ($this->queriesActive [$ID]))
        return;
      
      $Query = $this->queriesActive [$ID];
      unset ($this->queriesActive [$ID]);
      
      if (count ($this->queriesActive) == 0)
        $this->disconnect ();
      
      // Fire callbacks
      if (($Query [2] !== null) && is_callable ($Query [2]))
        call_user_func ($Query [2], $this, $Query [1], $Message->getAnswers (), $Message->getAuthorities (), $Message->getAdditionals (), $Query [3], $Message);
      
      $this->___callback ('dnsResult', $Query [1], $Message->getAnswers (), $Message->getAuthorities (), $Message->getAdditionals (), $Message);
    }
    // }}}
    
    // {{{ dnsConvertPHP
    /**
     * Create an array compatible to php's dns_get_records from a given response
     * 
     * @param qcEvents_Socket_Stream_DNS_Message $Response
     * 
     * @access public
     * @return array
     **/
    public function dnsConvertPHP (qcEvents_Socket_Stream_DNS_Message $Response, &$authns = null, &$addtl = null, &$raw = false) {
      // Make sure this is a response
      if ($Response->isQuery ())
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
     * @param qcEvents_Socket_Stream_DNS_Record $Record
     * 
     * @access private
     * @return array
     **/
    private function dnsConvertPHPRecord (qcEvents_Socket_Stream_DNS_Record $Record) {
      // Only handle IN-Records
      if ($Record->getClass () != qcEvents_Socket_Stream_DNS_Message::CLASS_INTERNET)
        return false;
      
      static $Types = array (
        qcEvents_Socket_Stream_DNS_Message::TYPE_A => 'A',
        qcEvents_Socket_Stream_DNS_Message::TYPE_MX => 'MX',
        qcEvents_Socket_Stream_DNS_Message::TYPE_CNAME => 'CNAME',
        qcEvents_Socket_Stream_DNS_Message::TYPE_NS => 'NS',
        qcEvents_Socket_Stream_DNS_Message::TYPE_PTR => 'PTR',
        qcEvents_Socket_Stream_DNS_Message::TYPE_TXT => 'TXT',
        qcEvents_Socket_Stream_DNS_Message::TYPE_AAAA => 'AAAA',
        qcEvents_Socket_Stream_DNS_Message::TYPE_SRV => 'SRV',
        # Skipped: SOA, HINFO, NAPTR and A6
      );
      
      // Create preset
      $Type = $Record->getType ();
      
      $Result = array (
        'host' => $Record->getLabel (),
        'class' => 'IN',
        'type' => $Types [$Type],
        'ttl' => $Record->getTTL (),
      );
      
      // Add data depending on type
      switch ($Type) {
        case qcEvents_Socket_Stream_DNS_Message::TYPE_A:
          $Result ['ip'] = $Record->getAddress ();
          
          break;
        case qcEvents_Socket_Stream_DNS_Message::TYPE_AAAA:
          $Result ['ipv6'] = substr ($Record->getAddress (), 1, -1);
          
          break;
        case qcEvents_Socket_Stream_DNS_Message::TYPE_NS:
        case qcEvents_Socket_Stream_DNS_Message::TYPE_CNAME:
        case qcEvents_Socket_Stream_DNS_Message::TYPE_PTR:
          $Result ['target'] = $Record->getHostname ();
          
          break;
        case qcEvents_Socket_Stream_DNS_Message::TYPE_MX:
          $Result ['pri'] = $Record->getPriority ();
          $Result ['target'] = $Record->getHostname ();
          
          break;
        case qcEvents_Socket_Stream_DNS_Message::TYPE_SRV:
          $Result ['pri'] = $Record->getPriority ();
          $Result ['weight'] = $Record->getWeight ();
          $Result ['port'] = $Record->getPort ();
          $Result ['target'] = $Record->getHostname ();
          
          break;
        case qcEvents_Socket_Stream_DNS_Message::TYPE_TXT:
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
     * @param qcEvents_Socket_Stream_DNS_Message $wholeMessage
     * 
     * @access protected
     * @return void
     **/
    protected function dnsResult ($askedHostname, $Answers, $Authorities, $Additionals, qcEvents_Socket_Stream_DNS_Message $wholeMessage) { }
    // }}}
  }

?>