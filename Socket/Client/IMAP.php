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
    private $namespaces = array ();
    
    /* Status-Cache for Mailboxes */
    private $mailboxes = array ();
    
    /* Current Mailbox */
    private $mailboxCurrent = null;
    
    /* Read-Only-Status of current mailbox */
    private $mailboxReadOnly = false;
    
    /* Flags of current mailbox */
    private $mailboxFlags = array ();
    
    /* Flags that are stored permanent on current mailbox */
    private $mailboxPermanentFlags = array ();
    
    /* Number of mails in current mailbox */
    private $mailboxCount = 0;
    
    /* Number of recent mails in current mailbox */
    private $mailboxRecent = 0;
    
    /* Number of unseen mails in current mailbox */
    private $mailboxUnseen = 0;
    
    /* UID-Validity of current mailbox */
    private $mailboxUIDValidity = 0;
    
    /* Next UID-Value of current mailbox */
    private $mailboxUIDNext = 0;
    
    /* Message-Information */
    private $messages = array ();
    
    /* Sequence-to-UID mappings */
    private $messageIDs = array ();
    
    /* Message-IDs of last search */
    private $searchResult = array ();
    
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
          $this->receive ($Buffer);
        
        return;
      }
      
      // Flush any buffered command
      $Line = $this->commandBuffer . $Line;
      $this->commandBuffer = '';
      
      // Check for a continuation-command
      if ($Line [0] == '+') {
        echo 'IN-CONTINUE: ', $Line, "\n";
        
        // Find the next command-callback with continuation-callback
        foreach ($this->Callbacks as $ID=>$Info)
          if ($Info [2] !== null) {
            $rc = call_user_func ($Info [2], $ID, ltrim (substr ($Line, 1)), $Info [3]);
            
            if ($rc === null)
              continue;
            
            $this->write ($rc . "\r\n");
            echo 'OUT-CONTINUE: ', trim ($rc), "\n";
            
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
      
      echo 'IN: ', $Tag, ' / ', $Response, ' / ', implode (' - ', $Code), ' / ', $Line, "\n";
      
      // Handle command based on state
      if ($this->State == self::IMAP_STATE_CONNECTING) {
        // Connection is OK, we are in connected state
        if ($Response == self::IMAP_STATUS_OK) {
          $this->imapSetState (self::IMAP_STATE_CONNECTED);
          $Callback = 'imapConnected';
        
        // Connection is very good, we are already authenticated
        } elseif ($Response == self::IMAP_STATUS_PREAUTH) {
          $this->imapSetState (self::IMAP_STATE_AUTHENTICATED);
          $Callback = 'imapAuthenticated';
        
        // Something is wrong with us or the server, we will be disconnected soon
        } elseif ($Response == self::IMAP_STATUS_BYE) {
          $this->imapSetState (self::IMAP_STATE_DISCONNECTED);
          return $this->disconnect ();
        
        // RFC-Violation, leave immediatly
        } else {
          trigger_error ('Wrong response received: ' . $Response);
          
          return $this->disconnect ();
        }
        
        // Check wheter to ask for capabilities
        if (count ($this->serverCapabilities) == 0)
          return $this->imapCommand ('CAPABILITY', null, array ($this, 'imapCallback'), $Callback);
        
        return;
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
          
          // Make sure the mailbox-status is initialized
          if (!isset ($this->mailboxes [$Result [0]]))
            $this->mailboxes [$Result [0]] = array ('Flags' => null, 'Subscribed' => null, 'Delimiter' => null, 'Status' => array ());
          
          // Merge the status
          while (count ($Result [1]) > 1) {
            $Property = array_shift ($Result [1]);
            $this->mailboxes [$Result [0]]['Status'][$Property] = array_shift ($Result [1]);
          }
          
          break;
        
        // LIST/LSUB-Information
        case 'LIST':
        case 'LSUB':
          // Parse the result
          $Result = $this->imapParseArgs ($Text);
          
          // Update Mailbox-Information
          if (isset ($this->mailboxes [$Result [2]])) {
            $this->mailboxes [$Result [2]]['Delimiter'] = $Result [1]; 
            $this->mailboxes [$Result [2]]['Flags'] = $Result [0];
            
            if ($Response == 'LSUB')
              $this->mailboxes [$Result [2]]['Subscribed'] = true;
          } else
            $this->mailboxes [$Result [2]] = array ('Flags' => $Result [0], 'Subscribed' => ($Response == 'LSUB'), 'Delimiter' => $Result [1], 'Status' => array ());
          
          break;
        
        // Namespace-Info
        case 'NAMESPACE':
          // Parse the result
          $Result = $this->imapParseArgs ($Text);
          
          foreach ($Result as $Type=>$Namespaces) {
            if (!is_array ($Namespaces))
              continue;
            
            foreach ($Namespaces as $Info)
              $this->namespaces [$Info [0]] = array ($Info [1], $Type);
          }
          
          break;
        
        // Handle Search-Results
        case 'SEARCH':
          $this->searchResult = $this->imapParseArgs ($Text);
          break;
        
        // Mailbox-Flags
        case 'FLAGS':
          $Args = $this->imapParseArgs ($Text);
          $this->mailboxFlags = array_shift ($Args);
          break;
        
        // Number of mails in current mailbox
        case 'EXISTS':
          $this->mailboxCount = intval ($Text);
          break;
        
        // Number of recent mails in current mailbox
        case 'RECENT':
          $this->mailboxRecent = intval ($Text);
          break;
        
        // A message was removed from mailbox
        case 'EXPUNGE':
          // Decrease the message-counter
          $this->mailboxCount--;
          
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
                $this->messages [$UID]['Envelope'] = $Args [$i + 1];
                
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
                      $Type = 'RFC822';
                    
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
          
          switch ($Code [0]) {
            // List of flags that are stored permanent
            case 'PERMANENTFLAGS':
              $Args = $this->imapParseArgs ($Code [1]);
              $this->mailboxPermanentFlags = array_shift ($Args);
              break;
            
            // UID-Validity-Value of a mailbox
            case 'UIDVALIDITY':
              $this->mailboxUIDValidity = intval ($Code [1]);
              break;
            
            // Predicted next UID-Value
            case 'UIDNEXT':
              $this->mailboxUIDNext = intval ($Code [1]);
              break;
            
            // Number of unseen messages on this mailbox
            case 'UNSEEN':
              $this->mailboxUnseen = intval ($Code [1]);
              break;
          }
          
          break;                              
        
        default:
          echo 'UNHANDLED UNTAGGED: ', $Response, ' / ', implode (' - ', $Code), ' / ', $Text, "\n";
      }
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
     * 
     * @access public
     * @return void
     **/
    public function getCapabilities ($Callback = null) {
      return $this->imapCommand ('CAPABILITY', null, $Callback);
    }
    // }}}
    
    // {{{ noOp
    /**
     * Issue an NoOp-Command to keep the connection alive or retrive any pending updates
     * 
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function noOp ($Callback = null) {
      return $this->imapCommand ('NOOP', null, $Callback);
    }
    // }}}
    
    // {{{ logout
    /**
     * Request a logout from server
     * 
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function logout ($Callback = null) {
      $this->imapSetState (self::IMAP_STATE_DISCONNECTING);
      
      return $this->imapCommand ('LOGOUT', null, $Callback);
    }
    // }}}
    
    // {{{ startTLS
    /**
     * Enable TLS-encryption on this connection
     * 
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return bool
     **/
    public function startTLS ($Callback = null) {
      // Check if STARTTLS is supported by the server
      if (!$this->haveCapability ('STARTTLS'))
        return false;
      
      // Check if we are in connected state
      if ($this->State !== self::IMAP_STATE_CONNECTED)
        # TODO: What if the connection was pre-authenticated?!
        return false;
      
      return $this->imapCommand ('STARTTLS', null, array ($this, 'imapTLSHelper'), $Callback);
    }
    // }}}
    
    // {{{ imapTLSHelper
    /**
     * Internal Callback: Establish TLS-Connection
     * 
     * @param enum $Response
     * @param array $Code
     * @param string $Text
     * @param callback $Callback
     * 
     * @access private
     * @return void
     **/
    private function imapTLSHelper ($Response, $Code, $Text, $Callback) {
      // Validate the callback
      if (!is_callable ($Callback))
        $Callback = null;
      
      // Check if the server is able to start TLS
      if ($Response != self::IMAP_STATUS_OK) {
        if ($Callback !== null)
          call_user_func ($Callback, $this, null);
        
        return $this->___callback ('tlsFailed');
      }
      
      // Start TLS-Negotiation
      $this->imapTLSCallback = $Callback;
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
        
        return $this->imapCommand ('CAPABILITY', null, array ($this, 'imapCallbackExt'), array ($Callback, false, true));
      }
      
      // Raise any registered callback
      if ($Callback !== null)
        call_user_func ($Callback, $this, $Status);
    }
    // }}}
    
    // {{{ authenticate
    /**
     * Try to login using AUTHENTICATE
     * 
     * @param string $Username
     * @param string $Password
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function authenticate ($Username, $Password, $Callback = null) {
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
        4 => $Callback
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
        return $this->imapCommand ('CAPABILITY', null, array ($this, 'imapAfterAuthentication'), $Private [4]);
      }
      
      // Check if there are mechanisms available
      if (count ($Private [3]) == 0) {
        if ($Private [4] !== null)
          call_user_func ($Private [4], $this, false);
        
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
     * 
     * @access public
     * @return void
     **/
    public function login ($Username, $Password, $Callback = null) {
      // Check if we are in connected state
      if ($this->State !== self::IMAP_STATE_CONNECTED)
        return false;
       
      if (($Callback !== null) && !is_callable ($Callback))
        $Callback = null;
      
      $this->imapCommand ('LOGIN', array ($Username, $Password), array ($this, 'loginHandler'), $Callback);
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
      if ($Callback !== null)
        call_user_func ($Callback, $this, false);
      
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
      if ($Callback !== null)
        call_user_func ($Callback, $this, true);
     
      return $this->___callback ('imapAuthenticated');
    }
    // }}}
    
    
    // {{{ createMailbox
    /**
     * Create a new mailbox
     * 
     * @param string $Name
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function createMailbox ($Name, $Callback = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Issue the command
      return $this->imapCommand ('CREATE', array ($Name), array ($this, 'mailboxAction'), array (0, $Callback, $Name));
    }
    // }}}
   
    // {{{ deleteMailbox
    /**
     * Remove a mailbox from server
     * 
     * @param string $Name
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function deleteMailbox ($Name, $Callback = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Issue the command
      return $this->imapCommand ('DELETE', array ($Name), array ($this, 'mailboxAction'), array (1, $Callback, $Name));
    }
    // }}}
    
    // {{{ statusMailbox
    /**
     * Retrive the status for a given mailbox
     * 
     * @param string $Mailbox
     * @param array $Statuses (optional)
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function statusMailbox ($Mailbox, $Statuses = null, $Callback = null) {
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
      
      return $this->imapCommand ('STATUS', array ($Mailbox, $Statuses), $Callback);
    }
    // }}}
    
    // {{{ renameMailbox
    /**
     * Rename a mailbox on our server
     * 
     * @param string $Name
     * @param string $newName
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void  
     **/
    public function renameMailbox ($Name, $newName, $Callback = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Issue the command
      return $this->imapCommand ('RENAME', array ($Name, $newName), array ($this, 'mailboxAction'), array (2, $Callback, $Name, $newName));
    }
    // }}}
    
    // {{{ subscribeMailbox
    /**
     * Subscribe to a mailbox on our server
     * 
     * @param string $Name
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function subscribeMailbox ($Name, $Callback = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Issue the command
      return $this->imapCommand ('SUBSCRIBE', array ($Name, $newName), array ($this, 'mailboxAction'), array (3, $Callback, $Name));
    }
    // }}}
    
    // {{{ unsubscribeMailbox
    /**
     * Unsubscribe from a mailbox on our server
     * 
     * @param string $Name
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function unsubscribeMailbox ($Name, $Callback = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&   
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Issue the command
      return $this->imapCommand ('UNSUBSCRIBE', array ($Name, $newName), array ($this, 'mailboxAction'), array (4, $Callback, $Name));
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
     * 
     * @access public
     * @return void
     **/
    public function appendMailbox ($Mailbox, $Message, $Flags = null, $Timestamp = null, $Callback = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Generate parameters
      $Args = array ($Mailbox);
      
      if (is_array ($Flags))
        $Args [] = $Flags;
      
      if ($Timestamp !== null)
        $Args [] = date ('d-m-Y H:i:s O', $Timestamp);
      
      $Args [] = '{' . strlen ($Message) . '}';
      $Args [] = $Message;
      
      // Issue the command
      return $this->imapCommand ('APPEND', $Args, array ($this, 'mailboxAction'), array (5, $Callback, $Mailbox));
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
        
        // Mailbox was removed
        } elseif ($Action == 1)
          unset ($this->mailboxes [$Private [0]]);
        
        // Mailbox was renamed
        elseif ($Action == 2) {
          if (isset ($this->mailboxes [$Private [0]])) {
            $this->mailboxes [$Private [1]] = $this->mailboxes [$Private [0]];
            unset ($this->mailboxes [$Private [0]]);
          }
        
        // Mailbox was subscribed
        } elseif ($Action == 3) {
        
        // Mailbox was unsubscribed
        } elseif ($Action == 4) {
        
        // Mail was appended to mailbox
        } elseif ($Action == 5) {
        
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
     * 
     * @access public
     * @return void
     **/
    public function listMailboxes ($Name = '%', $Root = '', $Callback = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Issue the command
      return $this->imapCommand ('LIST', array ($Root, $Name), array ($this, 'listHandler'), array (0, $Callback, $Name, $Root));
    }
    // }}}
    
    // {{{ listSubscribedMailboxes
    /**
     * Request a list of subscribed mailboxes from server
     * 
     * @param string $Name (optional)
     * @param string $Root (optional)  
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void   
     **/
    public function listSubscribedMailboxes ($Name = '%', $Root = '', $Callback = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Issue the command
      return $this->imapCommand ('LSUB', array ($Root, $Name), array ($this, 'listHandler'), array (1, $Callback, $Name, $Root));
    }
    // }}}
    
    // {{{ listNamespaces
    /**
     * Request a list of all namespaces on this server
     * 
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void   
     **/
    public function listNamespaces ($Callback = null) {
      // Check our state
      if ((($this->State != self::IMAP_STATE_AUTHENTICATED) &&
           ($this->State != self::IMAP_STATE_ONMAILBOX)) ||
          !$this->haveCapability ('NAMESPACE'))
        return false;
      
      // Issue the command
      return $this->imapCommand ('NAMESPACE', array (), array ($this, 'listHandler'), array (2, $Callback));
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
      if (($Private [1] !== null) && is_callable ($Private [1]))
        call_user_func ($Private [1], $this);
    }
    // }}}
    
    // {{{ selectMailbox
    /**
     * Open a given mailbox
     * 
     * @param string $Mailbox
     * @param bool $ReadOnly (optional)
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function selectMailbox ($Mailbox, $ReadOnly = false, $Callback = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_AUTHENTICATED) &&
          ($this->State != self::IMAP_STATE_ONMAILBOX))
        return false;
      
      // Issue the command 
      return $this->imapCommand (($ReadOnly ? 'EXAMINE' : 'SELECT'), array ($Mailbox), array ($this, 'selectHandler'), array ($Callback, $Mailbox));
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
      // Check the callback
      $Callback = $Private [0];
      
      // Check if the command was successfull
      if ($Response == self::IMAP_STATUS_OK) {
        $this->mailboxCurrent = $Private [1];
        $this->mailboxReadOnly = ((count ($Code) > 0) && ($Code [0] == 'READ-ONLY'));
        
        $this->imapSetState (self::IMAP_STATE_ONMAILBOX);
      }
      
      // Fire callbacks
      $this->imapCallbackStatus (array ('imapMailboxOpened', 'imapMailboxOpenFailed'), $Callback, $Response == self::IMAP_STATUS_OK, $this->mailboxCurrent, !$this->mailboxReadOnly);
    }
    // }}}
    
    
    // {{{ check
    /**
     * Issue a CHECK-Command
     * 
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function check ($Callback = null) {
      // Check our state
      if ($this->State != self::IMAP_STATE_ONMAILBOX)
        return false;
      
      return $this->imapCommand ('CHECK', null, $Callback);
    }
    // }}}
    
    // {{{ close
    /**
     * Close the currently selected mailbox
     * 
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function close ($Callback = null) {
      // Check our state
      if ($this->State != self::IMAP_STATE_ONMAILBOX)  
        return false;
      
      return $this->imapCommand ('CLOSE', null, array ($this, 'closeHandler'), $Callback);
    }
    // }}}
    
    // {{{ unselect
    /**
     * Unselect the current mailbox
     * 
     * @remark this is similar to CLOSE, but does not implicit expunge deleted messages
     * 
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void  
     **/
    public function unselect ($Callback = null) {
      // Check our state
      if (($this->State != self::IMAP_STATE_ONMAILBOX) || !$this->haveCapability ('UNSELECT'))
        return false;
      
      return $this->imapCommand ('UNSELECT', null, array ($this, 'closeHandler'), $Callback);
    }
    // }}}
    
    // {{{ closeHandler
    /**
     * Internal Callback: CLOSE-Command was executed
     * 
     * @param enum $Response
     * @param array $Code
     * @param string $Text
     * @param array $Callback
     * 
     * @access private
     * @return void
     **/  
    private function closeHandler ($Response, $Code, $Text, $Callback) {
      // Remember the current mailbox
      $oMailbox = $this->mailboxCurrent;
      
      // Change the status on success
      if ($Response == self::IMAP_STATUS_OK) {
        $this->mailboxCurrent = null;
        $this->imapSetState (self::IMAP_STATE_AUTHENTICATED);
      }
      
      // Handle callbacks
      $this->imapCallbackStatus (array ('imapMailboxClosed', 'imapMailboxCloseFailed'), $Callback, $Response == self::IMAP_STATUS_OK, $oMailbox);
    }
    // }}}
    
    // {{{ expunge
    /**
     * Wipe out all mails marked as deleted on the current mailbox
     * 
     * @param callback $Callback (optional)
     * 
     * @access public
     * @return void
     **/
    public function expunge ($Callback = null) {
      // Check our state
      if ($this->State != self::IMAP_STATE_ONMAILBOX)
        return false;
      
      // Issue the command
      return $this->imapCommand ('EXPUNGE', null, array ($this, 'imapCallbackExt'), array ($Callback, true));
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
     * 
     * @access public
     * @return void
     **/
    public function setFlags ($byUID, $IDs, $Flags, $Mode = self::MODE_SET, $Callback = null) {
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
      
      $Args [] = $Flags;
      
      // Issue the command
      return $this->imapCommand (($byUID ? 'UID STORE' : 'STORE'), $Args, array ($this, 'imapCallbackExt'), array ($Callback, true));
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
     * 
     * @access public
     * @return void
     **/
    public function copy ($byUID, $IDs, $Mailbox, $Callback = null) {
      // Check our state
      if ($this->State != self::IMAP_STATE_ONMAILBOX)
        return false;
      
      // Prepare the args
      if (!($Seq = $this->imapSequence ($IDs)))
        return false;
      
      $Args = array ($Seq, $Mailbox);
      
      // Issue the command
      return $this->imapCommand (($byUID ? 'UID COPY' : 'COPY'), $Args, array ($this, 'imapCallbackExt'), array ($Callback, true));
    }
    // }}}
    
    // {{{ fetchMessages
    /**
     * Fetch messages from server
     * 
     * @param bool $byUID
     * @param sequence $IDs
     * @param callback $Callback (optional)
     * @param string ... (optional)
     *
     * @access public
     * @return void
     **/
    public function fetchMessages ($byUID, $IDs, $Callback = null, $Item = 'ALL') {
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
      return $this->imapCommand (($byUID ? 'UID FETCH' : 'FETCH'), $Args, array ($this, 'imapCallbackExt'), array ($Callback, true), null, null, true);
    }
    // }}}
    
    // {{{ searchMessages
    /**
     * Search a set of messages
     * 
     * @param bool $byUID
     * @param string $Charset (optional)
     * @param $Callback (optional)
     * @param string ... (optional)
     * 
     * @access public
     * @return void
     **/
    public function searchMessages ($byUID, $Charset = null, $Callback = null, $Match1 = 'ALL') {
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
      return $this->imapCommand (($byUID ? 'UID SEARCH' : 'SEARCH'), $Args, array ($this, 'imapCallbackExt'), array ($Callback, true), null, null, true);
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
     * 
     * @access private
     * @return void
     **/
    private function imapCommand ($Command, $Args, $Callback, $Private = null, $ContinueCallback = null, $Private2 = null, $dontParse = false) {
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
        if ($dontParse)
          $firstArg = implode (' ', $Args);
        
        // Prepare arguements for submission
        elseif (!($Args = $this->imapArgs ($Args)))
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
        
        echo 'OUT: ', 'C', dechex ($ID), ' ', $Command, ' ', $firstArg, "\n";
      
      // Write out command without args
      } else {
        $this->mwrite ('C', dechex ($ID), ' ', $Command, "\r\n");
        
        echo 'OUT: ', 'C', dechex ($ID), ' ', $Command, "\n";
      }
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
      array_unshift ($Private, $this);
      
      if ($appendStatus)
        array_push ($Private, $Response == self::IMAP_STATUS_OK);
      
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
        array_unshift ($eArgs, $this);
        array_push ($eArgs, $Status == true);
        
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
    protected function imapStateChanged ($newState, $oldState) { echo 'IMAP: State changed from ', $oldState, ' to ', $newState, "\n"; }
    // }}}
    
    // {{{ imapConnected
    /**
     * Callback: IMAP-Connection was established, Client is in Connected-State
     * 
     * @access protected
     * @return void
     **/
    protected function imapConnected () { echo 'IMAP: Connected', "\n"; }
    // }}}
    
    // {{{ imapAuthenticated
    /**
     * Callback: IMAP-Connection was successfully authenticated
     * 
     * @access protected
     * @return void
     **/
    protected function imapAuthenticated () { echo 'IMAP: Authenticated', "\n"; }
    // }}}
    
    // {{{ imapAuthenticationFailed
    /**
     * Callback: Authentication failed
     * 
     * @access protected
     * @return void
     **/
    protected function imapAuthenticationFailed () { echo 'IMAP: Authentication failed', "\n"; }
    // }}}
    
    // {{{ imapDisconnected
    /**
     * Callback: IMAP-Connection was closed, Client is in Disconnected-State
     * 
     * @access protected
     * @return void
     **/
    protected function imapDisconnected () { echo 'IMAP: Disconnected', "\n"; }
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
    protected function imapCapabilities ($Capabilities) { echo 'IMAP: Capabilities received', "\n"; }
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
    protected function imapAlert ($Message) { echo 'IMAP-Alert: ', $Message, "\n"; }
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
    protected function imapMailboxOpened ($Mailbox, $Writeable) { echo 'IMAP: Mailbox ', $Mailbox, ' openend for ', ($Writeable ? 'Read-Write' : 'Read'), "\n"; }
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
    protected function imapMailboxOpenFailed ($Mailbox) { echo 'IMAP: Open Mailbox ', $Mailbox, ' failed', "\n"; }
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
    protected function imapMailboxClosed ($Mailbox) { echo 'IMAP: Mailbox ', $Mailbox, ' was closed', "\n"; }
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
    protected function imapMailboxCloseFailed ($Mailbox) { echo 'IMAP: Mailbox ', $Mailbox, ' could not be closed', "\n"; }
    // }}}
  }

?>