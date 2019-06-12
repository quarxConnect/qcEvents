<?PHP

  /**
   * qcEvents - Asyncronous IMAP Client
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
  
  require_once ('qcEvents/Socket/Client.php');
  require_once ('qcMail/Headers.php');
  
  /**
   * IMAPv4r1 Client
   * ---------------
   * 
   * @changelog 20130621 Added Support for RFC 4959 IMAP SASL-IR
   *            20130621 Added Support for RFC 3691 IMAP UNSELECT
   *            20130621 Added Support for RFC 2342 IMAP NAMESPACE
   *            20130624 Completed support for RFC 3501 IMAP4r1
   *            20130624 Added Support for RFC 2088 Literal+
   *            20130722 Added basic Support for RFC 3348 Mailbox-Children
   * 
   * @todo RFC 2359 UIDPLUS
   *       RFC 5256 SORT/THREAD
   *       RFC 2177 IDLE
   *       RFC 4978 COMPRESS
   *       RFC 3502 MULTIAPPEND
   *       RFC 4469 CATENATE
   *       RFC 4314 ACLs
   *       RFC 2087 Quota
   *       RFC 5465 NOTIFY
   *       RFC 5464 METADATA
   *       RFC 6855 UTF-8
   **/
  class qcEvents_Socket_Client_IMAP extends qcEvents_Socket_Client {
    /* Defaults for IMAP */
    const DEFAULT_PORT = 143;
    const DEFAULT_TYPE = qcEvents_Socket::TYPE_TCP;
    
    /* Defaults for this client-protocol */
    const USE_LINE_BUFFER = true;
    
    /* Connection-states */
    const IMAP_STATE_CONNECTING = 1; # Set by socketConnected-Callback
    const IMAP_STATE_CONNECTED = 2; # Set by receivedLine if connecting
    const IMAP_STATE_AUTHENTICATED = 3; # Set by imapAfterAuthentication
    const IMAP_STATE_ONMAILBOX = 4;
    const IMAP_STATE_DISCONNECTING = 5; # Set by logout
    const IMAP_STATE_DISCONNECTED = 0; # Set by socketDisconnected
    
    /* Response-status */
    const IMAP_STATUS_OK = 'OK';
    const IMAP_STATUS_NO = 'NO';
    const IMAP_STATUS_BAD = 'BAD';
    const IMAP_STATUS_BYE = 'BYE';
    const IMAP_STATUS_PREAUTH = 'PREAUTH';
    
    /* Set/Store-Modes */
    const MODE_SET = 0;
    const MODE_ADD = 1;
    const MODE_DEL = 2;
    
    /* Our current protocol-state */
    private $State = qcEvents_Socket_Client_IMAP::IMAP_STATE_DISCONNECTED;
    
    /* IMAP-Greeting was received */
    private $imapGreeting = false;
    
    /* Command-Counter */
    private $Commands = 0;
    
    /* Pipe for commands waiting for execution */
    private $commandPipe = array ();
    
    /* Buffered reads for literal */
    private $literalBuffer = '';
    private $literalLength = null;
    private $commandBuffer = '';
    
    /* Registered callbacks for commands issued */
    private $Callbacks = array ();
    
    /* Capabilities of our IMAP-Server */
    private $serverCapabilities = array ();
    
    /* Callback for TLS-Negotiation */
    private $imapTLSCallback = null;
    
    /* Namespaces on this server */
    private $impNamespaces = null;
    
    /* Status-Cache for Mailboxes */
    private $imapMailboxes = array ();
    
    /* Current Mailbox */
    private $imapMailbox = null;
    
    /* Predicted next mailbox */
    private $imapMailboxNext = null;
    
    /* Read-Only-Status of current mailbox */
    private $mailboxReadOnly = false;
    
    /* Message-Information */
    private $messages = array ();
    
    /* Sequence-to-UID mappings */
    private $messageIDs = array ();
    
    /* Message-IDs of last search */
    private $searchResult = array ();
    
    // {{{ imapIsConnected
    /**
     * Check if this IMAP-Connection is at least in connected state
     * 
     * @access public
     * @return bool
     **/
    public function imapIsConnected () {
      return (($this->State == self::IMAP_STATE_CONNECTED) || ($this->State == self::IMAP_STATE_AUTHENTICATED) || ($this->State == self::IMAP_STATE_ONMAILBOX));
    }
    // }}}
    
    // {{{ imapIsAuthenticated
    /**
     * Check if this IMAP-Connection is at least in authenticated state
     * 
     * @access public
     * @return bool
     **/
    public function imapIsAuthenticated () {
      return (($this->State == self::IMAP_STATE_AUTHENTICATED) || ($this->State == self::IMAP_STATE_ONMAILBOX));
    }
    // }}}
    
    // {{{ socketReceive
    /**
     * Internal Callback: Data is received over the wire
     * Hook into this function to handle pending literals
     * 
     * @param string $Data
     * 
     * @access protected
     * @return void
     **/
    protected final function socketReceive ($Data) {
      // Check if we are expecting a literal
      if ($this->literalLength !== null) {
        $remaining = $this->literalLength - strlen ($this->literalBuffer);
        $l = strlen ($Data);
        
        // Check if there is still data pending
        if ($l < $remaining) {
          $this->literalBuffer .= $Data;
          
          return;
        
        // Check for an exact buffer-fill
        } elseif ($l == $remaining) {
          $this->commandBuffer .= $this->literalBuffer . $Data;
          $this->literalBuffer = '';
          $this->literalLength = null;
          
          return;
        
        // Check if there is more data available than expected
        } else {
          if ($remaining > 0) {
            $this->commandBuffer .= $this->literalBuffer . substr ($Data, 0, $remaining);
            $Data = substr ($Data, $remaining);
          }
          
          $this->literalBuffer = '';
          $this->literalLength = null;
        }
      }
      
      // Inherit to our parent function
      return parent::socketReceive ($Data);
    }
    // }}}
    
    // {{{ receivedLine
    /**
     * A single IMAP-Line was received
     * 
     * @param string $Line
     * 
     * @access protected
     * @return void
     **/
    protected final function receivedLine ($Line) {
      // Make sure there is any data on the line
      if (strlen ($Line) == 0)
        return;
      
      // Check if a literal is expected
      if ((substr ($Line, -1, 1) == '}') && (($p = strrpos ($Line, '{')) !== false)) {
        $this->literalLength = intval (substr ($Line, $p + 1, -1));
        $this->commandBuffer .= $Line . "\r\n";
        
        // Flush any buffered data back to our handler
        if (strlen ($Buffer = $this->getLineBufferClean ()) > 0)
          $this->socketReceive ($Buffer);
        
        return;
      }
      
      // Flush any buffered command
      $Line = $this->commandBuffer . $Line;
      $this->commandBuffer = '';
      
      // Check for a continuation-command
      if ($Line [0] == '+') {
        // Find the next command-callback with continuation-callback
        foreach ($this->Callbacks as $ID=>$Info)
          if ($Info [2] !== null) {
            $rc = call_user_func ($Info [2], $ID, ltrim (substr ($Line, 1)), $Info [3]);
            
            if ($rc === null)
              continue;
            
            $this->write ($rc . "\r\n");
            
            return;
          }
        
        return;
      }
      
      // Extract the tag
      if (($p = strpos ($Line, ' ')) === false)
        return false;
      
      $Tag = substr ($Line, 0, $p);
      $Line = ltrim (substr ($Line, $p + 1));
      
      // Extract the response
      if (($p = strpos ($Line, ' ')) === false)
        $p = strlen ($Line);
      
      $Response = substr ($Line, 0, $p);
      $Line = ltrim (substr ($Line, $p + 1));
      
      // Check for an additional response-code
      $Code = array ();
      
      if ((strlen ($Line) > 0) && ($Line [0] == '[') && (($p = strpos ($Line, ']', 1)) !== false)) {
        $Code = explode (' ', substr ($Line, 1, $p - 1));
        $Line = ltrim (substr ($Line, $p + 1));
        
        $this->receivedCode ($Code, $Line);
      }
      
      // Handle command based on state
      if ($this->State == self::IMAP_STATE_CONNECTING) {
        // Check if a greeting was already received
        if ($this->imapGreeting !== false) {
          // Ignore tagged responses
          if ($Tag == '*')
            return $this->receivedUntagged ($Response, $Code, $Line);
          
          if ($this->imapGreeting == self::IMAP_STATUS_PREAUTH)
            $this->imapSetState (self::IMAP_STATE_AUTHENTICATED);
          else
            $this->imapSetState (self::IMAP_STATE_CONNECTED);
          
        // Connection is OK, we are in connected state
        } elseif ($Response == self::IMAP_STATUS_OK)
          $Callback = 'imapConnected';
        
        // Connection is very good, we are already authenticated
        elseif ($Response == self::IMAP_STATUS_PREAUTH)
          $Callback = 'imapAuthenticated';
        
        // Something is wrong with us or the server, we will be disconnected soon
        elseif ($Response == self::IMAP_STATUS_BYE) {
          $this->imapSetState (self::IMAP_STATE_DISCONNECTED);
          
          return $this->disconnect ();
        
        // RFC-Violation, leave immediatly
        } else {
          trigger_error ('Wrong response received: ' . $Response);
          
          return $this->disconnect ();
        }
        
        if ($this->imapGreeting === false) {
          // Store the greeting-response
          $this->imapGreeting = $Response;
          
          // Check wheter to ask for capabilities
          if (count ($this->serverCapabilities) == 0)
            return $this->imapCommand ('CAPABILITY', null, array ($this, 'imapCallback'), $Callback);
          
          // Chnge the state directly
          if ($this->imapGreeting == self::IMAP_STATUS_PREAUTH)
            $this->imapSetState (self::IMAP_STATE_AUTHENTICATED);
          else
            $this->imapSetState (self::IMAP_STATE_CONNECTED);
          
          return $this->___callback ($Callback);
        }
      }
      
      // Check for an untagged response
      if ($Tag == '*')
        return $this->receivedUntagged ($Response, $Code, $Line);
      
      // Check if we have callback for this
      if ($Tag [0] != 'C') {
        trigger_error ('Received response for a strange command ' . $Tag, E_USER_WARNING);
        
        return false;
      }
      
      $ID = hexdec (substr ($Tag, 1));
      
      if (!isset ($this->Callbacks [$ID])) {
        trigger_error ('Received response for unknown command ' . $Tag, E_USER_NOTICE);
        
        return false;
      }
      
      switch ($Response) {
        case self::IMAP_STATUS_OK:
        case self::IMAP_STATUS_NO:
        case self::IMAP_STATUS_BAD:
          // Retrive Callback-Information
          $Info = $this->Callbacks [$ID];
          unset ($this->Callbacks [$ID]);
          
          // Check if there is a real callback registered
          if ($Info [0] === null)
            break;
          
          // Fire the callback
          if (!is_array ($Info [0]) || ($Info [0][0] !== $this))
            call_user_func ($Info [0], $this, $Response, $Code, $Line, $Info [1]);
          else
            call_user_func ($Info [0], $Response, $Code, $Line, $Info [1]);
          
          break;
        
        // An invalid response-status was received, close connection to that buggy server
        default:
          trigger_error ('Invalid response-status ' . $Response . ' for ' . $Tag, E_USER_WARNING);
          $this->disconnect ();
      }
    }
    // }}}
    
    // {{{ receivedCode
    /**
     * Special function to handle received codes
     * 
     * @param array $Code
     * @param string $Text
     * 
     * @access private
     * @return void
     **/
    private function receivedCode ($Code, $Text) {
      // Make sure the code is valid
      if (count ($Code) < 1)
        return;
      
      switch ($Code [0]) {
        // An alert was raised
        case 'ALERT':
        case 'PARSE': // This is not really an Alert but indicates an error on server-side
          $this->___callback ('imapAlert', $Text);
          break;
        
        // Server includes capabilities in its greeting, fake an CAPABILITY-Command
        case 'CAPABILITY':
          $this->receivedUntagged ('CAPABILITY', array (), $Text);
          break;
        
        # Other:
        #  PERMANENTFLAGS / UIDVALIDITY / UIDNEXT / UNSEEN - Handled in receivedUntagged
        #  READ-ONLY / READ-WRITE - Handled by SELECT/EXAMINE-Handler
        #  TRYCREATE
      }
    }
    // }}}
    
    // {{{ receivedUntagged
    /**
     * Receive an untagged response from server
     * 
     * @param string $Response
     * @param string $Line
     * 
     * @access private
     * @return void
     **/
    private function receivedUntagged ($Response, $Code, $Text) {
      // Ugly hack for EXISTS, RECENT, FETCH & EXPUNGE
      if (is_numeric ($Response)) {
        $t = $Response;
        
        if (($p = strpos ($Text, ' ')) !== false) {
          $Response = substr ($Text, 0, $p);
          $Text = $t . substr ($Text, $p);
        } else {
          $Response = $Text;
          $Text = $t;
        }
        
        unset ($t);
      }
      
      // Handle the response
      switch ($Response) {
        // Update Capabilities of our server
        case 'CAPABILITY':
          $this->serverCapabilities = explode (' ', $Text);
          $this->___callback ('imapCapabilities', $this->serverCapabilities);
          
          break;
        
        // About to disconnect
        case 'BYE':
          // Ignore this if we are disconnecting
          if ($this->State == self::IMAP_STATE_DISCONNECTING)
            break;
          
          // Raise an alert
          $this->___callback ('imapAlert', $Text);
          
          break;
        
        // Update Mailbox-Status
        case 'STATUS':
          // Parse the result
          $Result = $this->imapParseArgs ($Text);
          
          // Retrive a handle for this mailbox
          $Handle = $this->imapCreateLocalMailbox ($Result [0]);
          
          // Merge the status
          while (count ($Result [1]) > 1) {
            $Property = array_shift ($Result [1]);
            $Handle->Status [$Property] = array_shift ($Result [1]);
          }
          
          break;
        
        // LIST/LSUB-Information
        case 'LIST':
        case 'LSUB':
          // Parse the result
          $Result = $this->imapParseArgs ($Text);
          
          // Retrive a handle for this mailbox
          $Handle = $this->imapCreateLocalMailbox ($Result [2]);
          
          // Update the handle
          $Handle->Delimiter = $Result [1];
          
          if (count ($Handle->Attributes == 0) || ($Response == 'LIST'))
            # TODO: On LSUB we may have a \\NoSelect here, that only affects the subscription-status
            $Handle->Attributes = $Result [0];
          
          if (($Response == 'LSUB') && !in_array ('\\NoSelect', $Result [0]))
            $Handle->Subscribed = true;
          
          break;
        
        // Namespace-Info
        case 'NAMESPACE':
          // Parse the result
          $Result = $this->imapParseArgs ($Text);
          
          foreach ($Result as $Type=>$Namespaces) {
            if (!is_array ($Namespaces))
              continue;
            
            foreach ($Namespaces as $Info)
              $this->imapNamespaces [$Info [0]] = array ($Info [1], $Type);
          }
          
          break;
        
        // Handle Search-Results
        case 'SEARCH':
          $this->searchResult = $this->imapParseArgs ($Text);
          break;
        
        // Mailbox-Flags
        case 'FLAGS':
          if ($this->imapMailboxNext !== null)
            $Mailbox = $this->getMailbox ($this->imapMailboxNext);
          elseif ($this->imapMailbox !== null)
            $Mailbox = $this->getMailbox ($this->imapMailbox);
          else
            break;
     
          if ($Mailbox) {
            $Args = $this->imapParseArgs ($Text);
            $Mailbox->Flags = array_shift ($Args);
          }
          
          break;
        
        // Number of mails in current mailbox
        case 'EXISTS':
          if ($this->imapMailboxNext !== null)
            $Mailbox = $this->getMailbox ($this->imapMailboxNext);
          elseif ($this->imapMailbox !== null)
            $Mailbox = $this->getMailbox ($this->imapMailbox);
          else
            break;
          
          if ($Mailbox)
            $Mailbox->MessageCount = intval ($Text);
          
          break;
        
        // Number of recent mails in current mailbox
        case 'RECENT':
          if (($this->imapMailbox !== null) && ($Mailbox = $this->getMailbox ($this->imapMailbox)))
            $Mailbox->RecentCount = intval ($Text);
          
          break;
        
        // A message was removed from mailbox
        case 'EXPUNGE':
          // Decrease the counter
          if (($this->imapMailbox !== null) && ($Mailbox = $this->getMailbox ($this->imapMailbox)))
            $Mailbox->MessageCount--;
          
          // Retrive Sequence-number of the removed message
          $ID = intval ($Text);
          
          // Remove this message from our local storage
          if (isset ($this->messageIDs [$ID])) {
            if (isset ($this->messageIDs [$ID]) && ($this->messageIDs [$ID] !== null))
              unset ($this->messages [$this->messageIDs [$ID]]);
            
            array_splice ($this->messageIDs, $ID, 1);
          }
          
          break;
        // Message-Data was fetched
        case 'FETCH':
          // Parse the arguements of this response
          if (!($Args = $this->imapParseArgs ($Text)))
            return;
          
          // Retrive the Sequence-ID
          $SequenceID = $Args [0];
          $Args = $Args [1];
          
          // Find UID of this message
          if (($ID = array_search ('UID', $Args)) !== false)
            $UID = $Args [$ID + 1];
          elseif (isset ($this->messageIDs [$SequenceID]))
            $UID = $this->messageIDs [$SequenceID];
          else {
            trigger_error ('Received FETCH-Response without UID', E_USER_WARNING);
            break;
          }
          
          // Make sure the message exists
          if (!isset ($this->messages [$UID]))
            $this->messages [$UID] = array (
              'UID' => $UID,
              'Headers' => new qcMail_Headers,
              'HeadersComplete' => false,
              'Structure' => null,
              'Envelope' => null,
              'Flags' => null,
              'Internaldate' => null,
              'Size' => null,
              'Body' => null,
              'Parts' => array (),
            );
          
          if (!isset ($this->messageIDs [$SequenceID])) {
            // Extend message-ID-Mapping to the right size
            for ($i = count ($this->messageIDs); $i < $SequenceID; $i++)
              $this->messageIDs [$i] = null;
            
            // Map the UID
            $this->messageIDs [$i] = $UID;
          }
          
          // Update Message-Information
          $l = count ($Args);
          
          for ($i = 0; $i < $l; $i += 2)
            switch ($Args [$i]) {
              // Update flags of this message
              case 'FLAGS':
                $this->messages [$UID]['Flags'] = $Args [$i + 1];
                
                break;
              // Update envelope-information of this message
              case 'ENVELOPE':
                $j = $i + 1;
                
                $this->messages [$UID]['Envelope'] = array (
                  'date' => strtotime ($Args [$j][0]),
                  'subject' => $Args [$j][1],
                  'from' => $Args [$j][2],
                  'sender' => $Args [$j][3],
                  'reply-to' => $Args [$j][4],
                  'to' => $Args [$j][5],
                  'cc' => $Args [$j][6],
                  'bcc' => $Args [$j][7],
                  'in-reply-to' => $Args [$j][8],
                  'message-id' => $Args [$j][9],
                );
                
                break;
              // Update the internal date of this message
              case 'INTERNALDATE':
                $this->messages [$UID]['Internaldate'] = strtotime ($Args [$i + 1]);
                
                break;
              // Update the size of this message
              case 'RFC822.SIZE':
                $this->messages [$UID]['Size'] = intval ($Args [$i + 1]);
                
                break;
              // Update the structure of this message
              case 'BODY':
              case 'BODYSTRUCTURE':
                $this->messages [$UID]['Structure'] = $Args [$i + 1];
                
                break;
              // Entire message was received
              case 'BODY[]':
              case 'BODY.PEEK[]':
              case 'RFC822':
                $n = $i + 1;
                
                // Find delimiter between header and body
                if (($p = strpos ($Args [$n], "\r\n\r\n")) === false)
                  continue;
                
                // Enqueue header and body as seperated items
                $Args [$l++] = 'RFC822.HEADER';
                $Args [$l++] = substr ($Args [$n], 0, $p);
                $Args [$l++] = 'RFC822.TEXT';
                $Args [$l++] = substr ($Args [$n], $p + 4);
                $Args [$n] = '';
                
                break;
              // Entire header was received
              case 'BODY[HEADER]':
              case 'BODY.PEEK[HEADER]':
              case 'RFC822.HEADER':
                $this->messages [$UID]['Headers'] = new qcMail_Headers ($Args [$i + 1]);
                $this->messages [$UID]['HeadersComplete'] = true;
                
                break;
              // Entire body was received
              case 'BODY[TEXT]':
              case 'BODY.PEEK[TEXT]':
              case 'RFC822.TEXT':
                $this->messages [$UID]['Body'] = $Args [$i + 1];
              
                break;
              // Check if a message-section was received
              default:
                // Make sure here is a body-part returned
                if ((substr ($Args [$i], 0, 5) != 'BODY[') &&
                    (substr ($Args [$i], 0, 10) != 'BODY.PEEK['))
                  continue;
                
                // Parse the section-description
                # TODO: We don't handle partial GETs here
                $Section = $this->imapParseArgs (substr ($Args [$i], ($Args [$i][5] == '.' ? 10 : 5), -1));
                
                switch ($Section [0]) {
                  // Specific fields from Message-Header
                  case 'HEADER.FIELDS':
                  // Message-Header without a set of fields
                  case 'HEADER.FIELDS.NOT':
                    // Ignore this if headers are complete
                    if (!$this->messages [$UID]['HeadersComplete'])
                      $this->messages [$UID]['Headers']->addHeadersFromBlob ($Args [$i + 1], true);
                    
                    break;
                  // Message-Part
                  default:
                    // Handle the message-path-path
                    $Path = explode ('.', $Section [0]);
                    $Last = count ($Path) - 1;
                    
                    // Check for an entire RFC822-Mail(part)
                    if (!is_numeric ($Path [$Last])) {
                      $Type = '';
                      
                      while (!is_numeric ($Path [$Last])) {
                        $Type = $Path [$Last] . (strlen ($Type) > 0 ? '.' . $Type : '');
                        unset ($Path [$Last--]);
                      }
                    } else
                      $Type = 'TEXT';
                    
                    // Rollback the path into a string
                    $Path = implode ('.', $Path);
                    
                    // Make sure the part exists
                    if (!isset ($this->messages [$UID]['Parts'][$Path]))
                      $this->messages [$UID]['Parts'][$Path] = array (
                        'Headers' => new qcMail_Headers,
                        'HeadersComplete' => false,
                        'Body' => null,
                      );
                    
                    switch ($Type) {
                      // Entire content of this part
                      case 'RFC822':
                        $n = $i + 1;
                        
                        // Find delimiter between header and body
                        if (($p = strpos ($Args [$n], "\r\n\r\n")) === false)
                          break;
                        
                        $this->messages [$UID]['Parts'][$Path]['Headers'] = new qcMail_Headers (substr ($Args [$n], 0, $p));
                        $this->messages [$UID]['Parts'][$Path]['HeadersComplete'] = true;
                        $this->messages [$UID]['Parts'][$Path]['Body'] = substr ($Args [$n], $p + 4);
                        $Args [$n] = '';
                        
                        break;
                      // Append partial headers to this part
                      case 'HEADER.FIELDS':
                      case 'HEADER.FIELDS.NOT':
                      case 'MIME':
                        if (!$this->messages [$UID]['Parts'][$Path]['HeadersComplete'])
                          $this->messages [$UID]['Parts'][$Path]['Headers']->addHeadersFromBlob ($Args [$i + 1], true);
                        
                        break;
                      // Set the entire headers of this part
                      case 'HEADER':
                        $this->messages [$UID]['Parts'][$Path]['Headers'] = new qcMail_Headers ($Args [$i + 1]);
                        $this->messages [$UID]['Parts'][$Path]['HeadersComplete'] = true;
                        
                        break;
                      // Set the body of this part
                      case 'TEXT':
                        $this->messages [$UID]['Parts'][$Path]['Body'] = $Args [$i + 1];
                        
                        break;
                    }
                }
            }
          
          break;
        
        // Special codes in OK-Response
        case 'OK':
          // Check if there is a code given
          if (count ($Code) < 2)
            return;
          
          if ($this->imapMailboxNext !== null)
            $Mailbox = $this->getMailbox ($this->imapMailboxNext);
          elseif ($this->imapMailbox !== null)
            $Mailbox = $this->getMailbox ($this->imapMailbox);
          else
            break;
            
          if (!$Mailbox)
            break;
          
          switch ($Code [0]) {
            // List of flags that are stored permanent
            case 'PERMANENTFLAGS':
              $Args = $this->imapParseArgs ($Code [1]);
              $Mailbox->PermanentFlags = array_shift ($Args);
              break;
            
            // UID-Validity-Value of a mailbox
            case 'UIDVALIDITY':
              $Mailbox->UIDValidity = intval ($Code [1]);
              break;
            
            // Predicted next UID-Value
            case 'UIDNEXT':
              $Mailbox->UIDNext = intval ($Code [1]);
              break;
            
            // Number of unseen messages on this mailbox
            case 'UNSEEN':
              $Mailbox->UnseenCount = intval ($Code [1]);
              break;
          }
          
          break;                              
        
        default:
          echo 'UNHANDLED UNTAGGED: ', $Response, ' / ', implode (' - ', $Code), ' / ', $Text, "\n";
      }
    }
    // }}}
    
    // {{{ imapCreateLocalFolder
    /**
     * Make sure we have a given folder on our local storage
     * 
     * @param string $Path
     * 
     * @access private
     * @return object
     **/
    private function imapCreateLocalMailbox ($Path) {
      // Find a namespace for this
      $Namespace = null;
      
      if (is_array ($this->imapNamespaces)) {
        // Find the longest matching namespace
        $l = 0;
        
        foreach ($this->imapNamespaces as $cNamespace=>$Info) {
          $ln = strlen ($cNamespace);
          
          if (($ln == 0) || ((substr ($Path, 0, $ln) == $cNamespace) && ($ln > $l)))
            $Namespace = $cNamespace;
        }
      }
      
      if (($Namespace !== null) && (($p = strrpos ($Path, $this->imapNamespaces [$Namespace][0])) !== false)) {
        $Name = substr ($Path, $p + 1);
        $pPath = substr ($Path, 0, $p);
      } else {
        $Name = $Path;
        $pPath = '';
      }
      
      // Check if this is a root-folder
      if (strlen ($pPath) == 0) {
        if (!isset ($this->imapMailboxes [$Name])) {
          $this->imapMailboxes [$Name] = $Handle = new stdClass;
          
          $Handle->Path = $Path;
          $Handle->Name = $Name;
          $Handle->Namespace = $Namespace;
          $Handle->Delimiter = ($Namespace !== null ? $this->imapNamespaces [$Namespace][0] : '/');
          $Handle->Subscribed = false;
          $Handle->Attributes = array ();
          $Handle->Flags = array ();
          $Handle->PermanentFlags = array ();
          $Handle->Status = array ();
          $Handle->Children = array ();
          $Handle->Parent = null;
          $Handle->MessageCount = 0;
          $Handle->RecentCount = 0;
          $Handle->UnseenCount = 0;
          $Handle->UIDValidity = 0;
          $Handle->UIDNext = 0;
        }
        
        return $this->imapMailboxes [$Name];
      }
      
      // Try to load the parent folder
      $pHandle = $this->imapCreateLocalMailbox ($pPath);
      
      if (!isset ($pHandle->Children [$Name])) {
        $pHandle->Children [$Name] = $Handle = new stdClass;
        
        $Handle->Path = $Path;
        $Handle->Name = $Name;
        $Handle->Namespace = $Namespace;
        $Handle->Delimiter = ($Namespace !== null ? $this->imapNamespaces [$Namespace][0] : '/');
        $Handle->Subscribed = false;
        $Handle->Attributes = array ();
        $Handle->Flags = array ();
        $Handle->PermanentFlags = array ();
        $Handle->Status = array ();
        $Handle->Children = array ();
        $Handle->Parent = $pHandle;
        $Handle->MessageCount = 0;
        $Handle->RecentCount = 0;
        $Handle->UnseenCount = 0; 
        $Handle->UIDValidity = 0; 
        $Handle->UIDNext = 0;
      }
      
      return $pHandle->Children [$Name];
    }
    // }}}
    
    // {{{ getRootMailboxes
    /**
     * Retrive all mailboxes on the top-level of this server
     * 
     * @access public
     * @return array
     **/
    public function getRootMailboxes () {
      return $this->imapMailboxes;
    }
    // }}}
    
    // {{{ getMailbox
    /**
     * Retrive a mailbox at given Path
     * 
     * @remark This is a local function only, you have to load them via LIST/LSUB before
     * 
     * @param string $Path
     * 
     * @access public
     * @return object
     **/
    public function getMailbox ($Path) {
      $l = strlen ($Path);
      
      // Find a root-mailbox for this
      $rootMailbox = null;
      
      foreach ($this->imapMailboxes as $Name=>$Mailbox) {
        $lM = strlen ($Name);
        
        // Check if the name matches
        if (substr ($Path, 0, $lM) != $Name)
          continue;
        
        // Check for a direct match
        if ($lM == $l)
          return $Mailbox;
        
        // Check the delimiter
        if ($Path [$lM++] != $Mailbox->Delimiter)
          continue;
        
        $rootMailbox = $Mailbox;
        $Path = substr ($Path, $lM);
        $l -= $lM;
        
        break;
      }
      
      if (!$rootMailbox)
        return false;
      
      $Path = explode ($rootMailbox->Delimiter, $Path);
      
      foreach ($Path as $Name)
        if (!isset ($rootMailbox->Children [$Name]))
          return false;
        else
          $rootMailbox = $rootMailbox->Children [$Name];
      
      return $rootMailbox;
    }
    // }}}
    
    // {{{ getMessages
    /**
     * Retrive all messages stored on this client
     * 
     * @access public
     * @return array
     **/
    public function getMessages () {
      return $this->messages;
    }
    // }}}
    
    // {{{ getMessage
    /**
     * Retrive a message stored on this client
     * 
     * @param int $ID
     * @param bool $UID (optional) ID is the UID
     * 
     * @access public
     * @return array
     **/
    public function getMessage ($ID, $UID = false) {
      // Check wheter to translate into an UID
      if (!$UID) {
        if (!isset ($this->messageIDs [$ID]))
          return false;
        
        $ID = $this->messageIDs [$ID];
      }
      
      // Check if the message exists
      if (!isset ($this->messages [$ID]))
        return false;
      
      // Return the message
      return $this->messages [$ID];
    }
    // }}}
    
    // {{{ haveCapabilty
    /**
     * Check if a given capability is supported by the server
     * 
     * @param string $Capability
     * 
     * @access public
     * @return bool
     **/
    public function haveCapability ($Capability) {
      return in_array ($Capability, $this->serverCapabilities);
    }
    // }}}
    
    // {{{ getCapabilities
    /**
     * Request a list of capabilities of this server
     * 
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function getCapabilities ($Callback = null, $Private = null) {
      return $this->imapCommand ('CAPABILITY', null, $Callback, $Private);
    }
    // }}}
    
    // {{{ noOp
    /**
     * Issue an NoOp-Command to keep the connection alive or retrive any pending updates
     * 
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function noOp ($Callback = null, $Private = null) {
      return $this->imapCommand ('NOOP', null, $Callback, $Private);
    }
    // }}}
    
    // {{{ logout
    /**
     * Request a logout from server
     * 
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function logout ($Callback = null, $Private = null) {
      $this->imapSetState (self::IMAP_STATE_DISCONNECTING);
      
      return $this->imapCommand ('LOGOUT', null, $Callback, $Private);
    }
    // }}}
    
    // {{{ startTLS
    /**
     * Enable TLS-encryption on this connection
     * 
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return bool
     **/
    public function startTLS ($Callback = null, $Private = null) {
      // Check if STARTTLS is supported by the server
      if (!$this->haveCapability ('STARTTLS'))
        return false;
      
      // Check if we are in connected state
      if ($this->State !== self::IMAP_STATE_CONNECTED)
        return false;
      
      return $this->imapCommand ('STARTTLS', null, array ($this, 'imapTLSHelper'), array ($Callback, $Private));
    }
    // }}}
    
    // {{{ imapTLSHelper
    /**
     * Internal Callback: Establish TLS-Connection
     * 
     * @param enum $Response
     * @param array $Code
     * @param string $Text
     * @param array $Private
     * 
     * @access private
     * @return void
     **/
    private function imapTLSHelper ($Response, $Code, $Text, $Private) {
      // Check if the server is able to start TLS
      if ($Response != self::IMAP_STATUS_OK) {
        if (($Private [0] !== null) && is_callable ($Private [0]))
          call_user_func ($Private [0], $this, null, $Private [1]);
        
        return $this->___callback ('tlsFailed');
      }
      
      // Start TLS-Negotiation
      $this->imapTLSCallback = $Private;
      $this->tlsEnable (true, array ($this, 'imapTLSReady'));
    }
    // }}}
    
    // {{{ imapTLSReady
    /**
     * Handle a successfull TLS-Connection
     * 
     * @param bool $Status
     * 
     * @access protected
     * @return void
     **/
    protected final function imapTLSReady ($Status) {
      // Get the requested callback
      $Callback = $this->imapTLSCallback;
      $this->imapTLSCallback = null;
      
      // Check if TLS was enabled successfully
      if ($Status === true) {
        $this->serverCapabilities = array ();
        
        return $this->imapCommand ('CAPABILITY', null, array ($this, 'imapCallbackExt'), array ($Callback [0], false, true, $Callback [1]));
      }
      
      // Raise any registered callback
      if (($Callback [0] !== null) && is_callable ($Callback [0]))
        call_user_func ($Callback [0], $this, $Status, $Callback [1]);
    }
    // }}}
    
    // {{{ authenticate
    /**
     * Try to login using AUTHENTICATE
     * 
     * @param string $Username
     * @param string $Password
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function authenticate ($Username, $Password, $Callback = null, $Private = null) {
      // Check if we are in connected state
      if ($this->State !== self::IMAP_STATE_CONNECTED)
        return false;
      
      if (($Callback !== null) && !is_callable ($Callback))
        $Callback = null;
      
      // Create SASL-Client
      require_once ('qcAuth/SASL/Client.php');
      
      $Client = new qcAuth_SASL_Client;
      $Client->setUsername ($Username);
      $Client->setPassword ($Password);
      
      // Start authentication-process
      $this->authenticateHandler (self::IMAP_STATUS_NO, array (), '', array (
        0 => $Client,
        1 => null,
        2 => 0,
        3 => $Client->getMechanisms (),
        4 => $Callback,
        5 => $Private,
      ));
    }
    // }}}
    
    // {{{ authenticateHandler
    /**
     * Internal Callback: Handle ongoing authentication-process
     * 
     * @param enum $Response
     * @param array $Code
     * @param string $Text
     * @param array $Private
     * 
     * @access private
     * @return void
     **/
    private function authenticateHandler ($Response, $Code, $Text, $Private) {
      // Check if authentication was successfull
      if ($Response == self::IMAP_STATUS_OK) {
        // Set new state
        $this->imapSetState (self::IMAP_STATE_AUTHENTICATED);
        
        // Re-Request Capabilties and fire up all callbacks
        return $this->imapCommand ('CAPABILITY', null, array ($this, 'imapAfterAuthentication'), array ($Private [4], $Private [5]));
      }
      
      // Check if there are mechanisms available
      if (count ($Private [3]) == 0) {
        if ($Private [4] !== null)
          call_user_func ($Private [4], $this, false, $Private [5]);
        
        return $this->___callback ('imapAuthenticationFailed');
      }
      
      // Try next authentication-mechanism
      $Private [1] = array_shift ($Private [3]);
      $Private [2] = 0;
      
      $Private [0]->cancel ();
      $Private [0]->setMechanism ($Private [1]);
      
      // Prepare parameters
      $Args = array ($Private [1]);
      
      if ($this->haveCapability ('SASL-IR') && (($Initial = $Private [0]->getInitialResponse ()) !== null))
        $Args [] = base64_encode ($Initial);
      
      // Issue the command
      return $this->imapCommand ('AUTHENTICATE', $Args, array ($this, 'authenticateHandler'), $Private, array ($this, 'authenticateCallback'), $Private);
    }
    // }}}
    
    // {{{ authenticateCallback
    /**
     * Internal Callback: Continuation-Request for AUTHENTICATE
     * 
     * @param int $ID Identifier of the current command
     * @param string $Text Text submitted by the server for this continuation-request
     * @param array $Private Private data for this callback
     * 
     * @access private
     * @return void
     **/
    private function authenticateCallback ($ID, $Text, $Private) {
      // Increase the counter
      $this->Callbacks [$ID][1][2]++;
      $this->Callbacks [$ID][3][2]++;
      
      // Check wheter to send an initial response
      if (($Private [2] == 0) && !$this->haveCapability ('SASL-IR'))
        return base64_encode ($Private [0]->getInitialResponse ());
      
      // Send normal response
      return base64_encode ($Private [0]->getResponse ());
    }
    // }}}
    
    // {{{ login
    /**
     * Login on server using LOGIN
     * 
     * @param string $Username
     * @param string $Password
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function login ($Username, $Password, $Callback = null, $Private = null) {
      // Check if we are in connected state
      if ($this->State !== self::IMAP_STATE_CONNECTED)
        return false;
       
      if (($Callback !== null) && !is_callable ($Callback))
        $Callback = null;
      
      $this->imapCommand ('LOGIN', array ($Username, $Password), array ($this, 'loginHandler'), array ($Callback, $Private));
    }
    // }}}
    
    // {{{ loginHandler
    /**
     * Internal Callback: Handle LOGIN-Response
     * 
     * @param enum $Response
     * @param array $Code
     * @param string $Text
     * @param array $Private
     * 
     * @access private
     * @return void
     **/
    private function loginHandler ($Response, $Code, $Text, $Callback) {
      // Check if authentication was successfull
      if ($Response == self::IMAP_STATUS_OK) {
        // Set new state
        $this->imapSetState (self::IMAP_STATE_AUTHENTICATED);
       
        // Re-Request Capabilties and fire up all callbacks
        return $this->imapCommand ('CAPABILITY', null, array ($this, 'imapAfterAuthentication'), $Callback);
      }
      
      // Handle failure
      if ($Callback [0] !== null)
        call_user_func ($Callback [0], $this, false, $Callback [1]);
      
      return $this->___callback ('imapAuthenticationFailed');
    }
    // }}}
    
    // {{{ imapAfterAuthentication
    /**
     * Internal Callback: Authentication was finished successfully
     * 
     * @param enum $Response
     * @param array $Code
     * @param string $Text
     * @param array $Private
     * 
     * @access private
     * @return void
     **/
    private function imapAfterAuthentication ($Response, $Code, $Text, $Callback) {
      if ($Callback [0] !== null)
        call_user_func ($Callback [0], $this, true, $Callback [1]);
     
      return $this->___callback ('imapAuthenticated');
    }
    // }}}
    
    
    // {{{ createMailbox
    /**
     * Create a new mailbox
     * 
     * @param string $Name
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function createMailbox ($Name, $Callback = null, $Private = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Issue the command
      return $this->imapCommand ('CREATE', array ($Name), array ($this, 'mailboxAction'), array (0, $Callback, $Name, $Private));
    }
    // }}}
   
    // {{{ deleteMailbox
    /**
     * Remove a mailbox from server
     * 
     * @param string $Name
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function deleteMailbox ($Name, $Callback = null, $Private = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Issue the command
      return $this->imapCommand ('DELETE', array ($Name), array ($this, 'mailboxAction'), array (1, $Callback, $Name, $Private));
    }
    // }}}
    
    // {{{ statusMailbox
    /**
     * Retrive the status for a given mailbox
     * 
     * @param string $Mailbox
     * @param array $Statuses (optional)
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function statusMailbox ($Mailbox, $Statuses = null, $Callback = null, $Private = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Check wheter to retrive all statuses for this mailbox
      if (!is_array ($Statuses))
        $Statuses = array (
          'MESSAGES',
          'RECENT',
          'UIDNEXT',
          'UIDVALIDITY',
          'UNSEEN',
        );
      
      return $this->imapCommand ('STATUS', array ($Mailbox, $Statuses), $Callback, $Private);
    }
    // }}}
    
    // {{{ renameMailbox
    /**
     * Rename a mailbox on our server
     * 
     * @param string $Name
     * @param string $newName
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void  
     **/
    public function renameMailbox ($Name, $newName, $Callback = null, $Private = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Issue the command
      return $this->imapCommand ('RENAME', array ($Name, $newName), array ($this, 'mailboxAction'), array (2, $Callback, $Name, $newName, $Private));
    }
    // }}}
    
    // {{{ subscribeMailbox
    /**
     * Subscribe to a mailbox on our server
     * 
     * @param string $Name
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function subscribeMailbox ($Name, $Callback = null, $Private = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Issue the command
      return $this->imapCommand ('SUBSCRIBE', array ($Name, $newName), array ($this, 'mailboxAction'), array (3, $Callback, $Name, $Private));
    }
    // }}}
    
    // {{{ unsubscribeMailbox
    /**
     * Unsubscribe from a mailbox on our server
     * 
     * @param string $Name
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function unsubscribeMailbox ($Name, $Callback = null, $Private = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&   
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Issue the command
      return $this->imapCommand ('UNSUBSCRIBE', array ($Name, $newName), array ($this, 'mailboxAction'), array (4, $Callback, $Name, $Private));
    }
    // }}}
    
    // {{{ appendMailbox
    /**
     * Append a given message to a mailbox
     * 
     * @param string $Mailbox
     * @param string $Message
     * @param array $Flags (optional)
     * @param int $Timestamp (optional)
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function appendMailbox ($Mailbox, $Message, $Flags = null, $Timestamp = null, $Callback = null, $Private = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Generate parameters
      $Args = array ($Mailbox);
      
      if (is_array ($Flags))
        $Args [] = '(' . implode (' ', $Flags) . ')';
      
      if ($Timestamp !== null)
        $Args [] = '"' . date ('d-M-Y H:i:s O', $Timestamp) . '"';
      
      if ($this->haveCapability ('LITERAL+')) {
        $Args [] = '{' . strlen ($Message) . '+}' . "\n" . $Message;
        $Args = array ($Args);
      } else {
        $Args [] = '{' . strlen ($Message) . '}';
        $Args = array ($Args, array ($Message));
      }
      
      // Issue the command
      return $this->imapCommand ('APPEND', $Args, array ($this, 'mailboxAction'), array (5, $Callback, $Mailbox, $Private), null, null, true, true);
    }
    // }}}
    
    // {{{ mailboxAction
    /**
     * Internal Callback: A Mailbox was modified
     * 
     * @param enum $Response
     * @param array $Code
     * @param string $Text
     * @param array $Private
     * 
     * @access private
     * @return void
     **/
    private function mailboxAction ($Response, $Code, $Text, $Private) {
      // Retrive Arguments
      $Action = array_shift ($Private);
      $Callback = array_shift ($Private);
      
      // Update internal values upon success
      if ($Response == self::IMAP_STATUS_OK) {
        // Mailbox was created
        if ($Action == 0) {
        
        // Mailbox was removed or renamed
        } elseif (($Action == 1) || ($Action == 2)) {
          // Remove the mailbox
          if ($Handle = $this->getMailbox ($Private [0])) {
            if ($Handle->Parent)
              unset ($Handle->Parent->Children [$Handle->Name]);
            else
              unset ($this->imapMailboxes [$Handle->Name]);
            
            if ($Action == 2) {
              $Handle2 = $this->imapCreateLocalMailbox ($Private [1]);
              $Handle->Name = $Handle2->Name;
              $Handle->Parent = $Handle2->Parent;
              
              if ($Handle->Parent)
                $Handle->Parent->Children [$Handle->Name] = $Handle;
              else
                $this->imapMailboxes [$Handle->Name] = $Handle;
            }
          } elseif ($Action == 2)
            $this->imapCreateLocalMailbox ($Private [1]);
        
        // Mailbox was (un)subscribed
        } elseif ((($Action == 3) || ($Action == 4)) && ($Handle = $this->getMailbox ($Private [0])))
          $Handle->Subscribed = ($Action == 3);
        
        // Mail was appended to mailbox
        elseif ($Action == 5) {
        
        }
      }
      
      // Check wheter to fire up another callback
      if (($Callback !== null) && is_callable ($Callback)) {
        array_unshift ($Private, $this);
        $Private [] = $Response == self::IMAP_STATUS_OK;
        
        call_user_func_array ($Callback, $Private);
      }
    }
    // }}}
    
    // {{{ listMailboxes
    /**
     * Request a list of mailboxes from server
     * 
     * @param string $Name (optional)
     * @param string $Root (optional)
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function listMailboxes ($Name = '%', $Root = '', $Callback = null, $Private = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Try to load IMAP-Namespaces before this one
      if (!is_array ($this->imapNamespaces))
        $this->listNamespaces ();
      
      // Issue the command
      return $this->imapCommand ('LIST', array ($Root, $Name), array ($this, 'listHandler'), array (0, $Callback, $Name, $Root, $Private));
    }
    // }}}
    
    // {{{ listSubscribedMailboxes
    /**
     * Request a list of subscribed mailboxes from server
     * 
     * @param string $Name (optional)
     * @param string $Root (optional)  
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void   
     **/
    public function listSubscribedMailboxes ($Name = '%', $Root = '', $Callback = null, $Private = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Try to load IMAP-Namespaces before this one
      if (!is_array ($this->imapNamespaces))
        $this->listNamespaces ();
      
      // Issue the command
      return $this->imapCommand ('LSUB', array ($Root, $Name), array ($this, 'listHandler'), array (1, $Callback, $Name, $Root, $Private));
    }
    // }}}
    
    // {{{ listNamespaces
    /**
     * Request a list of all namespaces on this server
     * 
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void   
     **/
    public function listNamespaces ($Callback = null, $Private = null) {
      // Check our state
      if ((($this->State != self::IMAP_STATE_AUTHENTICATED) &&
           ($this->State != self::IMAP_STATE_ONMAILBOX)) ||
          !$this->haveCapability ('NAMESPACE'))
        return false;
      
      // Make sure the format of imapNamespaces is correct
      if (!is_array ($this->imapNamespaces))
        $this->imapNamespaces = array ();
      
      // Issue the command
      return $this->imapCommand ('NAMESPACE', array (), array ($this, 'listHandler'), array (2, $Callback, $Private));
    }
    // }}}
    
    // {{{ listHandler
    /**
     * Internal Callback: A LIST/LSUB-Command was executed
     * 
     * @param enum $Response
     * @param array $Code
     * @param string $Text
     * @param array $Private
     * 
     * @access private
     * @return void
     **/
    private function listHandler ($Response, $Code, $Text, $Private) {
      # TODO: Improve this
      $Type = array_shift ($Private);
      
      // Check if LIST/LSUB was performed
      if ($Type < 2) {
        $Root = $Private [2];
        
        // Implement HasNoChildren-Flag if not supported by server
        if (($Handle = $this->getMailbox ($Root)) && (count ($Handle->Children) == 0) && !in_array ('\\HasNoChildren', $Handle->Attributes))
          $Handle->Attributes [] = '\\HasNoChildren';
      }
      
      // Issue any stored callback for this
      $Callback = $Private [0];
      $Private [0] = $this;
      
      if (($Callback !== null) && is_callable ($Callback))
        call_user_func_array ($Callback, $Private);
    }
    // }}}
    
    // {{{ selectMailbox
    /**
     * Open a given mailbox
     * 
     * @param string $Mailbox
     * @param bool $ReadOnly (optional)
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function selectMailbox ($Mailbox, $ReadOnly = false, $Callback = null, $Private = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Store the predicted next mailbox
      $this->imapMailboxNext = $Mailbox;
      
      // Issue the command 
      return $this->imapCommand (($ReadOnly ? 'EXAMINE' : 'SELECT'), array ($Mailbox), array ($this, 'selectHandler'), array ($Callback, $Mailbox, $Private));
    }
    // }}}
    
    // {{{ selectHandler
    /**
     * Internal Callback: A Mailbox was selected
     * 
     * @param enum $Response
     * @param array $Code
     * @param string $Text
     * @param array $Private
     * 
     * @access private
     * @return void
     **/
    private function selectHandler ($Response, $Code, $Text, $Private) {
      // Check if the command was successfull
      if ($Response == self::IMAP_STATUS_OK) {
        $this->imapMailbox = $Private [1];
        $this->imapMailboxNext = null;
        $this->mailboxReadOnly = ((count ($Code) > 0) && ($Code [0] == 'READ-ONLY'));
        
        $this->imapSetState (self::IMAP_STATE_ONMAILBOX);
      }
      
      // Fire callbacks
      $this->imapCallbackStatus (array ('imapMailboxOpened', 'imapMailboxOpenFailed'), $Private [0], $Response == self::IMAP_STATUS_OK, $this->imapMailbox, !$this->mailboxReadOnly, $Private [2]);
    }
    // }}}
    
    
    // {{{ check
    /**
     * Issue a CHECK-Command
     * 
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function check ($Callback = null, $Private = null) {
      // Check our state
      if ($this->State != self::IMAP_STATE_ONMAILBOX)
        return false;
      
      return $this->imapCommand ('CHECK', null, $Callback, $Private);
    }
    // }}}
    
    // {{{ closeMailbox
    /**
     * Close the currently selected mailbox
     * 
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function closeMailbox ($Callback = null, $Private = null) {
      // Check our state
      if ($this->State != self::IMAP_STATE_ONMAILBOX)  
        return false;
      
      $this->imapMailboxNext = null;
      
      return $this->imapCommand ('CLOSE', null, array ($this, 'closeHandler'), array ($Callback, $Private));
    }
    // }}}
    
    // {{{ unselect
    /**
     * Unselect the current mailbox
     * 
     * @remark this is similar to CLOSE, but does not implicit expunge deleted messages
     * 
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void  
     **/
    public function unselect ($Callback = null, $Private = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_ONMAILBOX) || !$this->haveCapability ('UNSELECT'))
        return false;
      
      $this->imapMailboxNext = null;
      
      return $this->imapCommand ('UNSELECT', null, array ($this, 'closeHandler'), array ($Callback, $Private));
    }
    // }}}
    
    // {{{ closeHandler
    /**
     * Internal Callback: CLOSE-Command was executed
     * 
     * @param enum $Response
     * @param array $Code
     * @param string $Text
     * @param array $Private
     * 
     * @access private
     * @return void
     **/  
    private function closeHandler ($Response, $Code, $Text, $Private) {
      // Remember the current mailbox
      $oMailbox = $this->imapMailbox;
      
      // Change the status on success
      if ($Response == self::IMAP_STATUS_OK) {
        $this->imapMailbox = null;
        $this->imapSetState (self::IMAP_STATE_AUTHENTICATED);
      }
      
      // Handle callbacks
      $this->imapCallbackStatus (array ('imapMailboxClosed', 'imapMailboxCloseFailed'), $Private [0], $Response == self::IMAP_STATUS_OK, $oMailbox, $Private [1]);
    }
    // }}}
    
    // {{{ expunge
    /**
     * Wipe out all mails marked as deleted on the current mailbox
     * 
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function expunge ($Callback = null, $Private = null) {
      // Check our state
      if ($this->State != self::IMAP_STATE_ONMAILBOX)
        return false;
      
      // Issue the command
      return $this->imapCommand ('EXPUNGE', null, array ($this, 'imapCallbackExt'), array ($Callback, true, $Private));
    }
    // }}}
    
    // {{{ setFlags
    /**
     * Set Flags of a messages
     * 
     * @param bool $byUID
     * @param sequence $IDs
     * @param array $Flags
     * @param enum $Mode (optional)
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function setFlags ($byUID, $IDs, $Flags, $Mode = self::MODE_SET, $Callback = null, $Private = null) {
      // Check our state
      if ($this->State != self::IMAP_STATE_ONMAILBOX)
        return false;
      
      // Prepare the args
      $Args = array ();
      
      if (!($Seq = $this->imapSequence ($IDs)))
        return false;
      
      $Args [] = $Seq;
      
      if ($Mode == self::MODE_SET)
        $Args [] = 'FLAGS';
      elseif ($Mode == self::MODE_ADD)
        $Args [] = '+FLAGS';
      elseif ($Mode == self::MODE_DEL)
        $Args [] = '-FLAGS';
      else
        return false;
      
      if (!is_array ($Flags))
        $Flags = array ($Flags);
      
      $Args [] = '(' . implode (' ', $Flags) . ')';
      
      // Issue the command
      return $this->imapCommand (($byUID ? 'UID STORE' : 'STORE'), $Args, array ($this, 'imapCallbackExt'), array ($Callback, true, $Private), null, null, true);
    }
    // }}}
    
    // {{{ copy
    /**
     * Copy a set of Messages to a given mailbox
     * 
     * @param bool $byUID
     * @param sequence $IDs
     * @param string $Mailbox
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function copy ($byUID, $IDs, $Mailbox, $Callback = null, $Private = null) {
      // Check our state
      if ($this->State != self::IMAP_STATE_ONMAILBOX)
        return false;
      
      // Prepare the args
      if (!($Seq = $this->imapSequence ($IDs)))
        return false;
      
      $Args = array ($Seq, $Mailbox);
      
      // Issue the command
      return $this->imapCommand (($byUID ? 'UID COPY' : 'COPY'), $Args, array ($this, 'imapCallbackExt'), array ($Callback, true, $Private));
    }
    // }}}
    
    // {{{ fetchMessages
    /**
     * Fetch messages from server
     * 
     * @param bool $byUID
     * @param sequence $IDs
     * @param callback $Callback (optional)
     * @param mixed $Private (optional)
     * @param string ... (optional)
     *
     * @access public
     * @return void
     **/
    public function fetchMessages ($byUID, $IDs, $Callback = null, $Private = null, $Item = 'ALL') {
      // Check our state
      if ($this->State != self::IMAP_STATE_ONMAILBOX)
        return false;
      
      // Handle all data-items
      if (count ($Args = array_slice (func_get_args (), 3)) == 0)
        $Args [] = 'ALL';
      
      foreach ($Args as $idx=>$Arg)
        // Make sure Macros are on their own
        if ($Arg == 'ALL') {
          $Args = array ('UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'ENVELOPE');
          break;
        } elseif ($Arg == 'FAST') {
          $Args = array ('UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE');
          break;
        } elseif ($Arg == 'FULL') {
          $Args = array ('UID', 'FLAGS', 'INTERNALDATE', 'RFC822.SIZE', 'ENVELOPE', 'BODY');
          break;
        
        // Check for a valid flag that stands on its own
        } elseif (($Arg == 'ENVELOPE') || ($Arg == 'FLAGS') || ($Arg == 'UID') || ($Arg == 'INTERNALDATE') ||
                  ($Arg == 'RFC822') || ($Arg == 'RFC822.HEADER') || ($Arg == 'RFC822.SIZE') || ($Arg == 'RFC822.TEXT') ||
                  ($Arg == 'BODY') || ($Arg == 'BODYSTRUCTURE'))
          continue;
        
        // Check wheter to fetch a body-section
        elseif (((substr ($Arg, 0, 5) == 'BODY[') || (substr ($Arg, 0, 10) == 'BODY.PEEK[')) && (substr ($Arg, -1, 1) == ']')) {
          # TODO: Validate the section-value
          # TODO: Add support for parital gets
          
          # $Section = substr ($Arg, ($Arg [4] == '.' ? 10 : 5), -1);
          continue;
        
        // Discard the value if it seems invalid
        } else
          unset ($Args [$idx]);
      
      // Make sure always to fetch the UID
      if (!in_array ('UID', $Args))
        $Args [] = 'UID';
      
      // Prepare the args
      if (!($Seq = $this->imapSequence ($IDs)))
        return false;
      
      $Args = array ($Seq, '(' . implode (' ', $Args) . ')');
      
      // Issue the command
      return $this->imapCommand (($byUID ? 'UID FETCH' : 'FETCH'), $Args, array ($this, 'imapCallbackExt'), array ($Callback, true, $Private), null, null, true);
    }
    // }}}
    
    // {{{ searchMessages
    /**
     * Search a set of messages
     * 
     * @param bool $byUID
     * @param string $Charset (optional)
     * @param $Callback (optional)
     * @param mixed $Private (optional)
     * @param string ... (optional)
     * 
     * @access public
     * @return void
     **/
    public function searchMessages ($byUID, $Charset = null, $Callback = null, $Private = null, $Match1 = 'ALL') {
      // Check our state
      if ($this->State != self::IMAP_STATE_ONMAILBOX)
        return false;
      
      // Handle all data-items
      if (count ($Args = array_slice (func_get_args (), 3)) == 0)
        $Args [] = 'ALL';
      
      foreach ($Args as $idx=>$Arg)
        if (!$this->checkSearchKey ($Arg))
          unset ($Args [$idx]);
      
      if (count ($Args) > 1)
        $Args = array ($Args);
      
      // Prepend Charset to arguements
      if ($Charset !== null)
        array_unshift ($Args, 'CHARSET', $Charset);
      
      // Issue the command
      return $this->imapCommand (($byUID ? 'UID SEARCH' : 'SEARCH'), $Args, array ($this, 'imapCallbackExt'), array ($Callback, true, $Private), null, null, true);
    }
    // }}}
    
    // {{{ checkSearchKey
    /**
     * Validate a given search-key
     * 
     * @param string $Key
     * 
     * @access private
     * @return bool
     **/
    private function checkSearchKey ($Key) {
      // Check for a single-value
      if (($Key == 'ALL') || ($Key == 'NEW') || ($Key == 'OLD') || ($Key == 'SEEN') ||
          ($Key == 'DRAFT') || ($Key == 'RECENT') || ($Key == 'UNSEEN') || ($Key == 'DELETED') ||
          ($Key == 'FLAGGED') || ($Key == 'UNDRAFT') || ($Key == 'UNDELETED') || ($Key == 'UNFLAGGED') ||
          ($Key == 'UNANSWERED'))
        return true;
      
      // Try to parse the key
      if (!($Params = $this->imapParseArgs ($Key)) || (count ($Params) < 2))
        return false;
      
      // Check by keyword
      switch ($Params [0]) {
        // Second arguement must be a number
        case 'LARGER':
        case 'SMALLER':
          return ((count ($Params) == 2) && is_numeric ($Params [1]));
        
        // Simple strings
        case 'CC':
        case 'BCC':
        case 'FROM':
        case 'BODY':
        case 'SUBJECT':
          return ((count ($Params) == 2) && (strlen ($Params [1]) > 0));
        
        case 'HEADER':
          return ((count ($Params) == 3) && (strlen ($Params [1]) > 0) && (strlen ($Params [2]) > 0));
          
        // Dates
        case 'ON':
        case 'SINCE':
        case 'BEFORE':
        case 'SENTON':
        case 'SENTSINCE':
        case 'SENTBEFORE':
          return ((count ($Params) == 2) && (strlen ($Params [1]) > 7));
        
        // Flags
        case 'KEYWORD':
        case 'UNKEYWORD':
          return ((count ($Params) == 2) && (strlen ($Params [1]) > 0));
        
        // Sequences
        case 'UID':
          return ((count ($Params) == 2) && (strlen ($Params [1]) > 0));
        
        // Subkeys
        case 'NOT':
          return $this->checkSearchKey (substr ($Key, 4));
        
        case 'OR':
          # TODO!
      }
      
      return false;
    }
    // }}}
    
    // {{{ imapCommand
    /**
     * Issue an IMAP-Command over the wire
     * 
     * @param string $Command
     * @param mixed $Args
     * @param callback $Callback
     * @param mixed $Private (optional)
     * @param callback $ContinueCallback (optional)
     * @param mixed $Private2 (optional)
     * @param bool $dontParse (optional)
     * @param bool $unparedMultiline (optional)
     * 
     * @access private
     * @return void
     **/
    private function imapCommand ($Command, $Args, $Callback, $Private = null, $ContinueCallback = null, $Private2 = null, $dontParse = false, $unparedMultiline = false) {
      // Make sure the callback is valid
      if (($Callback !== null) && !is_callable ($Callback)) {
        trigger_error ('No valid callback given', E_USER_WARNING);
        
        return false;
      }
      
      if (($ContinueCallback !== null) && !is_callable ($ContinueCallback))
        $ContinueCallback = null;
      
      // Retrive the ID for this Command
      $ID = $this->Commands++;
      
      # TODO: Keep commands in a local pipe, don't issue them directly
      #   See RFC 3501 5.5
      # Use for this commandPipe
      
      // Register a callback for this command
      $this->Callbacks [$ID] = array ($Callback, $Private, $ContinueCallback, $Private2);
      
      // Write out a command with arguments
      if (is_array ($Args) && (count ($Args) > 0)) {
        // Check wheter to parse the given arguements
        if ($dontParse) {
          if ($unparedMultiline) {
            $firstArg = implode (' ', array_shift ($Args));
          } else {
            $firstArg = implode (' ', $Args);
            $Args = array ();
          }
        
        // Prepare arguements for submission
        } elseif (!($Args = $this->imapArgs ($Args)))
          return false;
        
        // Peek the first line for this command
        else
          $firstArg = array_shift ($Args);
        
        // Check if there are literals (and rewrite the continuation-callback)
        if (count ($Args) > 0) {
          $this->Callbacks [$ID][2] = array ($this, 'handleCommandLiterals');
          $this->Callbacks [$ID][3] = array ($Args, $ContinueCallback, $Private2);
        }
        
        // Write out the first part of this command
        $this->mwrite ('C', dechex ($ID), ' ', $Command, ' ', $firstArg, "\r\n");
        
      // Write out command without args
      } else
        $this->mwrite ('C', dechex ($ID), ' ', $Command, "\r\n");
    }
    // }}}
    
    // {{{ handleCommandLiterals
    /**
     * Internal Callback: Write out further literals for a command
     * 
     * @param int $ID Identifier of the current command
     * @param string $Text Text submitted by the server for this continuation-request
     * @param array $Private Private data for this callback
     * 
     * @access private
     * @return string
     **/
    private function handleCommandLiterals ($ID, $Text, $Private) {
      // Retrive the next chunk from the pipe
      $NextArg = array_shift ($this->Callbacks [$ID][3][0]);
      
      // Check if we are ready after this one
      if (count ($this->Callbacks [$ID][3][0]) == 0) {
        $this->Callbacks [$ID][2] = $this->Callbacks [$ID][3][1];
        $this->Callbacks [$ID][3] = $this->Callbacks [$ID][3][2];
      }
      
      return $NextArg;
    }
    // }}}
    
    // {{{ imapSequence
    /**
     * Create an IMAP-Sequence from a given valud
     * 
     * @param mixed $Sequence
     * 
     * @access private
     * @return string
     **/
    private function imapSequence ($Sequence) {
      // Return the Sequence as-is if not an array
      if (!is_array ($Sequence))
        return $Sequence;
      
      // Convert an array into a string
      $out = '';
      
      foreach ($Sequence as $V)
        if (is_array ($V)) {
          if (count ($V) == 2) {
            $V1 = array_shift ($V);
            $V2 = array_shift ($V);
            $out .= ($V1 == '*' ? $V1 : intval ($V1)) . ':' . ($V2 == '*' ? $V2 : intval ($V2)) . ',';
          } else
            foreach ($V as $v)
              $out .= ($v == '*' ? $v : intval ($v)) . ',';
        } else
          $out .= ($V == '*' ? $V : intval ($V)) . ',';
      
      return substr ($out, 0, -1);
    }
    // }}}
    
    // {{{ isASCII
    /**
     * Check if a given string is 7-bit ASCII
     * 
     * @param string $Text
     * 
     * @access private
     * @return bool
     **/
    private function isASCII ($Text) {
      for ($i = 0; $i < strlen ($Text); $i++)
        if (ord ($Text [$i]) > 127)
          return false;
      
      return true;
    }
    // }}}
    
    // {{{ imapArgs
    /**
     * Parse given arguements into a string
     * 
     * @param array $Args
     * 
     * @access private
     * @return array
     **/
    private function imapArgs ($Args) {
      // Check if the server supports literal+
      $literalPlus = $this->haveCapability ('LITERAL+');
      
      // Generate the output
      $rc = array ();
      $out = '';
      
      while (count ($Args) > 0) {
        // Get the next argument
        $Arg = array_shift ($Args);
        
        // Check for an array
        if (is_array ($Arg)) {
          $lst = $this->imapArgs ($Arg);
          $c = count ($lst);
          
          if ($c == 1)
            $out .= '(' . $lst [0] . ') ';
          elseif ($c == 0)
            $out .= '() ';
          else {
            $out .= '(' . array_shift ($lst);
            $rc [] = $out;
            
            foreach ($lst as $l)
              $rc [] = $l;
            
            $out .= ') ';
          }
        
        // Check for a null-value
        } elseif ($Arg === null)
          $Out .= 'NIL ';
        
        // Check for an empty string
        elseif (($l = strlen ($Arg)) == 0)
          $out .= '"" ';
        
        // Check for an explicit literal
        elseif (($Arg [0] == '{') && ($Arg [$l - 1] == '}') && is_numeric (substr ($Arg, 1, $l - 2))) {
          $next = array_shift ($Args);
          
          if (!$literalPlus) {
            $out .= '{' . strlen ($next) . '}';
            $rc [] = $out;
            $out = $next . ' ';
          } else
            $out .= '{' . strlen ($next) . '+}' . "\r\n" . $next . ' ';
        
        // Check for an implicit literal
        } elseif (!$this->isASCII ($Arg) || (strpos ($Arg, "\n") !== false) || (strpos ($Arg, "\r") !== false)) {
          if (!$literalPlus) {
            $out .= '{' . strlen ($Arg) . '}';
            $rc [] = $out;
            $out = $Arg . ' ';
          } else
            $out .= '{' . strlen ($Arg) . '+}'. "\r\n" . $Arg . ' ';
        
        // Check wheter to quote
        } elseif ((strpos ($Arg, ' ') !== false) || (strpos ($Arg, '(') !== false) || (strpos ($Arg, ')') !== false) ||
                (strpos ($Arg, '{') !== false) || (strpos ($Arg, '%') !== false) || (strpos ($Arg, '*') !== false) ||
                (strpos ($Arg, '[') !== false) || (strpos ($Arg, '\\') !== false))
          $out .= '"' . $Arg . '" ';
        
        // Just output the string
        else
          $out .= $Arg . ' ';
      }
      
      $rc [] = substr ($out, 0, -1);
      
      return $rc;
    }
    // }}}
    
    // {{{ imapParseArgs
    /**
     * Parse a string into IMAP-Args
     * 
     * @param string $Args
     * 
     * @access private
     * @return array
     **/
    private function imapParseArgs ($Args) {
      $out = array ();
      $stack = array ();
      
      while (($l = strlen ($Args)) > 0) {
        // Check for an enclosed value
        if (($Args [0] == '"') || ($Args [0] == "'")) {
          if (($p = strpos ($Args, $Args [0], 1)) !== false) {
            $out [] = substr ($Args, 1, $p - 1);
            $Args = ltrim (substr ($Args, $p + 1));
          } else {
            $out [] = substr ($Args, 1);
            $Args = '';
          }
        
        // Check for a literal
        } elseif (($Args [0] == '{') && (($p = strpos ($Args, "}\r\n", 1)) !== false)) {
          $Size = intval (substr ($Args, 1, $p - 1));
          $out [] = substr ($Args, $p + 3, $Size);
          $Args = ltrim (substr ($Args, $p + $Size + 3));
        
        // Check for beginning of an array
        } elseif ($Args [0] == '(') {
          $stack [] = $out;
          $out = array ();
          $Args = ltrim (substr ($Args, 1));
        
        // Check for end of an array
        } elseif ($Args [0] == ')') {
          $nout = array_pop ($stack);
          $nout [] = $out;
          $out = $nout;
          $Args = ltrim (substr ($Args, 1));
          unset ($nout);
        
        // Handle as simple type
        } else {
          // Find next delimiter
          if (($p = strpos ($Args, ' ')) === false)
            $p = $l;
          
          // Move back to last valid value
          $p--;
          
          if ((($p2 = strpos ($Args, '[')) !== false) &&
              ($p2 < $p) &&
              (($p3 = strpos ($Args, ']', $p2)) !== false))
            $p = max ($p3, $p);
          
          // Check for closed arrays
          if ((($p2 = strpos ($Args, ')')) !== false) && ($p2 < $p))
            $p = $p2 - 1;
          
          while ($Args [$p] == ')')
            $p--;
          
          if (($Value = substr ($Args, 0, $p + 1)) == 'NIL')
            $Value = null;
          
          $out [] = $Value;
          $Args = ltrim (substr ($Args, $p + 1));
        }
      }
      
      // Roll stack back (should never be used)
      while (count ($stack) > 0) {
        $nout = array_pop ($stack);
        $nout [] = $out;
        $out = $nout;
      }
      
      return $out;
    }
    // }}}
    
    // {{{ imapSetState
    /**
     * Change the IMAP-Protocol-State
     * 
     * @param enum $State
     * 
     * @access private
     * @return void
     **/
    private function imapSetState ($State) {
      // Check if anything is to be changed
      if ($State == $this->State)
        return;
      
      // Change the status
      $oState = $this->State;
      $this->State = $State;
      
      // Fire callback
      $this->___callback ('imapStateChanged', $State, $oState);
    }
    // }}}
    
    // {{{ imapCallback
    /**
     * Internal callback: Convert an IMAP-Command-Callback to a normal callback
     * 
     * @param enum $Response
     * @param array $Code
     * @param string $Text
     * @param array $Private
     * 
     * @access private
     * @return void
     **/
    private function imapCallback ($Response, $Code, $Text, $Private) {
      if (!is_array ($Private))
        $Private = array ($Private);
      
      call_user_func_array (array ($this, '___callback'), $Private);
    }
    // }}}
    
    // {{{ imapCallbackExt
    /**
     * Internal callback: Convert an IMAP-Command-Callback to an external callback
     * 
     * $Private is expected to be an array with at least one element:
     * - The first element is taken as callback
     * - If the second arguement is true, a boolean representing the IMAP-Response is appended to the remaining array
     * - The remaining elements (including IMAP-Response) will be used as parameter for the callback
     * 
     * @param enum $Response
     * @param array $Code
     * @param string $Text
     * @param array $Private
     * 
     * @access private
     * @return void
     **/
    private function imapCallbackExt ($Response, $Code, $Text, $Private) {
      // Make sure we have an array
      if (!is_array ($Private) || (($c = count ($Private)) < 1))
        return;
      
      // Retrive the callback
      if (!is_callable ($Callback = array_shift ($Private)))
        return;
      
      // Check wheter to append the status
      if ($c > 1)
        $appendStatus = array_shift ($Private);
      
      // Prepare arguements
      if ($appendStatus)
        array_unshift ($Private, $Response == self::IMAP_STATUS_OK);
      
      array_unshift ($Private, $this);
      
      // Fire the callback
      call_user_func_array ($Callback, $Private);
    } 
    // }}}
    
    // {{{ imapCallbackStatus
    /**
     * Fire up internal and external callbacks with a status-code
     * 
     * @param mixed $Internal
     * @param callback $External
     * @param bool $Status
     * 
     * @access private
     * @return void
     **/
    private function imapCallbackStatus ($Internal, $External, $Status) {
      // Retrive arguements
      $Args = array_slice (func_get_args (), 3);
      
      // Fire up external callback
      if (($External !== null) && is_callable ($External)) {
        $eArgs = $Args;
        array_unshift ($eArgs, $this, $Status == true);
        
        call_user_func_array ($External, $eArgs);
      }
      
      // Choose the right callback
      if (is_array ($Internal))
        $Callback = ($Status ? array_shift ($Internal) : array_pop ($Internal));
      else
        array_push ($Args, $Status == true);
      
      if ($Callback === null)
        return;
      
      array_unshift ($Args, $Callback);
      call_user_func_array (array ($this, '___callback'), $Args);
    }
    // }}}
    
    
    // {{{ socketConnected
    /**
     * Occupied Callback: Underlying connection was established
     * 
     * @access protected
     * @return void
     **/
    protected final function socketConnected () {
      $this->imapSetState (self::IMAP_STATE_CONNECTING);
      $this->imapGreeting = false;
    }
    // }}}
    
    // {{{ socketDisconnected
    /**
     * Occupied Callback: Underlying connection was closed
     * 
     * @access protected
     * @return void
     **/
    protected final function socketDisconnected () {
      $this->imapSetState (self::IMAP_STATE_DISCONNECTED);
      
      $this->___callback ('imapDisconnected');
    }
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
     * @param string $Mailbox
     * 
     * @access protected
     * @return void
     **/
    protected function imapMailboxClosed ($Mailbox) { }
    // }}}
    
    // {{{ imapMailboxCloseFailed
    /**
     * Callback: Mailbox could not be closed
     * 
     * @param string $Mailbox
     * 
     * @access protected
     * @return void
     **/
    protected function imapMailboxCloseFailed ($Mailbox) { }
    // }}}
  }

?>