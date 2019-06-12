<?PHP

  /**
   * qcEvents - Asyncronous SMTP Client-Stream
   * Copyright (C) 2015 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Promise.php');
  
  /**
   * SMTP-Client
   * -----------
   * Simple SMTP-Client-Implementation (RFC 5321)
   * 
   * @class qcEvents_Stream_SMTP_Client
   * @extends qcEvents_Hookable
   * @package qcEvents
   * @revision 03
   * @author Bernd Holzmueller <bernd@quarxconnect.de>
   * 
   * @changelog 20130703 Added Support for RFC 3207 StartTLS
   *            20130703 Added basic Support for RFC 2034 Enhanced Status Codes
   *            20130703 Added Support for RFC 1870 SMTP Size Declaration
   *            20130704 Added Support for RFC 1985 ETRN Command (remote queue startup)
   *            20130705 Added Support for RFC 4954 SMTP Authentication
   **/
  class qcEvents_Stream_SMTP_Client extends qcEvents_Hookable implements qcEvents_Interface_Stream_Consumer {
    /* The attached stream */
    private $Stream = null;
    
    /* Protocol state */
    const SMTP_STATE_DISCONNECTED = 0;
    const SMTP_STATE_CONNECTING = 1;
    const SMTP_STATE_CONNECTED = 2;
    const SMTP_STATE_TRANSACTION = 3;
    const SMTP_STATE_DISCONNECTING = 4;
    
    const SMTP_HANDSHAKE_START = 0;
    const SMTP_HANDSHAKE_EHLO = 1;
    const SMTP_HANDSHAKE_FALLBACK = 2;
    
    private $State = qcEvents_Stream_SMTP_Client::SMTP_STATE_DISCONNECTED;
    
    /* Internal read-buffer */
    private $Buffer = '';
    
    /* Domainname of this client */
    private $clientName = null;
    
    /* Is this connection authenticated */
    private $authenticated = false;
    
    /* State for handshake */
    private $connectingState = qcEvents_Stream_SMTP_Client::SMTP_HANDSHAKE_START;
    
    /* Command-Buffer */
    private $Command = null;
    private $Commands = array ();
    
    /* Response-Buffer */
    private $responseCode = null;
    private $responseLines = array ();
    
    /* Last response from server */
    private $lastCode = null;
    private $lastLines = null;
    
    /* Queued mails */
    private $mailCurrent = null;
    private $mailQueue = array ();
    
    /* Domain of server */
    private $serverDomain = null;
    
    /* Features supported by the server */
    private $serverFeatures = null;
    
    private $initCallback = null;
    
    // {{{ getClientName
    /**
     * Retrive the name of this client
     * 
     * @access public
     * @return string
     **/
    public function getClientName () {
      if ($this->clientName !== null)
        return $this->clientName;
      elseif (function_exists ('gethostname'))
        return gethostname ();
      
      return 'smtpc.quarxconnect.org';
    }
    // }}}
    
    // {{{ setClientName
    /**
     * Store the DNS-Name of this client
     * 
     * @param string $Name
     * 
     * @access public
     * @return bool
     **/
    public function setClientName ($Name) {
      $this->clientName = $Name;
      
      return true;
    }
    // }}}
    
    // {{{ getLastCode
    /**
     * Retrive the last result-code
     * 
     * @access public
     * @return int
     **/
    public function getLastCode () {
      return $this->lastCode;
    }
    // }}}
    
    // {{{ hasFeature
    /**
     * Check if our peer supports a given feature
     * 
     * @param string $Feature
     * 
     * @access public
     * @return bool
     **/
    public function hasFeature ($Feature) {
      return (is_array ($this->serverFeatures) && isset ($this->serverFeatures [$Feature]));
    }
    // }}}
    
    // {{{ startTLS
    /**
     * Try to enable encryption on this connection
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function startTLS () : qcEvents_Promise {
      // Check if the server supports StartTLS
      if (!is_array ($this->serverFeatures) || !isset ($this->serverFeatures ['STARTTLS']))
        return qcEvents_Promise::reject ('Server does not support STARTTLS');
      
      // Check if TLS is already active
      if ($this->Stream->tlsEnable ())
        return qcEvents_Promise::resolve ();
      
      // Issue the command
      return $this->issueSMTPCommand (
        'STARTTLS',
        null,
        self::SMTP_STATE_CONNECTED,
        self::SMTP_STATE_CONNECTING
      )->then (
        function ($Code, $Lines) {
          // Check if the server does not want us to enable TLS
          if ($Code >= 300) {
            $this->___callback ('tlsFailed');
            
            throw new exception ('Server rejected request');
          }
          
          // Lock the command-pipeline
          $this->Command = true;
          
          // Try to start TLS-negotiation
          return new qcEvents_Promise (function ($resolve, $reject) {
            $this->Stream->tlsEnable (true, function (qcEvents_Socket $Socket, $Status) use ($resolve, $reject) {
              // Unlock the command-pipeline
              $this->Command = null;
              
              // Check if TLS-negotiation failed
              if (!$Status)
                return $reject ('TLS-negotiation failed');
              
              // Restart the connection
              $this->connectingState = self::SMTP_HANDSHAKE_EHLO;
              $this->serverFeatures = null;
              
              $this->issueSMTPCommand (
                'EHLO',
                array ($this->getClientName ()),
                null,
                null
              )->then (
                $resolve,
                $reject
              );
            });
          });
        }
      );
    }
    // }}}
    
    // {{{ authenticate
    /**
     * Try to authenticate this connection
     * 
     * @param string $Username
     * @param string $Password
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function authenticate ($Username, $Password) : qcEvents_Promise {
      // Check if the server supports Authentication
      if (!is_array ($this->serverFeatures) || !isset ($this->serverFeatures ['AUTH']))
        return qcEvents_Promise::reject ('Server does not support authentication');
      
      // Don't authenticate twice
      if ($this->authenticated)
        return qcEvents_Promise::reject ('Already authenticated');
      
      // Create an authenticator
      require_once ('qcAuth/SASL/Client.php');
      
      $Client = new qcAuth_SASL_Client;
      $Client->setUsername ($Username);
      $Client->setPassword ($Password);
      
      $Mechanisms = $this->serverFeatures ['AUTH'];
      
      // Try to pick the first mechanism
      if (count ($Mechanisms) == 0)
        return qcEvents_Promise::reject ('No authentication-mechanisms available');
      
      $Mechanism = array_shift ($Mechanisms);
      
      while (!$Client->setMechanism ($Mechanism)) {
        if (count ($Mechanisms) == 0)
          return qcEvents_Promise::reject ('No suitable authentication-mechanism available');
        
        $Mechanism = array_shift ($Mechanisms);
      }
      
      // Setup SASL-Callback-Handler
      $saslContinue = function () use ($Client) {
        return base64_encode ($Client->getResponse ());
      };
      
      $saslFinish = function ($Code, $Lines) use ($saslContinue, &$saslFinish, $Client, &$Mechanisms, &$Mechanism) {
        // Check if the authentication was successfull
        if (($Code >= 200) && ($Code < 300)) {
          // Mark ourself as authenticated
          $this->authenticated = true;
          
          // Issue a EHLO-Command   
          $this->connectingState = self::SMTP_HANDSHAKE_EHLO;
          $this->serverFeatures = null;
          
          return $this->issueSMTPCommand (
            'EHLO',
            array ($this->getClientName ()),
            null,
            null
          )->then (
            function () { }
          );
        
        // Check if authentication failed at all
        } elseif ($Code == 535)
          throw new exception ('Authentication failed');
        
        // Check if there are mechanisms left
        if (count ($Mechanisms) == 0)
          throw new exception ('No suitable authentication succeeded');
        
        // Try to pick the next mechanism
        $Mechanism = array_shift ($Mechanisms);
      
        while (!$Client->setMechanism ($Mechanism)) {
          if (count ($Mechanisms) == 0)
            throw new exception ('No suitable authentication remaining');
          
          $Mechanism = array_shift ($Mechanisms);
        }
      
        // Ask the server for that mechanism
        return $this->issueSMTPCommand (
          'AUTH',
          array ($Mechanism, base64_encode ($Client->getInitialResponse ())),
          self::SMTP_STATE_CONNECTED,
          self::SMTP_STATE_CONNECTING,
          $saslContinue
        )->then (
          $saslFinish
        );
      };
      
      // Issue the first AUTH-Command
      return $this->issueSMTPCommand (
        'AUTH',
        array ($Mechanism, base64_encode ($Client->getInitialResponse ())),
        self::SMTP_STATE_CONNECTED,
        self::SMTP_STATE_CONNECTING,
        $saslContinue
      )->then (
        $saslFinish
      );
    }
    // }}}
    
    // {{{ startMail
    /**
     * Start the submission of an e-mail
     * 
     * @param string $Originator Originator of the mail
     * @param array $Params (optional) Additional parameters for this command (for extensions)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function startMail ($Originator, $Params = null) : qcEvents_Promise {
      // Make sure the originator is valid
      if ($Originator [0] != '<')
        $Originator = '<' . $Originator . '>';
      
      // Handle params
      if ($Params !== null) {
        $iParams = $Params;
        $Params = array ();
        
        if (is_array ($iParams))
          foreach ($iParams as $k=>$v)
            $Params [] = $k . '=' . $v;
      }
       
      // Issue the command
      return $this->issueSMTPCommand (
        'MAIL FROM:' . $Originator,
        $Params,
        self::SMTP_STATE_CONNECTED,
        self::SMTP_STATE_TRANSACTION
      )->then (
        function ($Code) {
          if ($Code >= 400)
            throw new exception ('Server returned an error');
        }
      );
    }
    // }}}
    
    // {{{ addReceiver
    /**
     * Append a receiver for an ongoing transaction
     *    
     * @param string $Receiver   
     * @param array $Params (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function addReceiver ($Receiver, $Params = null) : qcEvents_Promise {
      // Make sure the originator is valid
      if ($Receiver [0] != '<')
        $Receiver = '<' . $Receiver . '>';

      // Issue the command
      return $this->issueSMTPCommand (
        'RCPT TO:' . $Receiver,
        $Params,
        self::SMTP_STATE_TRANSACTION
      )->then (
        function ($Code) {
          if ($Code >= 400)
            throw new exception ('Server returned an error');
        }
      );
    }
    // }}}
    
    // {{{ sendData
    /**
     * Submit Mail-Data
     * 
     * @param string $Mail
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function sendData ($Mail) : qcEvents_Promise {
      // Issue the command
      return $this->issueSMTPCommand (
        'DATA',
        null,
        self::SMTP_STATE_TRANSACTION,
        self::SMTP_STATE_CONNECTED,
        function (qcEvents_Stream_SMTP_Client $Self, $Code, $Lines) use ($Mail) {
          $p = 0;
          
          while (($p = strpos ($Mail, "\r\n.\r\n", $p)) !== false)
            $Mail = substr ($Mail, 0, $p + 2) . '.' . substr ($Mail, $p + 2);
          
          return $Mail . (substr ($Mail, -2, 2) == "\r\n" ? '' : "\r\n") . ".\r\n";
        }
      )->then (
        function ($Code) {
          if ($Code >= 400)
            throw new exception ('Server returned an error');
        }
      );
    }
    // }}}
    
    // {{{ reset
    /**
     * Abort any ongoing mail-transaction
     *    
     * @access public
     * @return qcEvents_Promise
     **/
    public function reset () : qcEvents_Promise {
      // Issue the command
      return $this->issueSMTPCommand (
        'RSET',
        null,
        null,
        self::SMTP_STATE_CONNECTED
      )->then (
        function ($Code) {
          if ($Code >= 400)
            throw new exception ('Server returned an error');
        }
      );
    } 
    // }}}
    
    // {{{ verify
    /** 
     * Verfiy a username or mailbox
     * 
     * @param string $Mailbox
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function verify ($Mailbox) : qcEvents_Promise {
      // Issue the command
      return $this->issueSMTPCommand (
        'VRFY',
        array ($Mailbox)
      )->then (
        function ($Code, $Lines) use ($Mailbox) {
          $Fullname = null;
          
          // Handle a successfull response
          if ($Status = (($Code >=200) && ($Code < 300))) {
            $Result = array_shift ($Lines);   
            
            if (($p = strpos ($Result, '<')) !== false) {
              $Fullname = rtrim (substr ($Result, 0, $p));
              $Result = substr ($Result, $p + 1, strrpos ($Result, '>') - $p - 1);
            }
            
          // Handle failure
          } else {
            $Result = array ();
            
            foreach ($Lines as $Line)
              if (($p = strpos ($Line, '<')) !== false)
                $Result [] = substr ($Line, $p + 1, strrpos ($Line, '>') - $p - 1);
          }
          
          // Raise the callback
          return new qcEvents_Promise_Solution (array ($Result, $Fullname, $Status));
        }
      );
    }
    // }}}
    
    // {{{ noOp
    /**
     * Do nothing, but let the server know
     * 
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function qcEvents_Stream_SMTP_Client $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function noOp (callable $Callback = null, $Private = null) {
      // Issue the command
      return $this->issueSMTPCommand (
        'NOOP'
      )->then (
        function ($Code) {
          if ($Code >= 400)
            throw new exception ('Server returned an error');
        }
      );
    }
    // }}}
    
    // {{{ startQueue
    /**
     * Start/Flush the remote queue for a domain at the servers site
     * 
     * @param string $Domaim
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function startQueue ($Domain) : qcEvents_Promise {
      // Check if the server supports ETRN
      if (!is_array ($this->serverFeatures) || !isset ($this->serverFeatures ['ETRN']))
        return qcEvents_Promise::reject ('ETRN not supported by server');
      
      // Issue the command
      return $this->issueSMTPCommand (
        'ETRN',
        array ($Domain)
      )->then (
        function ($Code) {
          if ($Code >= 400)
            throw new exception ('Server returned an error');
        }
      );
    }   
    // }}}
    
    // {{{ close
    /**
     * Ask the server to close this session
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function close () : qcEvents_Promise {
      // Check if our stream is already closed
      if (!is_object ($this->Stream)) {
        // Check if we are in disconnected state
        if ($this->State != self::SMTP_STATE_DISCONNECTED) {
          // Set disconnected state
          $this->smtpSetState (self::SMTP_STATE_DISCONNECTED);
          $this->___callback ('smtpDisconnected');
          $this->___callback ('eventClosed');
        }
        
        return qcEvents_Promise::resolve ();
      }
      
      // Issue the command
      return $this->issueSMTPCommand (
        'QUIT',
        null,
        null,
        self::SMTP_STATE_DISCONNECTING
      )->then (
        function ($Code) {
          if (($Code < 200) || ($Code >= 300))
            throw new exception ('Server returned an error');
        }
      );
    }
    // }}}
    
    
    // {{{ sendMail
    /**
     * Submit an entire mail
     * 
     * @param string $Originator
     * @param array $Receivers
     * @param string $Mail
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function sendMail ($Originator, $Receivers, $Mail) : qcEvents_Promise {
      // Check the size
      if (is_array ($this->serverFeatures) && isset ($this->serverFeatures ['SIZE']) && (count ($this->serverFeatures ['SIZE']) > 0) &&
          (strlen ($Mail) > $this->serverFeatures ['SIZE'][0]) && ($this->serverFeatures ['SIZE'][0] > 0))
        return qcEvents_Promise::reject ('SIZE-constraint failed');
      
      // Enqueue the mail
      return new qcEvents_Promise (function ($resolve, $reject) use ($Originator, $Receivers, $Mail) {
        $this->mailQueue [] = array ($Originator, $Receivers, $Mail, $Receivers, array (), $resolve, $reject);
        
        // Try to start the submission
        $this->runMailQueue ();
      });
    }
    // }}}
    
    // {{{ runMailQueue
    /**
     * Check wheter to enqueue the next mail
     * 
     * @access private
     * @return void
     **/
    private function runMailQueue () {
      // Check if there is a mail being transmitted
      if ($this->mailCurrent !== null)
        return;
      
      // Check if the queue is empty
      if (count ($this->mailQueue) == 0)
        return;
      
      // Enqueue the next mail
      $this->mailCurrent = array_shift ($this->mailQueue);
      
      // Generate parameters
      if (is_array ($this->serverFeatures) && isset ($this->serverFeatures ['SIZE']))
        $Params = array ('SIZE' => strlen ($this->mailCurrent [2]));
      else
        $Params = null;
      
      // Start the submission
      $this->startMail ($this->mailCurrent [0], $Params)->then (
        function () {
          // Submit receivers
          $Receiver = array_shift ($this->mailCurrent [3]);
          $sendMail = function () {
            return $this->sendData ($this->mailCurrent [2])->then (
              function () {
                // Remember the current mail
                $mc = $this->mailCurrent;
                
                // Reset the mail-queue
                $this->mailCurrent = null;
                
                // Raise the callback
                call_user_func ($mc [5], $mc [4]);
                
                // Move forward to next queue-item
                $this->runMailQueue ();
              },
              function () {
                // Remember the current mail
                $mc = $this->mailCurrent;
                
                // Reset the mail-queue
                $this->mailCurrent = null;
            
                // Raise the callback
                call_user_func ($mc [6], 'Could not send mail');
                
                // Move forward to next queue-item
                $this->runMailQueue ();
              }
            );
          };
          
          $receiverError = null;
          $receiverAdded = function () use (&$Receiver, &$receiverAdded, &$receiverError, $sendMail) {
            // Append to successfull receivers
            $this->mailCurrent [4][] = $Receiver;
            
            // Check wheter to submit the next receiver
            if (count ($this->mailCurrent [3]) > 0) {
              $Receiver = array_shift ($this->mailCurrent [3]);
              
              return $this->addReceiver ($Receiver)->then ($receiverAdded, $receiverError);
            }
            
            // Submit the mail
            return $sendMail ();
          };
          
          $receiverError = function () use (&$Receiver, &$receiverAdded, &$receiverError, $sendMail) {
            // Push last error-code
            $this->mailCurrent [7] = $this->lastCode;
            
            // Check wheter to submit the next receiver
            if (count ($this->mailCurrent [3]) > 0) {
              $Receiver = array_shift ($this->mailCurrent [3]);
              
              return $this->addReceiver ($Receiver)->then ($receiverAdded, $receiverError);
            }
            
            // Check if the server accepted any receiver
            if (count ($this->mailCurrent [4]) == 0) {
              // Restore the last error-code
              $this->lastCode = $this->mailCurrent [7];
              
              // Remember the current mail
              $mc = $this->mailCurrent;
              
              // Reset the mail-queue
              $this->mailCurrent = null;
              
              $this->reset ()->then (
                function () {
                  $this->runMailQueue ();  
                }
              );
              
              // Raise the callback
              return call_user_func ($mc [6], 'No receiver was accepted by the server');
            }
            
            // Submit the mail
            return $sendMail ();
          };
          
          // Try to add the first receiver
          return $this->addReceiver ($Receiver)->then ($receiverAdded, $receiverError);
        },
        function () {
          // Remember the current mail
          $mc = $this->mailCurrent;
          
          // Reset the mail-queue
          $this->mailCurrent = null;
          
          $this->reset (function () {
            $this->runMailQueue ();
          });
          
          // Raise the callback
          return call_user_func ($mc [6], 'MAIL FROM failed');
        }
      );
    }
    // }}}
    
    
    // {{{ smtpSetState
    /**
     * Change our protocol-state
     * 
     * @param enum $State
     * 
     * @access private
     * @return void
     **/
    private function smtpSetState ($State) {
      // Check if anything was changed
      if ($this->State == $State)
        return;
      
      // Set the state
      $oState = $this->State;
      $this->State = $State;
      
      // Fire a callback
      $this->___callback ('smtpStateChanged', $State, $oState);
    }
    // }}}
    
    // {{{ smtpCheckState
    /**
     * Check our internal state how it will be when all commands are executed
     * 
     * @access private
     * @return enum
     **/
    private function smtpCheckState () {
      // Start with our current state
      $State = $this->State;
      
      // Check the current command
      if (($this->Command !== null) && isset ($this->Command [6]) && ($this->Command [6] !== null))
        $State = $this->Command [6];
      
      // Check all commands on the pipe
      foreach ($this->Commands as $Command)
        if (isset ($Command [6]) && ($Command [6] !== null))
          $State = $Command [6];
      
      return $State;
    }
    // }}}
    
    // {{{ issueSMTPCommand
    /**
     * Issue an SMTP-Command
     * 
     * @param string $Verb
     * @param array $Args (optional)
     * @param enum $requiredState (optional)
     * @param enum $setState (optional)
     * @param callable $ContinuationCallback (optional)
     * @param mixed $ContinuationPrivate (optional)
     * 
     * @access private
     * @return qcEvents_Promise
     **/
    private function issueSMTPCommand ($Verb, $Args = null, $requiredState = null, $setState = null, callable $ContinuationCallback = null, $ContinuationPrivate = null) : qcEvents_Promise {
      return new qcEvents_Promise (function ($resolve, $reject) use ($Verb, $Args, $ContinuationCallback, $ContinuationPrivate, $setState, $requiredState) {
        // Just push the command to the queue
        $this->Commands [] = array ($Verb, $Args, $ContinuationCallback, $ContinuationPrivate, $resolve, $reject, $setState, $requiredState);
        
        // Try to issue the next command
        $this->smtpExecuteCommand ();
      });
    }
    // }}}
    
    // {{{ smtpExecuteCommand
    /**
     * Try to execute the next pending command
     * 
     * @access private
     * @return void
     **/
    private function smtpExecuteCommand () {
      // Check if there is a command active
      if ($this->Command !== null)
        return;
      
      // Check if there are pending commands
      if (count ($this->Commands) == 0)
        return;
      
      // Retrive the next command
      while (($c = count ($this->Commands)) > 0) {
        $this->Command = array_shift ($this->Commands);
        
        // Check the required state
        if (($this->Command [7] === null) || ($this->State == $this->Command [7]))
          break;
        
        // Fire a failed callback
        call_user_func ($this->Command [5], 503, array ());
        
        if ($c > 1)
          continue;
        
        return ($this->Command = null);
      }
      
      // Write the command to the queue
      $Command = $this->Command [0];
      
      if (is_array ($this->Command [1]) && (count ($this->Command [1]) > 0))
        $Command .= ' ' . implode (' ', $this->Command [1]);
      
      $this->Stream->write ($Command . "\r\n");
      
      // Raise a callback for this
      $this->___callback ('smtpCommand', $this->Command [0], $this->Command [1], $Command);
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function initStreamConsumer (qcEvents_Interface_Stream $Source) : qcEvents_Promise {
      // Check if this is really a new stream
      if ($this->Stream === $Source)
        return qcEvents_Promise::resolve ();
      
      // Check if we have a stream assigned
      if (is_object ($this->Stream))
        $Promise = $this->Stream->unpipe ($this);
      else
        $Promise = qcEvents_Promise::resolve ();
      
      return $Promise->catch (function () { })->then (
        function () use ($Source) {
          // Reset our state
          $this->Stream = $Source;
          
          $this->Buffer = '';
          $this->authenticated = false;
          $this->connectingState = self::SMTP_HANDSHAKE_START;
          $this->Command = null;
          $this->Commands = array ();
          $this->responseCode = null;
          $this->responseLines = array ();
          $this->lastCode = null;
          $this->lastLines = null;
          $this->mailCurrent = null;
          $this->mailQueue = array ();
          $this->serverDomain = null;
          $this->serverFeatures = null;
          
          $this->smtpSetState (self::SMTP_STATE_CONNECTING);
          
          // Raise callbacks
          $this->___callback ('eventPipedStream', $Source);
          $this->___callback ('smtpConnecting');
          
          // Create a new promise
          return new qcEvents_Promise (function ($resolve, $reject) {
            $this->initCallback = array ($resolve, $reject);
          })
        }
      );
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
      // Check if the source is authentic
      if ($this->Stream !== $Source)
        return qcEvents_Promise::reject ('Invalid source');
      
      // Remove the stream
      $this->Stream = null;
      
      // Reset our state
      $this->close ();
      
      // Raise the final callback
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param mixed $Data  
     * @param qcEvents_Interface_Source $Source
     * 
     * @access public
     * @return void  
     **/
    public function consume ($Data, qcEvents_Interface_Source $Source) {
      // Append data to internal buffer
      $this->Buffer .= $Data;
      unset ($Data);
      
      // Read lines from buffer
      $s = 0;
      
      while (($p = strpos ($this->Buffer, "\n", $s)) !== false) {
        // Strip the line from the buffer
        $Line = substr ($this->Buffer, $s, $p - $s);
        $s = $p + 1;
        
        // Retrive the code from the line
        $Code = intval (substr ($Line, 0, 3));
        
        // Check if this is a multiline-message
        if ($Multiline = (($l = strlen ($Line)) > 3))
          $Multiline = ($Line [3] == '-');
        
        // Handle enhanced response-codes
        if (is_array ($this->serverFeatures) && isset ($this->serverFeatures ['ENHANCEDSTATUSCODES']) && (($p = strpos ($Line, ' ', 9)) !== false))
          $eCode = substr ($Line, 4, $p - 4);
        else
          $p = 3;
        
        // Push the response to local buffer
        if ($this->responseCode === null)
          $this->responseCode = $Code;
        elseif ($this->responseCode != $Code) {
          trigger_error ('SMTP-Protocol-Error: Invalid Response-Code on multi-line reply found. Quitting.');
          
          $this->Command = null;
          $this->Commands = array ();
          
          $this->close ();
          
          break;
        }
        
        $this->responseLines [] = substr ($Line, $p + 1);
        
        // Wait for further responses on multiline-responses
        if ($Multiline)
          continue;
        
        // Retrive buffered lines
        $this->lastCode = $Code;
        $this->lastLines = $Lines = $this->responseLines;
        unset ($Line);
        
        // Clear local buffer
        $this->responseCode = null;
        $this->responseLines = array ();
        
        // Raise a callback for this
        $this->___callback ('smtpResponse', $Code, $Lines);
        
        // Check for continuation
        if (($Code >= 300) && ($Code < 400)) {
          if (is_callable ($this->Command [2])) {
            $this->Stream->write ($this->___raiseCallback ($this->Command [2], $Code, $Lines, $this->Command [3]));
            
            continue;
          }
          
          trigger_error ('Server wants continuation, but we dont have a callback for this', E_USER_ERROR);
        }
        
        // Check if we are connecting
        if ($this->State == self::SMTP_STATE_CONNECTING) {
          // Peek the current command
          if ($this->Command)
            $Command = $this->Command;
          else
            $Command = null;
          
          // Handle the server's greeting
          if ($this->connectingState == self::SMTP_HANDSHAKE_START) {
            // Check if the server does not want us to connect
            // The RFC says only 554 here, we check them all though
            if ($Code >= 500) {
              $this->Buffer = '';
              
              return $this->close ()->finally (
                function () use ($Source) {
                  $this->___callback ('smtpConnectionFailed');
                  
                  if ($this->initCallback) {
                    call_user_func ($this->initCallback [1], 'Received ' . $Code);
                    $this->initCallback = null;
                  }
                }
              );
            }
            
            // Do the client-initiation
            $this->connectingState = self::SMTP_HANDSHAKE_EHLO;
            $this->Command = null;
            
            $this->issueSMTPCommand ('EHLO', array ($this->getClientName ()))->then (
              function () use ($Command, $Source) {
                // Raise callbacks
                $this->___callback ('smtpConnected');
                
                if ($this->initCallback) {
                  call_user_func ($this->initCallback [0], true);
                  $this->initCallback = null;
                }
                
                // Push back pending command
                if ($Command)
                  array_unshift ($this->Commands, $Command);
              },
              ($Command ? $Command [5] : null)
            );
            
            continue;
          
          // Handle the response to our own Greeting
          } elseif ($Code >= 500) {
            // Handle strange errors, were both EHLO and HELO failed
            if ($this->connectingState > self::SMTP_HANDSHAKE_EHLO) {
              $this->Buffer = '';
              
              return $this->close ()->finally (
                function () use ($Source) {
                  $this->___callback ('smtpConnectionFailed');
                  
                  if ($this->initCallback) {
                    call_user_func ($this->initCallback [1], 'Neither HELO nor EHLO were successfull');
                    $this->initCallback = null;
                  }
                }
              );
            }
            
            // Try HELO-Fallback
            $this->connectingState = self::SMTP_HANDSHAKE_FALLBACK;
            $this->Command = null;
            
            $this->issueSMTPCommand ('HELO', array ($this->getClientName ()))->then (
              function () use ($Command, $Source) {
                // Raise callbacks
                $this->___callback ('smtpConnected');
                
                if ($this->initCallback) {
                  call_user_func ($this->initCallback [0], true);
                  $this->initCallback = null;
                }
                
                // Push back pending command
                if ($Command)
                  array_unshift ($this->Commands, $Command);
              },
              ($Command ? $Command [5] : null)
            );
            
            continue;
          }
          
          // Retrive domainname of server
          $this->serverDomain = array_shift ($Lines);
          
          if (($p = strpos ($this->serverDomain, ' ')) !== false)
            $this->serverDomain = substr ($this->serverDomain, 0, $p);
          
          // Handle an EHLO-Response
          if ($this->connectingState == self::SMTP_HANDSHAKE_EHLO) {
            $this->serverFeatures = array ();
            
            foreach ($Lines as $Line) {
              $Info = explode (' ', trim ($Line));
              $Keyword = strtoupper (array_shift ($Info));
              
              $this->serverFeatures [$Keyword] = $Info;
            }
            
          // Server does not support EHLO
          } else
            $this->serverFeatures = false;
          
          // Change our protocol-state
          $this->smtpSetState (self::SMTP_STATE_CONNECTED);
        }
        
        // Handle normal replies
        if (($this->Command [6] !== null) && ($Code >= 200) && ($Code < 300))
          $this->smtpSetState ($this->Command [6]);
        
        call_user_func ($this->Command [4], $Code, $Lines);
        
        // Remove the current command
        $this->Command = null;
        
        // Try to issue any pending commands
        $this->smtpExecuteCommand ();
      }
      
      // Truncate the buffer
      $this->Buffer = substr ($this->Buffer, $s);
    }
    // }}}
    
    
    // {{{ smtpStateChanged
    /**
     * Callback: SMTP-Protocol-State was changed
     * 
     * @param enum $newState
     * @param enum $oldState
     * 
     * @access protected
     * @return void
     **/
    protected function smtpStateChanged ($newState, $oldState) { }
    // }}}
    
    // {{{ smtpConnecting
    /**
     * Callback: SMTP-Connection is being established
     * 
     * @access protected
     * @return void
     **/
    protected function smtpConnecting () { }
    // }}}
    
    // {{{ smtpConnected
    /**
     * Callback: SMTP-Connection is ready for action
     * 
     * @access protected
     * @return void
     **/
    protected function smtpConnected () { }
    // }}}
    
    // {{{ smtpConnectionFailed
    /**
     * Callback: SMTP-Connection could not be established
     * 
     * @access protected
     * @return void
     **/
    protected function smtpConnectionFailed () { }
    // }}}
    
    // {{{ smtpDisconnected
    /**
     * Callback: SMTP-Connection was closed
     * 
     * @access protected
     * @return void
     **/
    protected function smtpDisconnected () { }
    // }}}
    
    // {{{ smtpCommand
    /**
     * Callback: A SMTP-Command was issued to the server
     * 
     * @param string $Command
     * 
     * @access protected
     * @return void
     **/
    protected function smtpCommand ($Command) { }
    // }}}
    
    // {{{ smtpResponse
    /**
     * Callback: A Response was received from the server
     * 
     * @param int $Code
     * @param array $Lines
     * 
     * @access protected
     * @return void
     **/
    protected function smtpResponse ($Code, $Lines) { }
    // }}}
    
    // {{{ eventReadable
    /**
     * Callback: Never used.
     * 
     * @access protected
     * @return void
     **/
    protected function eventReadable () { }
    // }}}
    
    // {{{ eventClosed
    /**
     * Callback: The client-connection was closed
     * 
     * @access protected
     * @return void
     **/
    protected function eventClosed () { }
    // }}}
  }

?>