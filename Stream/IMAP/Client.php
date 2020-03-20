<?PHP

  /**
   * qcEvents - Asyncronous IMAP Stream
   * Copyright (C) 2013-2020 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Defered.php');
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  require_once ('qcEvents/Stream/IMAP/Mailbox.php');
  require_once ('qcEvents/Stream/IMAP/Literal.php');
  
  /**
   * IMAPv4r1 Client
   * ---------------
   * Full support for RFC 3501 "Internet Mesasge Access Protocol - Version 4rev1",
   * RFC 2088 "Literal+", RFC 2342 "IMAP Namespace", RFC 3691 "IMAP UNSELECT",
   * RFC 4315 "UIDPLUS" and RFC 4959 "IMAP SASL-IR".
   * 
   * @todo RFC 5256 SORT/THREAD
   *       RFC 2177 IDLE
   *       RFC 4978 COMPRESS
   *       RFC 3502 MULTIAPPEND
   *       RFC 4469 CATENATE
   *       RFC 4314 ACLs
   *       RFC 2087 Quota
   *       RFC 5465 NOTIFY
   *       RFC 5464 METADATA
   *       RFC 6855 UTF-8
   *       RFC 3348 Mailbox-Children
   **/
  class qcEvents_Stream_IMAP_Client extends qcEvents_Hookable implements qcEvents_Interface_Stream_Consumer {
    /* Defaults for IMAP */
    const DEFAULT_PORT = 143;
    const DEFAULT_TYPE = qcEvents_Socket::TYPE_TCP;
    
    /* Connection-states */
    const IMAP_STATE_DISCONNECTED = 0;
    const IMAP_STATE_DISCONNECTING = 1;
    const IMAP_STATE_CONNECTING = 2;
    const IMAP_STATE_CONNECTED = 3;
    const IMAP_STATE_AUTHENTICATED = 4;
    const IMAP_STATE_ONMAILBOX = 5;
    
    private $imapState = qcEvents_Stream_IMAP_Client::IMAP_STATE_DISCONNECTED;
    
    /* Response-status */
    const IMAP_STATUS_OK = 'OK';
    const IMAP_STATUS_NO = 'NO';
    const IMAP_STATUS_BAD = 'BAD';
    const IMAP_STATUS_BYE = 'BYE';
    const IMAP_STATUS_PREAUTH = 'PREAUTH';
    
    /* Set/Store-Modes */
    const STORE_MODE_SET = 0;
    const STORE_MODE_ADD = 1;
    const STORE_MODE_DEL = 2;
    
    /* Our Source-Stream */
    private $sourceStream = null;
    
    /* Receive-Buffer */
    private $receiveBuffer = '';
    
    /* Capabilities of our IMAP-Server */
    private $serverCapabilities = null;
    
    /* Command Sequence */
    private $commandSequence = 0xA000;
    
    /* Currently active command */
    private $activeCommand = null;
    
    /* Queue for pending commands */
    private $pendingCommands = array ();
    
    #/* Callback for TLS-Negotiation */
    #private $imapTLSCallback = null;
    #
    /* Namespaces on this server */
    private $imapNamespaces = null;
    
    #/* Status-Cache for Mailboxes */
    #private $imapMailboxes = array ();
    #
    #/* Current Mailbox */
    #private $imapMailbox = null;
    #
    #/* Predicted next mailbox */
    #private $imapMailboxNext = null;
    #
    #/* Read-Only-Status of current mailbox */
    #private $mailboxReadOnly = false;
    #
    #/* Message-Information */
    #private $messages = array ();
    #
    #/* Sequence-to-UID mappings */
    #private $messageIDs = array ();
    #
    #/* Message-IDs of last search */
    #private $searchResult = array ();
    
    /* Promise for our stream-initialization */
    private $initPromise = null;
    
    // {{{ isConnected
    /**
     * Check if this IMAP-Connection is at least in connected state
     * 
     * @access public
     * @return bool
     **/
    public function isConnected () {
      return ($this->imapState >= self::IMAP_STATE_CONNECTED);
    }
    // }}}
    
    // {{{ imapIsAuthenticated
    /**
     * Check if this IMAP-Connection is at least in authenticated state
     * 
     * @access public
     * @return bool
     **/
    public function isAuthenticated () {
      return ($this->imapState >= self::IMAP_STATE_AUTHENTICATED);
    }
    // }}}
    
    // {{{ getCapabilities
    /**
     * Request a list of capabilities of this server
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.1.1
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function getCapabilities () : qcEvents_Promise {
      return $this->imapCommand ('CAPABILITY')->then (
        function ($responseText, array $responseDatas) {
          // Look for new capabilities
          $serverCapabilities = null;
          
          foreach ($responseDatas as $responseData)
            if ($responseData [0] == 'CAPABILITY')
              $serverCapabilities = explode (' ', $responseData [1]);
          
          // Make sure we found capabilities
          if (!is_array ($serverCapabilities))
            throw new Error ('No capabilties received');
          
          // Raise a callback for this
          $this->___callback ('imapCapabilities', $serverCapabilities);
          
          return ($this->serverCapabilities = $serverCapabilities);
        }
      );
    }
    // }}}
    
    // {{{ haveCapabilty
    /**
     * Check if a given capability is supported by the server
     * 
     * @param string $checkCapability
     * 
     * @access public
     * @return bool
     **/
    public function haveCapability ($checkCapability) {
      if ($this->serverCapabilities === null)
        return null;
      
      return in_array ($checkCapability, $this->serverCapabilities);
    }
    // }}}
    
    // {{{ checkCapability
    /**
     * Safely check if the server supports a requested capability
     * 
     * @param string $checkCapability
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function checkCapability ($checkCapability) : qcEvents_Promise {
      return $this->getCapabilities ()->then (
        function (array $serverCapabilities) use ($checkCapability) {
          if (!in_array ($checkCapability, $serverCapabilities))
            throw new Error ('Requested capability not available');
          
          return true;
        },
        function () {
          return null;
        }
      );
    }
    // }}}
    
    // {{{ noOp
    /**
     * Issue an NoOp-Command to keep the connection alive or retrive any pending updates
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.1.2
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function noOp () : qcEvents_Promise {
      return $this->imapCommand ('NOOP')->then (
        function () { }
      );
    }
    // }}}
    
    // {{{ logout
    /**
     * Request a logout from server
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.1.3
     * 
     * @access public
     * @return void
     **/
    public function logout () : qcEvents_Promise {
      $this->imapSetState ($this::IMAP_STATE_DISCONNECTING);
      
      return $this->imapCommand ('LOGOUT')->then (
        function () { }
      );
    }
    // }}}
    
    // {{{ startTLS
    /**
     * Enable TLS-encryption on this connection
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.2.1
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function startTLS () : qcEvents_Promise {
      // Check if StartTLS is supported
      return $this->checkCapability ('STARTTLS')->then (
        function () {
          // Request start of TLS
          return $this->imapCommand ('STARTTLS');
        }
      )->then (
        function () {
          // Try to enable TLS on our source stream
          return $this->sourceStream->tlsEnable (true);
        },
        function () {
          // Raise a callback
          $this->___callback ('tlsFailed');
          
          // Forward the result
          throw new qcEvents_Promise_Solution (func_get_args ());
        }
      )->then (
        function () {
          // Reset capabilities
          $this->serverCapabilities = null;
          
          return $this->getCapabilities ()->then (
            function () { },
            function () { }
          );
        }
      );
    }
    // }}}
    
    // {{{ authenticate
    /**
     * Try to login using AUTHENTICATE
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.2.2
     * 
     * @param string $Username
     * @param string $Password
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function authenticate ($Username, $Password) : qcEvents_Promise {
      // Create SASL-Client
      require_once ('qcAuth/SASL/Client.php');
      
      $saslClient = new qcAuth_SASL_Client;
      $saslClient->setUsername ($Username);
      $saslClient->setPassword ($Password);
      
      // Create list of mechanisms to try
      $saslMechanisms = $saslClient->getMechanisms ();
      
      // Create authenticator
      $saslAuthenticate = null;
      $saslAuthenticate = function () use (&$saslAuthenticate, &$saslMechanisms, $saslClient) {
        if (count ($saslMechanisms) < 1)
          return qcEvents_Promise::reject ('No more mechanisms available');
        
        // Peek the next usable mechanism
        $saslMechanism = array_shift ($saslMechanisms);
        $saslClient->cancel ();
        
        while (!$saslClient->setMechanism ($saslMechanism))
          if (count ($saslMechanisms) > 0)
            $saslMechanism = array_shift ($saslMechanisms);
          else
            return qcEvents_Promise::reject ('No more mechanisms available');
        
        // Prepare arguments
        if ($this->haveCapability ('SASL-IR') && (($saslInitial = $saslClient->getInitialResponse ()) !== null))
          $saslArguments = array ($saslMechanism, (strlen ($saslInitial) > 0 ? base64_encode ($saslInitial) : '='));
        else
          $saslArguments = array ($saslMechanism);
        
        $saslInitial = true;
        
        return $this->imapCommand (
          'AUTHENTICATE',
          $saslArguments,
          function ($saslChallenge) use (&$saslInitial, $saslClient) {
            if (!$saslInitial || $this->haveCapability ('SASL-IR'))
              return base64_encode ($saslClient->getResponse ());
            
            $saslInitial = false;
            
            return base64_encode ($saslClient->getInitialResponse ());
          }
        )->then (
          function () {
            // Set new state
            $this->imapSetState ($this::IMAP_STATE_AUTHENTICATED);
            
            // Re-request capabilties
            return $this->getCapabilities ()->catch (
              function () { }
            )->then (
              function () {
                // Just raise a callback
                $this->___callback ('imapAuthenticated');
              }
            );
          },
          function () use ($saslAuthenticate, &$saslInitial) {
            // Check wheter to try the next method
            if ($saslInitial)
              return $saslAuthenticate ();
            
            // Raise a callback
            $this->___callback ('imapAuthenticationFailed');
            
            // Just pass the error
            throw new qcEvents_Promise_Solution (func_get_args ());
          }
        );
      };
      
      // Run authenticator
      return $saslAuthenticate ();
    }
    // }}}
    
    // {{{ login
    /**
     * Login on server using LOGIN
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.2.3
     * 
     * @param string $Username
     * @param string $Password
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function login ($Username, $Password) : qcEvents_Promise {
      return $this->imapCommand (
        'LOGIN',
        array ($Username, $Password)
      )->then (
        function () {
          // Set new state
          $this->imapSetState ($this::IMAP_STATE_AUTHENTICATED);
          
          // Re-request capabilties
          return $this->getCapabilities ()->catch (
            function () { }
          )->then (
            function () {
              // Just raise a callback
              $this->___callback ('imapAuthenticated');
            }
          );
        },
        function () {
          // Raise a callback
          $this->___callback ('imapAuthenticationFailed');
          
          // Just pass the error
          throw new qcEvents_Promise_Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ selectMailbox
    /**
     * Open a given mailbox
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.3.1
     * @see https://tools.ietf.org/html/rfc3501#section-6.3.2
     * 
     * @param string $mailboxName
     * @param bool $readOnly (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function selectMailbox ($mailboxName, $readOnly = false) : qcEvents_Promise {
      // Remove current mailbox
      $this->imapMailbox = null;
      $this->imapSetState ($this::IMAP_STATE_AUTHENTICATED);
      
      // Issue the command 
      return $this->imapCommand (
        ($readOnly ? 'EXAMINE' : 'SELECT'),
        array ($mailboxName)
      )->then (
        function ($responseText, array $responseItems, array $responseCode = null) use ($mailboxName, $readOnly) {
          // Update our state
          $this->imapMailbox = new qcEvents_Stream_IMAP_Mailbox ($mailboxName);
          $this->imapMailboxReadOnly = $readOnly;
          
          // Process response items
          foreach ($responseItems as $responseData) {
            if ($responseData [0] == 'FLAGS') 
              $this->imapMailbox->setFlags ($this::imapDecodeArguments ($responseData [1]) [0]);
            elseif ($responseData [1] == 'EXISTS')
              $this->imapMailbox->setMessageCount ((int)$responseData [0]);
            elseif ($responseData [1] == 'RECENT')
              $this->imapMailbox->setRecentCount ((int)$responseData [0]);
            
            if ($responseData [2] !== null)
              for ($i = 0; $i < count ($responseData [2]); $i++)
                if ($responseData [2][$i] == 'UNSEEN')
                  $this->imapMailbox->setFirstUnseen ((int)$responseData [2][++$i]);
                elseif ($responseData [2][$i] == 'PERMANENTFLAGS')
                  $this->imapMailbox->setPermanentFlags ($responseData [2][++$i]);
                elseif ($responseData [2][$i] == 'UIDNEXT')
                  $this->imapMailbox->setNextUID ((int)$responseData [2][++$i]);
                elseif ($responseData [2][$i] == 'UIDVALIDITY')
                  $this->imapMailbox->setUIDValidity ((int)$responseData [2][++$i]);
                elseif ($responseData [2][$i] == 'UIDNOSTICKY')
                  $this->imapMailbox->supportsPersistentUID (false);
          }
          
          // Change out state
          $this->imapSetState ($this::IMAP_STATE_ONMAILBOX);
          
          // Fire callback
          $this->___callback ('imapMailboxOpened', $mailboxName, $readOnly);
        },
        function () use ($mailboxName) {
          $this->___callback ('imapMailboxOpenFailed', $mailboxName);
          
          throw new qcEvents_Promise_Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ createMailbox
    /**
     * Create a new mailbox
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.3.3
     * 
     * @param string $mailboxName
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function createMailbox ($mailboxName) : qcEvents_Promise {
      // Issue the command
      return $this->imapCommand (
        'CREATE',
        array ($mailboxName)
      )->then (
        function () { }
      );
    }
    // }}}
    
    // {{{ deleteMailbox
    /**
     * Remove a mailbox from server
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.3.4
     * 
     * @param string $mailboxName
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function deleteMailbox ($mailboxName) : qcEvents_Promise {
      // Issue the command
      return $this->imapCommand (
        'DELETE',
        array ($mailboxName)
      )->then (
        function () { }
      );
    }
    // }}}
    
    // {{{ renameMailbox
    /**
     * Rename a mailbox on our server
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.3.5
     * 
     * @param string $mailboxName
     * @param string $newName
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function renameMailbox ($mailboxName, $newName) : qcEvents_Promise {
      // Issue the command
      return $this->imapCommand (
        'RENAME',
        array ($mailboxName, $newName)
      )->then (
        function () { }
      );
    }
    // }}}
    
    // {{{ subscribeMailbox
    /**
     * Subscribe to a mailbox on our server
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.3.6
     * 
     * @param string $mailboxName
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function subscribeMailbox ($mailboxName) : qcEvents_Promise {
      // Issue the command
      return $this->imapCommand (
        'SUBSCRIBE',
        array ($mailboxName)
      )->then (
        function () { }
      );
    }
    // }}}
    
    // {{{ unsubscribeMailbox
    /**
     * Unsubscribe from a mailbox on our server
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.3.7
     * 
     * @param string $mailboxName
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function unsubscribeMailbox ($mailboxName) : qcEvents_Promise {
      // Issue the command
      return $this->imapCommand (
        'UNSUBSCRIBE',
        array ($mailboxName)
      )->then (
        function () { }
      );
    }
    // }}}
    
    // {{{ listMailboxes
    /**
     * Retrive a list of mailboxes from the server
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.3.8
     * @see https://tools.ietf.org/html/rfc3501#section-6.3.9
     * 
     * @param string $parentMailbox (optional)
     * @param string $nameFilter (optional)
     * @param bool $subscribedOnly (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function listMailboxes ($parentMailbox = '', $nameFilter = '%', $subscribedOnly = false) : qcEvents_Promise {
      return $this->imapCommand (
        ($subscribedOnly ? 'LSUB' : 'LIST'),
        array ($parentMailbox, $nameFilter)
      )->then (
        function ($responseText, array $responseItems) use ($subscribedOnly) {
          // Prepare the result
          $resultMailboxes = array ();
          
          foreach ($responseItems as $responseData) {
            // Check if we are interested in this response-item
            if ($responseData [0] != ($subscribedOnly ? 'LSUB' : 'LIST'))
              continue;
            
            // Unpack mailbox-info
            if (!is_array ($mailboxInfo = $this::imapDecodeArguments ($responseData [1]))) {
              trigger_error ('Failed to deserialize mailbox-info');
              
              continue;
            }
            
            // Create a mailbox for this
            $resultMailboxes [] = $imapMailbox = new qcEvents_Stream_IMAP_Mailbox ($mailboxInfo [2]);
            
            $imapMailbox->setDelimiter ($mailboxInfo [1]);
            $imapMailbox->setAttributes ($mailboxInfo [0]);
          }
          
          // Forward the result
          return $resultMailboxes;
        }
      );
    }
    // }}}
    
    // {{{ statusMailbox
    /**
     * Retrive the status for a given mailbox
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.3.10
     * 
     * @param string $mailboxName
     * @param array $requestStatuses (optional)
     * 
     * @access public
     * @return void
     **/
    public function statusMailbox ($mailboxName, array $requestStatuses = null) {
      // Check wheter to retrive all statuses for this mailbox
      if ($requestStatuses === null)
        $requestStatuses = array (
          'MESSAGES',
          'RECENT',
          'UIDNEXT',
          'UIDVALIDITY',
          'UNSEEN',
        );
      
      // Issue the command
      return $this->imapCommand (
        'STATUS',
        array ($mailboxName, $requestStatuses)
      )->then (
        function ($responseText, array $responseItems) use ($mailboxName) {
          foreach ($responseItems as $responseData) {
            // Make sure it's a STATUS-response
            if ($responseData [0] != 'STATUS')
              continue;
            
            // Try to read the response
            if (!is_array ($mailboxStatus = $this::imapDecodeArguments ($responseData [1]))) {
              trigger_error ('Failed to decode STATUS-response');
              
              continue;
            }
            
            // Sanity-Check the mailbox-name
            if (strcasecmp ($mailboxName, $mailboxStatus [0]) != 0)
              continue;
            
            // Repack the result
            $imapMailbox = new qcEvents_Stream_IMAP_Mailbox ($mailboxName);
            
            for ($i = 0; $i < count ($mailboxStatus [1]); $i++)
              if ($mailboxStatus [1][$i] == 'MESSAGES')
                $imapMailbox->setMessageCount ((int)$mailboxStatus [1][++$i]);
              elseif ($mailboxStatus [1][$i] == 'RECENT')
                $imapMailbox->setRecentCount ((int)$mailboxStatus [1][++$i]);
              elseif ($mailboxStatus [1][$i] == 'UIDNEXT')
                $imapMailbox->setNextUID ((int)$mailboxStatus [1][++$i]);
              elseif ($mailboxStatus [1][$i] == 'UIDVALIDITY')
                $imapMailbox->setUIDValidity ((int)$mailboxStatus [1][++$i]);
              elseif ($mailboxStatus [1][$i] == 'UNSEEN')
                $imapMailbox->setUnseenCount ((int)$mailboxStatus [1][++$i]);
            
            return $imapMailbox;
          }
        }
      );
    }
    // }}}
    
    // {{{ appendMailbox
    /**
     * Append a given message to a mailbox
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.3.11
     * @see https://tools.ietf.org/html/rfc4315#section-3
     * 
     * @param string $mailboxName
     * @param string $messageBody
     * @param array $messageFlags (optional)
     * @param int $messageTimestamp (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function appendMailbox ($mailboxName, $messageBody, array $messageFlags = null, $messageTimestamp = null) : qcEvents_Promise {
      // Generate parameters
      $commandArguments = array ($mailboxName);
      
      if ($messageFlags !== null)
        $commandArguments [] = $messageFlags;
      
      if ($messageTimestamp !== null)
        $commandArguments [] = date ('d-M-Y H:i:s O', $messageTimestamp);
      
      $commandArguments [] = new qcEvents_Stream_IMAP_Literal ($messageBody);
      
      // Issue the command
      return $this->imapCommand (
        'APPEND',
        $commandArguments
      )->then (
        function ($responseText, array $responseItems, array $responseCodes = null) {
          // Check for an UIDPLUS-Response (RFC 4315)
          $uidValidity = null;
          
          if ($responseCodes)
            for ($i = 0; $i < count ($responseCodes); $i++)
              if ($responseCodes [$i] == 'APPENDUID') {
                $uidValidity = $responseCodes [++$i];
                $uidMessageSet = $responseCodes [++$i];
              }
          
          # TODO: Expand the uidMessageSet
          
          if ($uidValidity !== null)
            return array ($uidValidity, $uidMessageSet);
        }
      );
    }
    // }}}
    
    // {{{ checkMailbox
    /**
     * Issue a CHECK-Command
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.4.1
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function checkMailbox () : qcEvents_Promise {
      return $this->imapCommand ('CHECK')->then (
        function () { }
      );
    }
    // }}}
    
    // {{{ closeMailbox
    /**
     * Close the currently selected mailbox
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.4.2
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function closeMailbox () : qcEvents_Promise {
      return $this->imapCommand ('CLOSE')->then (
        function () {
          // Remove current mailbox
          $lastMailbox = $this->imapMailbox;
          $this->imapMailbox = null;
          
          // Update the state
          $this->imapSetState ($this::IMAP_STATE_AUTHENTICATED);
          
          // Raise a callback
          if ($lastMailbox)
            $this->___callback ('imapMailboxClosed', $lastMailbox);
        },
        function () {
          // Raise a callback
          if ($this->imapMailbox)
            $this->___callback ('imapMailboxCloseFailed', $this->imapMailbox);
          
          // Forward the rejection
          throw new qcEvents_Promise_Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ expungeMailbox
    /**
     * Wipe out all mails marked as deleted on the current mailbox
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.4.3
     * @see https://tools.ietf.org/html/rfc4315#section-2.1
     * 
     * @param string $uidSet (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function expungeMailbox ($uidSet = null) : qcEvents_Promise {
      return ($uidSet !== null ? $this->checkCapability ('UIDPLUS') : qcEvents_Promise::resolve (true))->then (
        function () use ($uidSet) {
          return $this->imapCommand (
            ($uidSet !== null ? 'UID ' : '') . 'EXPUNGE',
            ($uidSet !== null ? $this->imapEncodeSequence ($uidSet) : null)
          );
        }
      )->then (
        function ($responseText, array $responseData) {
          $sequenceNumbers = array ();
          
          foreach ($responseData as $responseItem)
            if ($responseItem [1] == 'EXPUNGE')
              $sequenceNumbers [] = (int)$responseItem [0];
          
          return $sequenceNumbers;
        }
      );
    }
    // }}}
    
    // {{{ searchMessages
    /**
     * Search a set of messages
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.4.4
     * 
     * @param bool $byUID (optional)
     * @param string $usedCharset (optional)
     * @param string ... (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function searchMessages ($byUID = false, $usedCharset = null, $defaultMatch = 'ALL') : qcEvents_Promise {
      // Extract search-arguments
      if (count ($searchArguments = array_slice (func_get_args (), 2)) == 0)
        $searchArguments [] = 'ALL';
      
      foreach ($searchArguments as $argumentIndex=>$searchArgument)
        if (!$this->imapCheckSearchKey ($searchArgument))
          unset ($searchArguments [$argumentIndex]);
      
      if (count ($searchArguments) > 1)
        $searchArguments = array ($searchArguments);
      
      // Prepend Charset to arguements
      if ($usedCharset !== null)
        array_unshift ($AsearchArguments, 'CHARSET', $useCharset);
      
      // Issue the command
      return $this->imapCommand (
        ($byUID ? 'UID SEARCH' : 'SEARCH'),
        $searchArguments
      )->then (
        function ($responseText, array $responseData, array $responseCodes = null) {
          $searchedSequence = null;
          
          foreach ($responseData as $responseItem)
            if ($responseItem [0] != 'SEARCH')
              continue;
            elseif ($searchedSequence !== null)
              throw new Error ('More than one SEARCH-Response found');
            else
              $searchedSequence = $this::imapDecodeArguments ($responseItem [1]);
          
          if ($searchedSequence === null)
            throw new Error ('No SEARCH-Response received');
          
          array_walk (
            $searchedSequence,
            function (&$sequenceNumber) {
              $sequenceNumber = intval ($sequenceNumber);
              
              if ($sequenceNumber < 1)
                throw new Error ('Invalid sequence-number received');
            }
          );
          
          return $searchedSequence;
        }
      );
    }
    // }}}
    
    // {{{ fetchMessages
    /**
     * Fetch messages from server
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.4.5
     * 
     * @param bool $byUID
     * @param sequence $IDs
     * @param string ... (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function fetchMessages ($byUID, $IDs, $fetchSpec = 'ALL') : qcEvents_Promise {
      // Handle all data-items
      if (count ($fetchArguments = array_slice (func_get_args (), 2)) == 0)
        $fetchArguments [] = 'ALL';
      
      foreach ($fetchArguments as $idx=>$fetchArgument)
        // Make sure Macros are on their own
        if ($fetchArgument == 'ALL') {
          $fetchArguments = array ('UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'ENVELOPE');
          
          break;
        } elseif ($fetchArgument == 'FAST') {
          $fetchArguments = array ('UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE');
          
          break;
        } elseif ($fetchArgument == 'FULL') {
          $fetchArguments = array ('UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'ENVELOPE', 'BODY');
          
          break;
        
        // Check wheter to fetch a body-section
        } elseif ((substr ($fetchArgument, 0, $sectionOffset = 5) == 'BODY[') ||
                  (substr ($fetchArgument, 0, $sectionOffset = 10) == 'BODY.PEEK[')) {
          if (($p = strpos ($fetchArgument, ']', $sectionOffset)) !== false) {
            $bodySection = substr ($fetchArgument, $sectionOffset, $p - $sectionOffset);
            
            // Sanatize partial get
            if ($p < ($l = strlen ($fetchArgument)) - 1) {
              if (($fetchArgument [$p + 1] != '<') ||
                  ($fetchArgument [$l - 1] != '>'))
                $bodySection = null;
              else
                $partialLength = substr ($fetchArgument, $p + 2, $l - $p - 2);
            } else
              $partialLength = null;
          }
          
          if (!$p || ($bodySection === null))
            unset ($fetchArgument [$idx]);
          
          # TODO: Validate the section-value
          # TODO: Validate partial length
          
        // Discard the value if it seems invalid
        } elseif (!in_array ($fetchArgument, array ('ENVELOPE', 'FLAGS', 'UID', 'INTERNALDATE', 'RFC822', 'RFC822.HEADER', 'RFC822.SIZE', 'RFC822.TEXT', 'BODY', 'BODYSTRUCTURE')))
          unset ($fetchArgument [$idx]);
      
      // Make sure always to fetch the UID
      if (!in_array ('UID', $fetchArguments))
        $fetchArguments [] = 'UID';
      
      // Prepare the args
      if (!($messageSequence = $this->imapEncodeSequence ($IDs)))
        return qcEvents_Promise::reject ('Invalid message-sequence');
      
      // Issue the command
      return $this->imapCommand (
        ($byUID ? 'UID FETCH' : 'FETCH'),
        array ($messageSequence, $fetchArguments)
      )->then (
        function ($responseText, array $responseData, array $responseCodes = null) {
          // Process FETCH-Responses
          $responseMessages = array ();
          
          foreach ($responseData as $responseItem) {
            if (substr ($responseItem [1], 0, 6) != 'FETCH ')
              continue;
            
            $messageSequence = (int)$responseItem [0];
            
            if (!is_array ($messageData = $this::imapDecodeArguments ($responseItem [1])))
              throw new Error ('Failed to decode FETCH-Response');
            
            $messageInfo = array ();
            
            for ($i = 0; $i < count ($messageData [1]); $i+=2)
              $messageInfo [$messageData [1][$i]] = $messageData [1][$i + 1];
            
            $responseMessages [$messageSequence] = $messageInfo;
          }
          
          // Forward the message-data
          return $responseMessages;
        }
      );
    }
    // }}}
    
    // {{{ storeMessageFlags
    /**
     * Store flags of a messages
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.4.6
     * 
     * @param bool $byUID
     * @param sequence $IDs
     * @param array $messageFlags
     * @param enum $storeMode (optional)
     * @param bool $storeSilent (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function storeMessageFlags ($byUID, $IDs, array $messageFlags, $storeMode = self::STORE_MODE_SET, $storeSilent = false) : qcEvents_Promise {
      // Prepare the arguments
      if (!($messageSequence = $this->imapEncodeSequence ($IDs)))
        return qcEvents_Promise::reject ('Failed to encode message-sequence');
      
      $commandArguments = array ($messageSequence);
      
      if ($storeMode == $this::STORE_MODE_SET)
        $commandArguments [] = 'FLAGS' . ($storeSilent ? '.SILENT' : '');
      elseif ($storeMode == $this::MODE_ADD)
        $commandArguments [] = '+FLAGS' . ($storeSilent ? '.SILENT' : '');
      elseif ($storeMode == $this::MODE_DEL)
        $commandArguments [] = '-FLAGS' . ($storeSilent ? '.SILENT' : '');
      else
        return qcEvents_Promise::reject ('Invalid store-mode');
      
      $commandArguments [] = $messageFlags;
      
      // Issue the command
      return $this->imapCommand (
        ($byUID ? 'UID STORE' : 'STORE'),
        $commandArguments
      )->then (
        function ($responseText, array $responseData, array $responseCodes = null) {
        
        }
      );
    }
    // }}}
    
    // {{{ copyMessages
    /**
     * Copy a set of Messages to a given mailbox
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-6.4.7
     * @see https://tools.ietf.org/html/rfc4315#section-3
     * 
     * @param bool $byUID
     * @param sequence $IDs
     * @param string $mailboxName
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function copyMessages ($byUID, $IDs, $mailboxName) : qcEvents_Promise {
      // Prepare the args
      if (!($messageSequence = $this->imapEncodeSequence ($IDs)))
        return qcEvents_Promise::reject ('Failed to encode message-sequence');
      
      $Args = array ($Seq, $Mailbox);
      
      // Issue the command
      return $this->imapCommand (
        ($byUID ? 'UID COPY' : 'COPY'),
        array ($messageSequence, $mailboxName)
      )->then (
        function ($responseText, array $responseData, array $responseCodes = null) {
          // Check for an UIDPLUS-Response (RFC 4315)
          $uidValidity = null;
          
          if ($responseCodes)
            for ($i = 0; $i < count ($responseCodes); $i++)
              if ($responseCodes [$i] == 'APPENDUID') {
                $uidValidity = $responseCodes [++$i];
                $uidMessageSet = $responseCodes [++$i];
              }
          
          # TODO: Expand the uidMessageSet
          
          if ($uidValidity !== null)
            return array ($uidValidity, $uidMessageSet);
        }
      );
    }
    // }}}
    
    // {{{ unselect
    /**
     * Unselect the current mailbox
     * 
     * @remark this is similar to CLOSE, but does not implicit expunge deleted messages
     * @see https://tools.ietf.org/html/rfc3691
     * 
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function unselect () : qcEvents_Promise {
      return $this->checkCapability ('UNSELECT')->then (
        function () {
          return $this->imapCommand ('UNSELECT');
        }
      )->then (
        function () {
          // Remove current mailbox
          $lastMailbox = $this->imapMailbox;
          $this->imapMailbox = null;
          
          // Update the state
          $this->imapSetState ($this::IMAP_STATE_AUTHENTICATED);
          
          // Raise a callback
          if ($lastMailbox)
            $this->___callback ('imapMailboxClosed', $lastMailbox);
        },
        function () {
          // Raise a callback
          if ($this->imapMailbox)
            $this->___callback ('imapMailboxCloseFailed', $this->imapMailbox);
          
          // Forward the rejection
          throw new qcEvents_Promise_Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ getNamespaces
    /**
     * Request a list of all namespaces on this server
     * 
     * @see https://tools.ietf.org/html/rfc2342
     * 
     * @param bool $forceFetch (optional) Ignore cached result
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function getNamespaces ($forceFetch = false) : qcEvents_Promise {
      // Check for a cached result
      if (!$forceFetch && ($this->imapNamespaces !== null))
        return qcEvents_Promise::resolve ($this->imapNamespaces);
      
      // Issue the command
      return $this->imapCommand (
        'NAMESPACE'
      )->then (
        function ($responseText, array $responseItems) {
          // Prepare the result
          $resultNamespaces = null;
          
          foreach ($responseItems as $responseData) {
            // Check if we are interested in this response-item
            if ($responseData [0] != 'NAMESPACE')
              continue;
            
            // Check if we already found a NAMESPACE-Response
            if ($resultNamespaces !== null)
              throw new Error ('');
            
            
            // Unpack namespace-info
            if (!is_array ($namespaceInfo = $this::imapDecodeArguments ($responseData [1]))) {
              trigger_error ('Failed to deserialize namespace');
              
              continue;
            }
            
            // Build the result
            $resultNamespaces = array (
              'personal' => array (),
              'users' => array (),
              'shared' => array (),
            );
            
            foreach (array_keys ($resultNamespaces) as $sectionName) {
              // Make sure there is data to read
              if (count ($namespaceInfo) < 1)
                throw new Error ('Short read on namespace-info');
              
              // Get the next namespace-section
              if (($sectionInfo = array_shift ($namespaceInfo)) === null)
                continue;
              
              foreach ($sectionInfo as $namespaceData)
                $resultNamespaces [$sectionName][] = array (
                  'prefix' => $namespaceData [0],
                  'delimiter' => $namespaceData [1],
                );
            }
          }
          
          // Check if a result was found
          if ($resultNamespaces === null)
            throw new Error ('No NAMESPACE-Response received');
          
          // Forward the result
          return ($this->imapNamespaces = $resultNamespaces);
        }
      );
    }
    // }}}
    
    
    // {{{ close
    /**
     * Close this event-interface
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function close () : qcEvents_Promise {
      if ($this->imapState > $this::IMAP_STATE_DISCONNECTING)
        return $this->logout ();
      
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param mixed $receivedData
     * @param qcEvents_Interface_Source $sourceStream
     * 
     * @access public
     * @return void
     **/
    public function consume ($receivedData, qcEvents_Interface_Source $sourceStream) {
      // Push to receive-buffer
      $this->receiveBuffer .= $receivedData;
      unset ($receivedData);
      
      // Process lines from buffer
      $bufferStart = $bufferProcessed = 0;
      
      while (($bufferStart = strpos ($this->receiveBuffer, "\n", $bufferProcessed)) !== false) {
        // Peek line from buffer
        $receivedLine = substr ($this->receiveBuffer, $bufferProcessed, $bufferStart - $bufferProcessed);
        
        if (substr ($receivedLine, -1, 1) == "\r")
          $receivedLine = substr ($receivedLine, 0, -1);
        
        // Check for literal on line
        if ((substr ($receivedLine, -1, 1) == '}') && (($p = strrpos ($receivedLine, '{')) !== false)) {
          // Extract length of literal
          $literalLength = intval (substr ($receivedLine, $p + 1, -1));
          
          // Check if the entire literal is on buffer
          if (strlen ($this->receiveBuffer) < $bufferStart + $literalLength + 1)
            break;
          
          // Append the entire literal to line
          $receivedLine .= "\r\n" . substr ($this->receiveBuffer, $bufferStart + 1, $literalLength);
          
          // Move the pointer to the end of the literal
          $bufferProcessed = $bufferStart + 1 + $literalLength;
        
        // Just move buffer-pointer
        } else
          $bufferProcessed = $bufferStart + 1;
        
        
        // Grab the first token from the line
        if (($p = strpos ($receivedLine, ' ')) === false) {
          $this->___callback ('imapDiscard', $receivedLine);
          
          continue;
        }
        
        $lineTag = substr ($receivedLine, 0, $p);
        $receivedLine = ltrim (substr ($receivedLine, $p + 1));
        
        // Check for continuation
        if ($lineTag == '+') {
          $this->processContinuation ($receivedLine);
          
          continue;
        }
        
        // Split line into status and additional data
        if (($p = strpos ($receivedLine, ' ')) === false) {
          $this->___callback ('imapDiscard', $lineTag . ' ' . $receivedLine);
          
          continue;
        }
        
        $lineStatus = substr ($receivedLine, 0, $p);
        $receivedLine = ltrim (substr ($receivedLine, $p + 1));
        
        // Process the line
        $this->processLine ($lineTag, $lineStatus, $receivedLine);
      }
      
      // Truncate processed data from buffer
      if ($bufferProcessed)
        $this->receiveBuffer = substr ($this->receiveBuffer, $bufferProcessed);
    }
    // }}}
    
    // {{{ processLine
    /**
     * Process a line received from our server
     * 
     * @param string $lineTag
     * @param enum $lineStatus
     * @param string $lineData
     * 
     * @access private
     * @return void
     **/
    private function processLine ($lineTag, $lineStatus, $lineData) {
      // Raise initial callback
      if ($this->___callback ('imapRead', $lineTag, $lineStatus, $lineData) === false)
        return;
      
      // Check for embeded codes in line-data
      if ((strlen ($lineData) > 0) &&
          ($lineData [0] == '[') &&
          (($p = strpos ($lineData, ']', 1)) !== false)) {
        $lineCode = $this::imapDecodeArguments (substr ($lineData, 1, $p - 1));
        $lineData = ltrim (substr ($lineData, $p + 1));
        
        $this->processCode ($lineCode, $lineData);
      } else
        $lineCode = null;
      
      // Process connection-setup
      if ($this->imapState == $this::IMAP_STATE_CONNECTING) {
        // Connection is OK, we are in connected state
        if ($lineStatus == $this::IMAP_STATUS_OK)
          $imapState = $this->imapSetState ($this::IMAP_STATE_CONNECTED);
        
        // Connection is very good, we are already authenticated
        elseif ($lineStatus == $this::IMAP_STATUS_PREAUTH)
          $imapState = $this->imapSetState ($this::IMAP_STATE_AUTHENTICATED);
        
        // Something is wrong with us or the server, we will be disconnected soon
        elseif ($lineStatus == $this::IMAP_STATUS_BYE)
          $imapState = $this->imapSetState ($this::IMAP_STATE_DISCONNECTED);
        
        // RFC-Violation, leave immediatly
        else
          # TODO: Signal the violation
          $impaState = $this->imapSetState ($this::IMAP_STATE_DISCONNECTED);
        
        # TODO: Check wheter to request CAPABILITY
        
        // Handle connection-failures
        if ($imapState == $this::IMAP_STATE_DISCONNECTED) {
          // Reject the init-promise
          if ($this->initPromise)
            $this->initPromise->reject ('Connection could not be negotiated');
          
          $this->initPromise = null;
          
          // Trigger connection-close
          $this->close ();
        
        // Check wheter to run callbacks
        } elseif ($imapState !== null) {
          if ($this->initPromise)
            $this->initPromise->resolve ();
          
          $this->___callback ('imapConnected');
          
          if ($imapState > $this::IMAP_STATE_CONNECTED)
            $this->___callback ('imapAuthenticated');
          
          $this->initPromise = null;
        }
      }
      
      // Check if this is an untagged message from server
      if ($lineTag == '*')
        return $this->processUntagged ($lineStatus, $lineData, $lineCode);
      
      // Check if the message is related to a pending command
      $activeCommand = hexdec ($lineTag);
      
      if (!isset ($this->pendingCommands [$activeCommand])) {
        # TODO
        return;
      }
      
      // Check if the pending command wasn't finished successfull
      if ($lineStatus != $this::IMAP_STATUS_OK) {
        // Reject the promise
        $this->pendingCommands [$activeCommand][0]->reject ($lineData, $lineStatus);
        
        // Check if the response-status was valid at all
        if (($lineStatus != $this::IMAP_STATUS_NO) &&
            ($lineStatus != $this::IMAP_STATUS_BAD))
          $this->close ();
      
      // Just resolve the promise
      } else
        $this->pendingCommands [$activeCommand][0]->resolve ($lineData, $this->pendingCommands [$activeCommand][4], $lineCode);
        
      // Remove active command
      unset ($this->pendingCommands [$activeCommand]);
      $this->activeCommand = null;
      
      // Try to start next command
      $this->imapStartPendingCommand ();
    }
    // }}}
    
    // {{{ processContinuation
    /**
     * Process requested continuation
     * 
     * @param string $receivedLine
     * 
     * @access private
     * @return void
     **/
    private function processContinuation ($receivedLine) {
      // Check if we can process the request
      if (($this->activeCommand === null) ||
          !isset ($this->pendingCommands [$this->activeCommand]) ||
          ($this->pendingCommands [$this->activeCommand][3] === null)) {
        trigger_error ('No continuation-handler available');
        
        return $this->imapWriteToStream ('*' . "\r\n");
      }
      
      // Invoke the contionation-handler
      if ((strlen ($receivedLine) > 0) && ($receivedLine [0] != '['))
        $receivedLine = base64_decode ($receivedLine);
      
      $rc = $this->pendingCommands [$this->activeCommand][3] ($receivedLine);
      
      return $this->imapWriteToStream ($rc . "\r\n");
    }
    // }}}
    
    // {{{ processUntagged
    /**
     * Process an untagged response from server
     * 
     * @param string $lineType
     * @param string $lineData
     * @param array $lineCode (optional)
     * 
     * @access private
     * @return void
     **/
    private function processUntagged ($lineType, $lineData, array $lineCode = null) {
      // Push to active command
      if (($this->activeCommand !== null) &&
          isset ($this->pendingCommands [$this->activeCommand]))
        $this->pendingCommands [$this->activeCommand][4][] = array ($lineType, $lineData, $lineCode);
      
      # TODO: EXISTS Update number of messages on current mailbox
      # TODO: RECENT Update number of recent messages on current mailboxBA
      # TODO: FETCH Update message-metadata
    }
    // }}}
    
    // {{{ processCode
    /**
     * Special function to handle received codes
     * 
     * @see https://tools.ietf.org/html/rfc3501#section-7.1
     * 
     * @param array $lineCode
     * @param string $lineData
     * 
     * @access private
     * @return void
     **/
    private function processCode (array $lineCode, $lineData) {
      switch ($lineCode [0]) {
        // An alert was raised
        case 'ALERT':
        case 'PARSE': // This is not really an Alert but indicates an error on server-side
          $this->___callback ('imapAlert', $lineData);
          
          break;
        
        // Server includes capabilities in its greeting, fake an CAPABILITY-Command
        case 'CAPABILITY':
          // Store new capabilities
          $this->serverCapabilities = explode (' ', $lineData);
          
          // Raise a callback for this
          $this->___callback ('imapCapabilities', $this->serverCapabilities);
          
          break;
        
        # Other:
        #  PERMANENTFLAGS / UIDVALIDITY / UIDNEXT / UNSEEN - Handled in receivedUntagged
        #  READ-ONLY / READ-WRITE - Handled by SELECT/EXAMINE-Handler
        #  TRYCREATE
      }
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param qcEvents_Interface_Source $sourceStream
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function initStreamConsumer (qcEvents_Interface_Stream $sourceStream) : qcEvents_Promise {
      // Check for an existing init-promise
      if ($this->initPromise)
        $this->initPromise->reject ('Replaced by another stream');
      
      // Reset our internal state
      $this->sourceStream = $sourceStream;
      $this->receiveBuffer = '';
      
      $this->imapSetState ($this::IMAP_STATE_CONNECTING);
      
      // Create new init-promise
      $this->initPromise = new qcEvents_Defered;
      $this->initPromise->getPromise ()->then (
        function () use ($sourceStream) {
          $this->___callback ('eventPipedStream', $sourceStream);
        }
      );
            
      return $this->initPromise->getPromise ();
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function deinitConsumer (qcEvents_Interface_Source $Source) : qcEvents_Promise {
      # TODO?
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    // {{{ imapCommand
    /**
     * Issue an IMAP-Command over the wire
     * 
     * @param string $commandNAme
     * @param array $commandArguments (optional)
     * @param callable $continuationCallback (optional)
     * 
     * @access private
     * @return qcEvents_Promise
     **/
    private function imapCommand ($commandName, array $commandArguments = null, callable $continuationCallback = null) : qcEvents_Promise {
      // Create a new promise for this command
      $deferedPromise = new qcEvents_Defered;
      
      // Push command to queue
      $this->pendingCommands [$this->commandSequence++] = array ($deferedPromise, $commandName, $commandArguments, $continuationCallback, array ());
      
      // Try to start pending command
      $this->imapStartPendingCommand ();
      
      // Return the promise
      return $deferedPromise->getPromise ();
    }
    // }}}
    
    // {{{ imapStartPendingCommand
    /**
     * Try to start next command from queue (if there is no active command)
     * 
     * @access private
     * @return void
     **/
    private function imapStartPendingCommand () {
      // Check if there is already an active command
      if ($this->activeCommand !== null)
        return;
      
      // Move to next pending command
      foreach ($this->pendingCommands as $commandSequence=>$commandInfo) {
        $this->activeCommand = $commandSequence;
        
        break;
      }
      
      // Check if there is a next command
      if ($this->activeCommand === null)
        return;
      
      // Prepare to write the command to the wire
      $commandLines = array ($this->pendingCommands [$this->activeCommand][1]);
      
      if ($this->pendingCommands [$this->activeCommand][2] !== null) {
        $argumentLines = $this->imapEncodeArguments ($this->pendingCommands [$this->activeCommand][2]);
        
        foreach ($argumentLines as $lineIndex=>$argumentLine)
          if ($lineIndex == 0)
            $commandLines [0] .= $argumentLine;
          else
            $commandLines [] = ltrim ($argumentLine, ' ');
      }
      
      // Check wheter to override the continuation-handler
      if (count ($commandLines) > 1) {
        $continuationHandler = $this->pendingCommands [$this->activeCommand][3];
        
        $this->pendingCommands [$this->activeCommand][3] =
          function () use ($continuationHandler, &$commandLines) {
            // Check wheter to restore the original continuation-handler
            if (count ($commandLines) < 2)
              $this->pendingCommands [$this->activeCommand][3] = $continuationHandler;
            
            // Return the next command-line
            return array_shift ($commandLines);
          };
      }
      
      // Write command to the wire
      $commandLine = array_shift ($commandLines);
      
      return $this->imapWriteToStream (sprintf ('%04X %s' . "\r\n", $this->activeCommand, $commandLine));
    }
    // }}}
    
    // {{{ imapEncodeArguments
    /**
     * Encode command-arguments for submission to the server
     * 
     * @param array $imapArguments
     * 
     * @access private
     * @return array
     **/
    private function imapEncodeArguments (array $imapArguments) : array {
      $argumentLines = array ();
      $argumentLine = '';
      
      foreach ($imapArguments as $imapArgument) {
        if (is_string ($imapArgument) &&
            ((strpos ($imapArgument, "\n") !== false) ||
             (strpos ($imapArgument, "\r") !== false)))
          $imapArgument = new qcEvents_Stream_IMAP_Literal ($imapArgument);
        
        if ($imapArgument instanceof qcEvents_Stream_IMAP_Literal) {
          if (true || !$this->haveCapability ('LITERAL+')) {
            $argumentLines [] = $argumentLine . ' {' . $imapArgument->length () . '}';
            $argumentLine = (string)$imapArgument;
          } else
            $argumentLine .= ' {' . $imapArgument->length () . '+}' . "\r\n" . (string)$imapArgument;
          
        } elseif (is_array ($imapArgument)) {
          $subArguments = static::imapEncodeArguments ($imapArgument);
          $argumentLine .= ' (';
          
          foreach ($subArguments as $lineIndex=>$subLine) {
            $argumentLine .= ltrim ($subLine) . ' ';
            
            if ($lineIndex < count ($subArguments) - 1) {
              $argumentLines [] = rtrim ($argumentLine);
              $argumentLine = '';
            }
          }
          
          $argumentLine = rtrim ($argumentLine) . ')';
        } elseif (preg_match ('/^[.\\\d\w=-]+$/', $imapArgument) > 0)
          $argumentLine .= ' ' . $imapArgument;
        else
          $argumentLine .= ' "' . str_replace (array ('"', '\\'), array ('\\"', '\\\\'), $imapArgument) . '"';
      }
      
      $argumentLines [] = $argumentLine;
      
      return $argumentLines;
    }
    // }}}
    
    // {{{ imapDecodeArguments
    /**
     * Parse a string into IMAP-Arguments
     * 
     * @param string $imapArguments
     * 
     * @access private
     * @return array
     **/
    private static function imapDecodeArguments ($imapArguments) {
      $decodedArguments = array ();
      $argumentStack = array ();
      
      while (($l = strlen ($imapArguments)) > 0) {
        // Check for a quoted string
        if (($imapArguments [0] == '"') || ($imapArguments [0] == "'")) {
          if (($p = strpos ($imapArguments, $imapArguments [0], 1)) !== false) {
            $decodedArguments [] = substr ($imapArguments, 1, $p - 1);
            $imapArguments = ltrim (substr ($imapArguments, $p + 1));
          } else {
            $decodedArguments [] = substr ($imapArguments, 1);
            $imapArguments = '';
          }
        
        // Check for a literal
        } elseif (($imapArguments [0] == '{') && (($p = strpos ($imapArguments, "}\r\n", 1)) !== false)) {
          $literalSize = intval (substr ($imapArguments, 1, $p - 1));
          $decodedArguments [] = substr ($imapArguments, $p + 3, $literalSize);
          $imapArguments = ltrim (substr ($imapArguments, $p + $literalSize + 3));
        
        // Check for beginning of an array
        } elseif ($imapArguments [0] == '(') {
          $argumentStack [] = $decodedArguments;
          $decodedArguments = array ();
          $imapArguments = ltrim (substr ($imapArguments, 1));
        
        // Check for end of an array
        } elseif ($imapArguments [0] == ')') {
          $stackNext = array_pop ($argumentStack);
          $stackNext [] = $decodedArguments;
          $decodedArguments = $stackNext;
          $imapArguments = ltrim (substr ($imapArguments, 1));
          
          unset ($stackNext);
        
        // Handle as simple type
        } else {
          // Find next delimiter
          if (($p = strpos ($imapArguments, ' ')) === false)
            $p = $l;
          
          // Move back to last valid value
          $p--;
          
          if ((($p2 = strpos ($imapArguments, '[')) !== false) &&
              ($p2 < $p) &&
              (($p3 = strpos ($imapArguments, ']', $p2)) !== false))
            $p = max ($p3, $p);
          
          // Check for closed arrays
          if ((($p2 = strpos ($imapArguments, ')')) !== false) && ($p2 < $p))
            $p = $p2 - 1;
          
          while ($imapArguments [$p] == ')')
            $p--;
          
          if (($argumentValue = substr ($imapArguments, 0, $p + 1)) == 'NIL')
            $argumentValue = null;
          
          $decodedArguments [] = $argumentValue;
          $imapArguments = ltrim (substr ($imapArguments, $p + 1));
        }
      }
      
      // Roll stack back (should never be used)
      while (count ($argumentStack) > 0) {
        $stackNext = array_pop ($argumentStack);
        $stackNext [] = $decodedArguments;
        $decodedArguments = $stackNext;
      }
      
      return $decodedArguments;
    }
    // }}}
    
    // {{{ imapEncodeSequence
    /**
     * Create an IMAP-Sequence from a given valud
     * 
     * @param mixed $Sequence
     * 
     * @access private
     * @return string
     **/
    private function imapEncodeSequence ($imapSequence) {
      // Return the Sequence as-is if not an array
      if (!is_array ($imapSequence))
        return $imapSequence;
      
      // Convert an array into a string
      $finalSequence = '';
      
      foreach ($imapSequence as $sequenceValue)
        if (is_array ($sequenceValue)) {
          // Check for a range
          if (count ($sequenceValue) == 2) {
            $sequenceStart = array_shift ($sequenceValue);
            $sequenceEnd = array_shift ($sequenceValue);
            
            $finalSequence .= ($sequenceStart == '*' ? $sequenceStart : (int)$sequenceStart) . ':' . ($sequenceEnd == '*' ? $sequenceEnd : (int)$sequenceEnd) . ',';
          } else
            foreach ($sequenceValue as $sequenceItem)
              $finalSequence .= ($sequenceItem == '*' ? $sequenceItem : (int)$sequenceItem) . ',';
        
        // Process a single sequence-number
        } else
          $finalSequence .= ($sequenceValue == '*' ? $sequenceValue : (int)$sequenceValue) . ',';
      
      return substr ($finalSequence, 0, -1);
    }
    // }}}
    
    // {{{ imapCheckSearchKey
    /**
     * Validate a given search-key
     * 
     * @param string $searchKey
     * 
     * @access private
     * @return bool
     **/
    private function imapCheckSearchKey ($searchKey) {
      // Check for a single-value
      if (in_array ($searchKey, array ('ALL', 'NEW', 'OLD', 'SEEN', 'DRAFT', 'RECENT', 'UNSEEN', 'DELETED', 'FLAGGED', 'UNDRAFT', 'UNDELETED', 'UNFLAGGED', 'UNANSWERED')))
        return true;
      
      // Try to parse the key
      if (!($searchParameters = $this->imapDecodeArguments ($searchKey)) || (count ($searchParameters) < 2))
        return false;
      
      // Check by keyword
      switch ($searchParameters [0]) {
        // Second arguement must be a number
        case 'LARGER':
        case 'SMALLER':
          return ((count ($searchParameters) == 2) && is_numeric ($searchParameters [1]));
        
        // Simple strings
        case 'CC':
        case 'BCC':
        case 'FROM':
        case 'BODY':
        case 'SUBJECT':
          return ((count ($searchParameters) == 2) && (strlen ($searchParameters [1]) > 0));
        
        case 'HEADER':
          return ((count ($searchParameters) == 3) && (strlen ($searchParameters [1]) > 0) && (strlen ($searchParameters [2]) > 0));
          
        // Dates
        case 'ON':
        case 'SINCE':
        case 'BEFORE':
        case 'SENTON':
        case 'SENTSINCE':
        case 'SENTBEFORE':
          return ((count ($searchParameters) == 2) && (strlen ($searchParameters [1]) > 7));
        
        // Flags
        case 'KEYWORD':
        case 'UNKEYWORD':
          return ((count ($searchParameters) == 2) && (strlen ($searchParameters [1]) > 0));
        
        // Sequences
        case 'UID':
          return ((count ($searchParameters) == 2) && (strlen ($searchParameters [1]) > 0));
        
        // Subkeys
        case 'NOT':
          return $this->imapCheckSearchKey (substr ($Key, 4));
        
        // Conjunction of two keys
        case 'OR':
          return (
            (count ($searchParameters) == 3) &&
            $this->imapCheckSearchKey ($searchParameters [1]) &&
            $this->imapCheckSearchKey ($searchParameters [2])
          );
      }
      
      return false;
    }
    // }}}
    
    
    // {{{ imapWriteToStream
    /**
     * Write a message to our server
     * 
     * @param string $writeData
     * 
     * @access private
     * @return void
     **/
    private function imapWriteToStream ($writeData) {
      // Raise a callback for this
      if ($this->___callback ('imapWrite', $writeData) === false)
        return false;
      
      // Push to the wire
      return $this->sourceStream->write ($writeData);
    }
    // }}}
    
    // {{{ imapSetState
    /**
     * Change the IMAP-Protocol-State
     * 
     * @param enum $newState
     * 
     * @access private
     * @return enum New state, NULL if state wasn't changed
     **/
    private function imapSetState ($newState) {
      // Check if anything is to be changed
      if ($newState == $this->imapState)
        return null;
      
      // Change the status
      $oldState = $this->imapState;
      $this->imapState = $newState;
      
      // Fire callback
      $this->___callback ('imapStateChanged', $newState, $oldState);
      
      return $newState;
    }
    // }}}
    
    
    // {{{ eventClosed
    /**
     * Callback: Stream was closed
     * 
     * @access protected
     * @return void
     **/
    protected function eventClosed () { }
    // }}}
    
    // {{{ eventPipedStream
    /**
     * Callback: A stream was attached to this consumer
     * 
     * @param qcEvents_Interface_Stream $sourceStream
     * 
     * @access protected
     * @return void
     **/
    protected function eventPipedStream (qcEvents_Interface_Stream $sourceStream) { }
    // }}}
    
    // {{{ imapStateChanged
    /**
     * Callback: Our protocol-state was changed
     * 
     * @param enum $newState
     * @param enum $oldState
     * 
     * @access protected
     * @return void
     **/
    protected function imapStateChanged ($newState, $oldState) { }
    // }}}
    
    // {{{ imapConnected
    /**
     * Callback: IMAP-Connection was established, Client is in Connected-State
     * 
     * @access protected
     * @return void
     **/
    protected function imapConnected () { }
    // }}}
    
    // {{{ imapRead
    /**
     * Callback: A decoded line was received from the server
     * 
     * @param string $lineTag
     * @param string $lineStatus
     * @param string $lineText
     * 
     * @access protected
     * @return bool
     **/
    protected function imapRead ($lineTag, $lineStatus, $lineText) { }
    // }}}
    
    // {{{ imapWrite
    /**
     * Callback: About to write data to the server
     * 
     * @param string $writeLine
     * 
     * @access protected
     * @return bool
     **/
    protected function imapWrite ($lineData) { }
    // }}}
    
    // {{{ imapAuthenticated
    /**
     * Callback: IMAP-Connection was successfully authenticated
     * 
     * @access protected
     * @return void
     **/
    protected function imapAuthenticated () { }
    // }}}
    
    // {{{ imapAuthenticationFailed
    /**
     * Callback: Authentication failed
     * 
     * @access protected
     * @return void
     **/
    protected function imapAuthenticationFailed () { }
    // }}}
    
    // {{{ imapDisconnected
    /**
     * Callback: IMAP-Connection was closed, Client is in Disconnected-State
     * 
     * @access protected
     * @return void
     **/
    protected function imapDisconnected () { }
    // }}}
    
    // {{{ imapCapabilities
    /**
     * Callback: Capabilities of server were updated
     * 
     * @param array $Capabilities
     * 
     * @access protected
     * @return void
     **/
    protected function imapCapabilities ($Capabilities) { }
    // }}}
    
    // {{{ imapAlert
    /**
     * Callback: An Alert was received over the wire
     * 
     * @param string $Message
     * 
     * @access protected
     * @return void
     **/
    protected function imapAlert ($Message) { }
    // }}}
    
    // {{{ imapMailboxOpened
    /**
     * Callback: Mailbox was selected
     * 
     * @param string $Mailbox Name of the mailbox
     * @param bool $Writeable Mailbox is writeable
     * 
     * @access protected
     * @return void
     **/
    protected function imapMailboxOpened ($Mailbox, $Writeable) { }
    // }}}
    
    // {{{ imapMailboxOpenFailed
    /**
     * Callback: Select of mailbox failed
     * 
     * @param string $Mailbox
     * 
     * @access protected
     * @return void
     **/
    protected function imapMailboxOpenFailed ($Mailbox) { }
    // }}}
    
    // {{{ imapMailboxClosed
    /**
     * Callback: Selected Mailbox was closed
     * 
     * @param qcEvents_Stream_IMAP_Mailbox $Mailbox
     * 
     * @access protected
     * @return void
     **/
    protected function imapMailboxClosed (qcEvents_Stream_IMAP_Mailbox $Mailbox) { }
    // }}}
    
    // {{{ imapMailboxCloseFailed
    /**
     * Callback: Mailbox could not be closed
     * 
     * @param qcEvents_Stream_IMAP_Mailbox $Mailbox
     * 
     * @access protected
     * @return void
     **/
    protected function imapMailboxCloseFailed (qcEvents_Stream_IMAP_Mailbox $Mailbox) { }
    // }}}
  }

?>