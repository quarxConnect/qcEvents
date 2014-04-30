<?PHP

  /**
   * qcEvents - SMTP-Relay-Server Implementation
   * Copyright (C) 2013 Bernd Holzmueller <bernd@quarxconnect.de>
   * 
   * This program is free software: you can redistribute it and/or modify
   * it under the terms of the GNU General Public License as published by
   * the Free Software Foundation, either version 3 of the License, or
   * (at your option) any later version.
   * 
   * This program is distributed in the hope that it will be useful,
   * but WITHOUT ANY WARRANTY; without even the implied warranty of
   * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
   * GNU General Public License for more details.
   * 
   * You should have received a copy of the GNU General Public License
   * along with this program.  If not, see <http://www.gnu.org/licenses/>.
   **/
  
  require_once ('qcEvents/Socket/Server/SMTP.php');
  require_once ('qcEvents/Socket/Client/DNS.php');
  require_once ('qcEvents/Socket/Client/SMTP.php');
  
  /**
   * SMTP-Relay
   * ----------
   * Generic SMTP-Relay Server that utilizes our SMTP-Server-Implementation
   * 
   * @class qcEvents_Socket_Server_SMTP_Relay
   * @extends qcEvents_Socket_Server_SMTP
   * @package qcEvents
   * @revision 02
   **/
  class qcEvents_Socket_Server_SMTP_Relay extends qcEvents_Socket_Server_SMTP {
    /* Relay all mail over this host */
    private $relaySmarthost = null;
    
    /* Current Client-Connections */
    private $smtpClients = array ();
    
    // {{{ __construct   
    /**
     * Create a new SMTP-Server
     * 
     * @access friendly
     * @return void
     **/
    function __construct () {
      // Inherit to our parent   
      call_user_func_array ('parent::__construct', func_get_args ());
      
      $this->addHook ('socketDisconnected', array ($this, 'smtpRelayDisconnected'));
    }
    // }}}
    
    // {{{ relayLockupTransport
    /**
     * Lookup the transport-destination for a given mail-adress
     * 
     * @param string $Address
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * @access private
     * @return void
     **/
    private function relayLockupTransport ($Address, callable $Callback, $Private = null) {
      // Check if to use a smart-host
      if ($this->relaySmarthost !== null)
        return call_user_func ($Callback, array (array ($this->relaySmarthost, 25)), $Private);
      
      // Check if this is a valid E-Mail-Address
      if (($p = strrpos ($Address, '@')) === false)
        return call_user_func ($Callback, false, $Private);
      
      // Extract domainname
      $Domain = substr ($Address, $p + 1);
      
      // Create a resolver
      $Resolver = new qcEvents_Socket_Client_DNS ($this->getEventBase ());
      $Resolver->resolve ($Domain, qcEvents_Stream_DNS_Message::TYPE_CNAME, null, array ($this, 'relayLockupResolve'), array ($Address, array ($Domain), 0, $Callback, $Private));
    }
    // }}}
    
    // {{{ relayLockupResolve
    /**
     * Callback: Handle Results from DNS-Resolver
     * 
     * @param qcEvents_Socket_Client_DNS $Resolver
     * @param string $Domain
     * @param array $Answers
     * @param array $Authorities
     * @param array $Additionals
     * @param array $Private
     * @param qcEvents_Stream_DNS_Message $Message (optional)
     * 
     * @access public
     * @return void
     **/
    public function relayLockupResolve (qcEvents_Socket_Client_DNS $Resolver, $Domain, $Answers, $Authorities, $Additionals, $Private, qcEvents_Stream_DNS_Message $wholeMessage = null) {
      // Check if we probed for an CNAME-Record
      if ($Private [2] == 0) {
        $Private [2] = 1;
        $Private [1] = array ();
        
        if (is_array ($Answers))
          foreach ($Answers as $Answer)
            if ($Answer->getType () == qcEvents_Stream_DNS_Message::TYPE_CNAME)
              $Private [1][] = $Record->getHostname ();
        
        if (count ($Private [1]) == 0)
          $Private [1][] = $Domain;
        
        foreach ($Private [1] as $Domain)
          $Resolver->resolve ($Domain, qcEvents_Stream_DNS_Message::TYPE_MX, null, array ($this, 'relayLockupResolve'), $Private);
        
        return;
      
      // Check if this is one of our final results
      } elseif ($Private [2] == 2) {
        if (is_array ($Answers))
          foreach ($Answers as $Answer) {
            $Type = $Answer->getType ();
            
            if (($Type == qcEvents_Stream_DNS_Message::TYPE_A) || ($Type == qcEvents_Stream_DNS_Message::TYPE_AAAA))
              $Resolver->relayResults [$Answer->getAddress ()] = array ($Answer->getAddress (), 25);
          }
        
        // Check if the resolver is ready
        if (!$Resolver->isActive ())
          return call_user_func ($Private [3], $Resolver->relayResults, $Private [4]);
      }
      
      // Check if this is an error
      if (!is_array ($Answers)) {
        // Check for an permanent error
        if (!$Resolver->isActive ())
          return call_user_func ($Private [3], false, $Private [4]);
        
        // ... or just discard this one
        return;
      }
      
      // Extract MX-Hosts
      if (!isset ($Resolver->relayHosts)) {
        $Resolver->relayHosts = array ();
        $Resolver->relayResults = array ();
      }
      
      foreach ($Answers as $Answer)
        if ($Answer->getType () == qcEvents_Stream_DNS_Message::TYPE_MX)
          $Resolver->relayHosts [] = $Answer->getHostname ();
      
      // Check if this resolving-stage was finished
      if ($Resolver->isActive ())
        return;
      
      // Resolve A-Records
      $Private [2] = 2;
      
      if (count ($Resolver->relayHosts) > 0)
        $Hosts = $Resolver->relayHosts;
      else
        $Hosts = $Private [1];
      
      foreach ($Hosts as $Host) {
        $Resolver->resolve ($Host, qcEvents_Stream_DNS_Message::TYPE_A, null, array ($this, 'relayLockupResolve'), $Private);
        $Resolver->resolve ($Host, qcEvents_Stream_DNS_Message::TYPE_AAAA, null, array ($this, 'relayLockupResolve'), $Private);
      }
    }
    // }}}
    
    // {{{ smtpAddReceiver
    /**
     * Callback: Try to add a recevier to the current mail-transaction
     * 
     * @param string $Receiver  
     * @param array $Parameters 
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * @access protected
     * @return void
     **/
    protected function smtpAddReceiver ($Receiver, $Parameters, callable $Callback, $Private = null) {
      $this->relayLockupTransport ($Receiver, array ($this, 'relayTransportResult'), array ($Receiver, $Callback, $Private));
    }
    // }}}
    
    // {{{ relayTransportResult
    /**
     * Destinations for E-Mail-Transport were received
     * 
     * @param array $Results
     * @param array $Private
     * 
     * @access public
     * @return void
     **/
    public function relayTransportResult ($Results, $Private) {
      // Check for an unsuccessfull attempt
      if (!is_array ($Results) || (count ($Results) == 0))
        return call_user_func ($Private [1], false, $Private [2]);
      
      // Check if we have a client-connection for this
      $Client = null;
      
      foreach ($Results as $Result)
        if (isset ($this->smtpClients [$Result [0]]))
          $Client = $this->smtpClients [$Result [0]];
      
      if ($Client !== null)
        return $Client->addReceiver ($Private [0], null, array ($this, 'smtpReceiverAdded'), $Private);
      
      $Addrs = array ();
      
      foreach ($Results as $Result)
        $Addrs [] = $Result [0];
      
      // Create a new SMTP-Client
      $Client = new qcEvents_Socket_Client_SMTP ($this->getEventBase ());
      
      if (!$Client->connect ($Addrs, null, null, false, null, array ($this, 'smtpClientSocketConnected'), $Private))
        return call_user_func ($Private [1], false, $Private [2]);
    }
    // }}}
    
    // {{{ smtpClientSocketConnected
    /**
     * Callback: A new SMTP-Client was connected at low-level
     * 
     * @param qcEvents_Socket_Client_SMTP $Client
     * @param bool $Status
     * @param array $Private
     * 
     * @access public
     * @return void
     **/
    public function smtpClientSocketConnected (qcEvents_Socket_Client_SMTP $Client, $Status, $Private) {
      // Check if the connection was successfull
      if (!$Status)
        return call_user_func ($Private [1], false, $Private [2]);
      
      // Register a hook on that Client
      $Client->addHook ('smtpConnected', array ($this, 'smtpClientConnected'), $Private);
    }
    // }}}
    
    // {{{ smtpClientConnected
    /**
     * Callback: An SMTP-Client was connected at SMTP-Level
     * 
     * @param qcEvents_Socket_Client_SMTP $Client
     * @param array $Private
     * 
     * @access public
     * @return void
     **/
    public function smtpClientConnected (qcEvents_Socket_Client_SMTP $Client, $Private) {
      $Client->startTLS (array ($this, 'smtpClientEncrypted'), $Private);
    }
    // }}}
    
    // {{{ smtpClientEncrypted
    /**
     * Callback: TLS-Negotiation was finished
     * 
     * @param qcEvents_Socket_Client_SMTP $Client
     * @param bool $Status
     * @param array $Private
     * 
     * @access public
     * @return void
     **/
    public function smtpClientEncrypted (qcEvents_Socket_Client_SMTP $Client, $Status, $Private) {
      return $Client->startMail ($this->smtpOriginator (), null, array ($this, 'smtpClientReady'), $Private);
    }
    // }}}
    
    // {{{ smtpClientReady
    /**
     * Callback: An SMTP-Client is ready for action
     * 
     * @param qcEvents_Socket_Client_SMTP $Client
     * @param bool $Status
     * @param string $Originator
     * @param array $Private
     * 
     * @access public
     * @return void
     **/
    public function smtpClientReady (qcEvents_Socket_Client_SMTP $Client, $Status, $Originator, $Private) {
      // Check if MAIL FROM was accepted
      if (!$Status)
        return call_user_func ($Private [1], false, $Private [2]);
      
      // Enqueue first receiver
      $Client->addReceiver ($Private [0], null, array ($this, 'smtpReceiverAdded'), $Private);
      
      // Store as client-connection
      if ($this->isConnected ())
        $this->smtpClients [$Client->getRemoteHost ()] = $Client;
    }
    // }}}
    
    // {{{ smtpReceiverAdded
    /**
     * Callback: Receiver was added to the queue
     * 
     * @param qcEvents_Socket_Client_SMTP $Client
     * @param bool $Status
     * @param string $Receiver
     * @param array $Private
     * 
     * @access public
     * @return void
     **/
    public function smtpReceiverAdded (qcEvents_Socket_Client_SMTP $Client, $Status, $Receiver, $Private) {
      call_user_func ($Private [1], $Client->getLastCode (), $Private [2]);
    }
    // }}}
    
    // {{{ smtpMessageReceived
    /**
     * Callback: Message-Data was completely received
     * 
     * @param string $Body The actual message-data
     * @param callable $Callback A callback to run after this one (with a status)
     * @param mixed $Private (optional) Some private data to pass to the callback
     * 
     * @access protected
     * @return void
     **/
    protected function smtpMessageReceived ($Body, callable $Callback, $Private = null) {
      // Make sure there are clients connected
      if (count ($this->smtpClients) < 1)
        return call_user_func ($Callback, false, $Private);
      
      // Prepend received-trace
      # TODO: Remote SMTP-Name isn't checked
      # TODO: Lookup remote name
      # TODO: via/id/for-keywords?!
      $Body =
        'Received: from ' . $this->getSMTPRemoteName () . ' (unknown [' . $this->getRemoteHost () . '])' ."\r\n" .
        "\t" . 'by ' . $this->smtpDomainname () . ' (qcEvents/SMTP-20131021) with ESMTP' . ($this->tlsEnable () ? 'S' : '') . ($this->smtpAuthenticated () ? 'A' : '') . '; ' . date ('r') . "\r\n" .
        $Body;
      
      // Start submission
      $Clients = $this->smtpClients;
      $Client = array_shift ($Clients);
      
      return $Client->sendData ($Body, array ($this, 'smtpProcessMessage'), array ($Client, $Clients, array (), array (), $Body, $Callback, $Private));
    }
    // }}}
    
    // {{{ smtpProcessMessage
    /**
     * Internal Callback: Handle mail-submission for all clients
     * 
     * @param qcEvents_Socket_Client_SMTP $Client The Client that finished the last request
     * @param bool $Status The status of the last request
     * @param array $Private Internal data to finish the request
     * 
     * @access public
     * @return void
     **/
    public function smtpProcessMessage (qcEvents_Socket_Client_SMTP $Client, $Status, $Private) {
      // Check if submission was successfull
      if ($Status)
        $Private [2][] = $Client;
      else
        $Private [3][] = $Client;
      
      // Check if there is a next client
      if (count ($Private [1]) > 0) {
        $Private [0] = array_shift ($Private [1]);
        
        return $Private [0]->sendData ($Private [4], array ($this, 'smtpProcessMessage'), $Private);
      }
      
      // Check if an error happened
      
      // Check if at least one client accepted the mail
      call_user_func ($Private [5], (count ($Private [2]) > 0), $Private [6]);
    }
    // }}}
    
    // {{{ smtpRelayDisconnected
    /**
     * Callback: Client-Connection was closed, close our clients as well
     * 
     * @access public
     * @return void
     **/
    public final function smtpRelayDisconnected () {
      foreach ($this->smtpClients as $Client)
        $Client->quit ();
      
      $this->smtpClients = array ();
    }
    // }}}
  }

?>