<?php

  /**
   * qcEvents - Asyncronous SMTP Client-Stream
   * Copyright (C) 2015-2022 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  namespace quarxConnect\Events\Stream\SMTP;
  use \quarxConnect\Events;
  
  /**
   * SMTP-Client
   * -----------
   * Simple SMTP-Client-Implementation (RFC 5321)
   * 
   * @class Client
   * @extends Events\Hookable
   * @package quarxconnect/events
   * @revision 03
   * @author Bernd Holzmueller <bernd@quarxconnect.de>
   **/
  class Client extends Events\Hookable implements Events\ABI\Stream\Consumer {
    /* The attached stream */
    private $sourceStream = null;
    
    /* Protocol state */
    public const SMTP_STATE_DISCONNECTED = 0;
    public const SMTP_STATE_DISCONNECTING = 1;
    public const SMTP_STATE_CONNECTING = 2;
    public const SMTP_STATE_CONNECTED = 3;
    public const SMTP_STATE_TRANSACTION = 4;
    
    private $smtpState = Client::SMTP_STATE_DISCONNECTED;
    
    /* Internal read-buffer */
    private $streamBuffer = '';
    
    /* Domainname of this client */
    private $clientName = null;
    
    /* Is this connection authenticated */
    private $clientAuthenticated = false;
    
    /* State for handshake */
    private const SMTP_HANDSHAKE_START = 0;
    private const SMTP_HANDSHAKE_EHLO = 1;
    private const SMTP_HANDSHAKE_FALLBACK = 2;
    
    private $connectingState = Client::SMTP_HANDSHAKE_START;
    
    /* Command-Buffer */
    private $currentCommand = null;
    private $queuedCommands = [ ];
    
    /* Response-Buffer */
    private $responseCode = null;
    private $responseLines = [ ];
    
    /* Last response from server */
    private $lastCode = null;
    private $lastLines = null;
    
    /* Queued mails */
    private $mailCurrent = null;
    private $mailQueue = [ ];
    
    /* Domain of server */
    private $serverDomain = null;
    
    /* Features supported by the server */
    private $serverFeatures = null;
    
    /* Promise for stream-initialization */
    private $initPromise = null;
    
    // {{{ getClientName
    /**
     * Retrive the name of this client
     * 
     * @access public
     * @return string
     **/
    public function getClientName () : string {
      if ($this->clientName !== null)
        return $this->clientName;
      
      if (function_exists ('gethostname'))
        return gethostname ();
      
      return 'smtpc.quarxconnect.org';
    }
    // }}}
    
    // {{{ setClientName
    /**
     * Store the DNS-Name of this client
     * 
     * @param string $clientName
     * 
     * @access public
     * @return void
     **/
    public function setClientName (string $clientName) : void {
      $this->clientName = $clientName;
    }
    // }}}
    
    // {{{ getLastCode
    /**
     * Retrive the last result-code
     * 
     * @access public
     * @return int
     **/
    public function getLastCode () : ?int {
      return $this->lastCode;
    }
    // }}}
    
    // {{{ hasFeature
    /**
     * Check if our peer supports a given feature
     * 
     * @param string $smtpFeature
     * 
     * @access public
     * @return bool
     **/
    public function hasFeature (string $smtpFeature) : ?bool {
      if (!is_array ($this->serverFeatures))
        return null;
      
      return isset ($this->serverFeatures [$smtpFeature]);
    }
    // }}}
    
    // {{{ startTLS
    /**
     * Try to enable encryption on this connection
     * 
     * @access public
     * @return Events\Promise
     **/
    public function startTLS () : Events\Promise {
      // Check if the server supports StartTLS
      if (!$this->hasFeature ('STARTTLS'))
        return Events\Promise::reject ('Server does not support STARTTLS');
      
      // Check if TLS is already active
      if ($this->sourceStream->isTLS ())
        return Events\Promise::resolve ();
      
      // Issue the command
      return $this->smtpCommand (
        'STARTTLS',
        null,
        self::SMTP_STATE_CONNECTED,
        self::SMTP_STATE_CONNECTING
      )->then (
        function (int $smtpCode) {
          // Check if the server does not want us to enable TLS
          if ($smtpCode >= 300) {
            $this->___callback ('tlsFailed');
            
            throw new \Exception ('Server rejected request');
          }
          
          // Lock the command-pipeline
          $this->currentCommand = true;
          
          // Try to start TLS-negotiation
          return $this->sourceStream->tlsEnable (true)->then (
            function () {
              // Unlock the command-pipeline
              $this->currentCommand = null;
              
              // Restart the connection
              $this->connectingState = self::SMTP_HANDSHAKE_EHLO;
              $this->serverFeatures = null;
              
              return $this->smtpCommand (
                'EHLO',
                [ $this->getClientName () ],
                null,
                null
              );
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ authenticate
    /**
     * Try to authenticate this connection
     * 
     * @param string $userName
     * @param string $userPassword
     * 
     * @access public
     * @return Events\Promise
     **/
    public function authenticate (string $userName, string $userPassword) : Events\Promise {
      // Check if the server supports Authentication
      if (!$this->hasFeature ('AUTH'))
        return Events\Promise::reject ('Server does not support authentication');
      
      // Don't authenticate twice
      if ($this->clientAuthenticated)
        return Events\Promise::reject ('Already authenticated');
      
      // Create an authenticator
      require_once ('qcAuth/SASL/Client.php');
      
      $saslClient = new \qcAuth_SASL_Client ();
      $saslClient->setUsername ($userName);
      $saslClient->setPassword ($userPassword);
      
      $saslMechanisms = $this->serverFeatures ['AUTH'];
      
      // Try to pick the first mechanism
      if (count ($saslMechanisms) == 0)
        return Events\Promise::reject ('No authentication-mechanisms available');
      
      $saslMechanism = array_shift ($saslMechanisms);
      
      while (!$saslClient->setMechanism ($saslMechanism)) {
        if (count ($saslMechanisms) == 0)
          return Events\Promise::reject ('No suitable authentication-mechanism available');
        
        $saslMechanism = array_shift ($saslMechanisms);
      }
      
      // Setup SASL-Callback-Handler
      $saslContinue = function () use ($saslClient) {
        return base64_encode ($saslClient->getResponse ());
      };
      
      $saslFinish = function (int $smtpCode) use ($saslContinue, &$saslFinish, $saslClient, &$saslMechanisms, &$saslMechanism) {
        // Check if the authentication was successfull
        if (($smtpCode >= 200) && ($smtpCode < 300)) {
          // Mark ourself as authenticated
          $this->clientAuthenticated = true;
          
          // Issue a EHLO-Command   
          $this->connectingState = self::SMTP_HANDSHAKE_EHLO;
          $this->serverFeatures = null;
          
          return $this->smtpCommand (
            'EHLO',
            [ $this->getClientName () ],
            null,
            null
          )->then (
            function () { }
          );
        
        // Check if authentication failed at all
        } elseif ($smtpCode == 535)
          throw new \Exception ('Authentication failed');
        
        // Check if there are mechanisms left
        if (count ($saslMechanisms) == 0)
          throw new \Exception ('No suitable authentication succeeded');
        
        // Try to pick the next mechanism
        $saslMechanism = array_shift ($saslMechanisms);
      
        while (!$saslClient->setMechanism ($saslMechanism)) {
          if (count ($saslMechanisms) == 0)
            throw new \Exception ('No suitable authentication remaining');
          
          $saslMechanism = array_shift ($saslMechanisms);
        }
      
        // Ask the server for that mechanism
        return $this->smtpCommand (
          'AUTH',
          [ $saslMechanism, base64_encode ($saslClient->getInitialResponse ()) ],
          self::SMTP_STATE_CONNECTED,
          self::SMTP_STATE_CONNECTING,
          $saslContinue
        )->then (
          $saslFinish
        );
      };
      
      // Issue the first AUTH-Command
      return $this->smtpCommand (
        'AUTH',
        [ $saslMechanism, base64_encode ($saslClient->getInitialResponse ()) ],
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
     * @param string $mailOriginator Originator of the mail
     * @param array $mailParameters (optional) Additional parameters for this command (for extensions)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function startMail (string $mailOriginator, array $mailParameters = null) : Events\Promise {
      // Make sure the originator is valid
      if ($mailOriginator [0] != '<')
        $mailOriginator = '<' . $mailOriginator . '>';
      
      // Handle params
      if ($mailParameters !== null) {
        $iParams = $mailParameters;
        $mailParameters = [ ];
        
        foreach ($iParams as $k=>$v)
          $mailParameters [] = $k . '=' . $v;
      }
       
      // Issue the command
      return $this->smtpCommand (
        'MAIL FROM:' . $mailOriginator,
        $mailParameters,
        self::SMTP_STATE_CONNECTED,
        self::SMTP_STATE_TRANSACTION
      )->then (
        function (int $smtpCode) {
          if ($smtpCode >= 400)
            throw new \Exception ('Server returned an error');
        }
      );
    }
    // }}}
    
    // {{{ addReceiver
    /**
     * Append a receiver for an ongoing transaction
     *    
     * @param string $mailReceiver   
     * @param array $mailParameters (optional)
     * 
     * @access public
     * @return Events\Promise
     **/
    public function addReceiver (string $mailReceiver, array $mailParameters = null) : Events\Promise {
      // Make sure the originator is valid
      if ($mailReceiver [0] != '<')
        $mailReceiver = '<' . $mailReceiver . '>';

      // Issue the command
      return $this->smtpCommand (
        'RCPT TO:' . $mailReceiver,
        $mailParameters,
        self::SMTP_STATE_TRANSACTION
      )->then (
        function (int $smtpCode) {
          if ($smtpCode >= 400)
            throw new \Exception ('Server returned an error');
        }
      );
    }
    // }}}
    
    // {{{ sendData
    /**
     * Submit Mail-Data
     * 
     * @param string $mailBody
     * 
     * @access public
     * @return Events\Promise
     **/
    public function sendData (string $mailBody) : Events\Promise {
      // Issue the command
      return $this->smtpCommand (
        'DATA',
        null,
        self::SMTP_STATE_TRANSACTION,
        self::SMTP_STATE_CONNECTED,
        function (Client $smtpStream, int $smtpCode, $Lines) use ($mailBody) {
          $p = 0;
          
          while (($p = strpos ($mailBody, "\r\n.\r\n", $p)) !== false)
            $mailBody = substr ($mailBody, 0, $p + 2) . '.' . substr ($mailBody, $p + 2);
          
          return $mailBody . (substr ($mailBody, -2, 2) == "\r\n" ? '' : "\r\n") . ".\r\n";
        }
      )->then (
        function (int $smtpCode) {
          if ($smtpCode >= 400)
            throw new \Exception ('Server returned an error');
        }
      );
    }
    // }}}
    
    // {{{ reset
    /**
     * Abort any ongoing mail-transaction
     *    
     * @access public
     * @return Events\Promise
     **/
    public function reset () : Events\Promise {
      // Issue the command
      return $this->smtpCommand (
        'RSET',
        null,
        null,
        self::SMTP_STATE_CONNECTED
      )->then (
        function (int $smtpCode) {
          if ($smtpCode >= 400)
            throw new \Exception ('Server returned an error');
        }
      );
    } 
    // }}}
    
    // {{{ verify
    /** 
     * Verfiy a username or mailbox
     * 
     * @param string $mailboxName
     * 
     * @access public
     * @return Events\Promise
     **/
    public function verify (string $mailboxName) : Events\Promise {
      // Issue the command
      return $this->smtpCommand (
        'VRFY',
        [ $mailboxName ]
      )->then (
        function (int $smtpCode, array $smtpLines) use ($mailboxName) {
          $Fullname = null;
          
          // Handle a successfull response
          if ($Status = (($smtpCode >=200) && ($smtpCode < 300))) {
            $Result = array_shift ($smtpLines);   
            
            if (($p = strpos ($Result, '<')) !== false) {
              $Fullname = rtrim (substr ($Result, 0, $p));
              $Result = substr ($Result, $p + 1, strrpos ($Result, '>') - $p - 1);
            }
            
          // Handle failure
          } else {
            $Result = [ ];
            
            foreach ($smtpLines as $smtpLine)
              if (($p = strpos ($smtpLine, '<')) !== false)
                $Result [] = substr ($smtpLine, $p + 1, strrpos ($smtpLine, '>') - $p - 1);
          }
          
          // Raise the callback
          return new Events\Promise\Solution ([ $Result, $Fullname, $Status ]);
        }
      );
    }
    // }}}
    
    // {{{ noOp
    /**
     * Do nothing, but let the server know
     * 
     * @access public
     * @return Events\Promise
     **/
    public function noOp () : Events\Promise {
      // Issue the command
      return $this->smtpCommand (
        'NOOP'
      )->then (
        function (int $smtpCode) {
          if ($smtpCode >= 400)
            throw new \Exception ('Server returned an error');
        }
      );
    }
    // }}}
    
    // {{{ startQueue
    /**
     * Start/Flush the remote queue for a domain at the servers site
     * 
     * @param string $domainName
     * 
     * @access public
     * @return Events\Promise
     **/
    public function startQueue (string $domainName) : Events\Promise {
      // Check if the server supports ETRN
      if (!$this->hasFeature ('ETRN'))
        return Events\Promise::reject ('ETRN not supported by server');
      
      // Issue the command
      return $this->smtpCommand (
        'ETRN',
        [ $domainName ]
      )->then (
        function (int $smtpCode) {
          if ($smtpCode >= 400)
            throw new \Exception ('Server returned an error');
        }
      );
    }   
    // }}}
    
    // {{{ close
    /**
     * Ask the server to close this session
     * 
     * @access public
     * @return Events\Promise
     **/
    public function close () : Events\Promise {
      // Check if our stream is already closed
      if (!is_object ($this->sourceStream)) {
        // Check if we are in disconnected state
        if ($this->smtpState != self::SMTP_STATE_DISCONNECTED) {
          // Set disconnected state
          $this->smtpSetState (self::SMTP_STATE_DISCONNECTED);
          $this->___callback ('smtpDisconnected');
          $this->___callback ('eventClosed');
        }
        
        return Events\Promise::resolve ();
      }
      
      // Issue the command
      return $this->smtpCommand (
        'QUIT',
        null,
        null,
        self::SMTP_STATE_DISCONNECTING
      )->then (
        function (int $smtpCode) {
          if (($smtpCode < 200) || ($smtpCode >= 300))
            throw new \Exception ('Server returned an error');
        }
      );
    }
    // }}}
    
    
    // {{{ sendMail
    /**
     * Submit an entire mail
     * 
     * @param string $mailOriginator
     * @param array $mailReceivers
     * @param string $mailBody
     * 
     * @access public
     * @return Events\Promise
     **/
    public function sendMail (string $mailOriginator, array $mailReceivers, string $mailBody) : Events\Promise {
      // Check the size
      if (
        $this->hasFeature ('SIZE') &&
        (count ($this->serverFeatures ['SIZE']) > 0) &&
        (strlen ($mailBody) > $this->serverFeatures ['SIZE'][0]) &&
        ($this->serverFeatures ['SIZE'][0] > 0)
      )
        return Events\Promise::reject ('SIZE-constraint failed');
      
      // Enqueue the mail
      $deferredPromise = new Events\Promise\Deferred ();
      
      $this->mailQueue [] = [
        $mailOriginator,
        $mailReceivers,
        $mailBody,
        $mailReceivers,
        [ ],
        $deferredPromise,
      ];
      
      // Try to run the queue
      $this->runMailQueue ();
      
      // Return the promise
      return $deferredPromise->getPromise ();
    }
    // }}}
    
    // {{{ runMailQueue
    /**
     * Check wheter to enqueue the next mail
     * 
     * @access private
     * @return void
     **/
    private function runMailQueue () : void {
      // Check if there is a mail being transmitted
      if ($this->mailCurrent !== null)
        return;
      
      // Check if the queue is empty
      if (count ($this->mailQueue) == 0)
        return;
      
      // Enqueue the next mail
      $this->mailCurrent = array_shift ($this->mailQueue);
      
      // Generate parameters
      if ($this->hasFeature ('SIZE'))
        $mailParameters = [ 'SIZE' => strlen ($this->mailCurrent [2]) ];
      else
        $mailParameters = null;
      
      // Start the submission
      $this->startMail ($this->mailCurrent [0], $mailParameters)->then (
        function () {
          // Submit receivers
          $nextReceiver = array_shift ($this->mailCurrent [3]);
          
          $sendMail = function () {
            return $this->sendData ($this->mailCurrent [2])->then (
              function () {
                // Remember the current mail
                $currentMail = $this->mailCurrent;
                
                // Reset the mail-queue
                $this->mailCurrent = null;
                
                // Raise the callback
                $currentMail [5]->resolve ($currentMail [4]);
                
                // Move forward to next queue-item
                $this->runMailQueue ();
              },
              function () {
                // Remember the current mail
                $currentMail = $this->mailCurrent;
                
                // Reset the mail-queue
                $this->mailCurrent = null;
            
                // Raise the callback
                $currentMail [5]->reject ('Could not send mail');
                
                // Move forward to next queue-item
                $this->runMailQueue ();
              }
            );
          };
          
          $receiverError = null;
          $receiverAdded = function () use (&$nextReceiver, &$receiverAdded, &$receiverError, $sendMail) {
            // Append to successfull receivers
            $this->mailCurrent [4][] = $nextReceiver;
            
            // Check wheter to submit the next receiver
            if (count ($this->mailCurrent [3]) > 0) {
              $nextReceiver = array_shift ($this->mailCurrent [3]);
              
              return $this->addReceiver ($nextReceiver)->then ($receiverAdded, $receiverError);
            }
            
            // Submit the mail
            return $sendMail ();
          };
          
          $receiverError = function () use (&$nextReceiver, &$receiverAdded, &$receiverError, $sendMail) {
            // Push last error-code
            $this->mailCurrent [6] = $this->lastCode;
            
            // Check wheter to submit the next receiver
            if (count ($this->mailCurrent [3]) > 0) {
              $nextReceiver = array_shift ($this->mailCurrent [3]);
              
              return $this->addReceiver ($nextReceiver)->then ($receiverAdded, $receiverError);
            }
            
            // Check if the server accepted any receiver
            if (count ($this->mailCurrent [4]) == 0) {
              // Restore the last error-code
              $this->lastCode = $this->mailCurrent [6];
              
              // Remember the current mail
              $currentMail = $this->mailCurrent;
              
              // Reset the mail-queue
              $this->mailCurrent = null;
              
              $this->reset ()->then (
                function () {
                  $this->runMailQueue ();  
                }
              );
              
              // Raise the callback
              return $currentMail [5]->reject ('No receiver was accepted by the server');
            }
            
            // Submit the mail
            return $sendMail ();
          };
          
          // Try to add the first receiver
          return $this->addReceiver ($nextReceiver)->then ($receiverAdded, $receiverError);
        },
        function () {
          // Remember the current mail
          $currentMail = $this->mailCurrent;
          
          // Reset the mail-queue
          $this->mailCurrent = null;
          
          $this->reset (function () {
            $this->runMailQueue ();
          });
          
          // Raise the callback
          return $currentMail [5]->reject ('MAIL FROM failed');
        }
      );
    }
    // }}}
    
    
    // {{{ smtpSetState
    /**
     * Change our protocol-state
     * 
     * @param enum $newState
     * 
     * @access private
     * @return void
     **/
    private function smtpSetState (int $newState) : void {
      // Check if anything was changed
      if ($this->smtpState == $newState)
        return;
      
      // Set the state
      $oldState = $this->smtpState;
      $this->smtpState = $newState;
      
      // Fire a callback
      $this->___callback ('smtpStateChanged', $newState, $oldState);
    }
    // }}}
    
    // {{{ smtpCheckState
    /**
     * Check our internal state how it will be when all commands are executed
     * 
     * @access private
     * @return enum
     **/
    private function smtpCheckState () : int {
      // Start with our current state
      $currentState = $this->smtpState;
      
      // Check the current command
      if (
        is_array ($this->currentCommand) &&
        isset ($this->currentCommand [4]) &&
        ($this->currentCommand [4] !== null)
      )
        $currentState = $this->currentCommand [4];
      
      // Check all commands on the pipe
      foreach ($this->queuedCommands as $queuedCommand)
        if (isset ($queuedCommand [4]) && ($queuedCommand [4] !== null))
          $currentState = $queuedCommand [4];
      
      return $currentState;
    }
    // }}}
    
    // {{{ smtpCommand
    /**
     * Issue an SMTP-Command
     * 
     * @param string $smtpVerb
     * @param array $commandArguments (optional)
     * @param enum $requiredState (optional)
     * @param enum $setState (optional)
     * @param callable $continuationCallback (optional)
     * 
     * @access private
     * @return Events\Promise
     **/
    private function smtpCommand (string $smtpVerb, array $commandArguments = null, int $requiredState = null, int $setState = null, callable $continuationCallback = null) : Events\Promise {
      // Just push the command to the queue
      $deferredPromise = new Events\Promise\Deferred ();
      
      $this->queuedCommands [] = [
        $smtpVerb,
        $commandArguments,
        $continuationCallback,
        $deferredPromise,
        $setState,
        $requiredState,
      ];
      
      // Try to issue the next command
      $this->smtpExecuteCommand ();
      
      return $deferredPromise->getPromise ();
    }
    // }}}
    
    // {{{ smtpExecuteCommand
    /**
     * Try to execute the next pending command
     * 
     * @access private
     * @return void
     **/
    private function smtpExecuteCommand () : void {
      // Check if there is a command active
      if ($this->currentCommand !== null)
        return;
      
      // Check if there are pending commands
      if (count ($this->queuedCommands) == 0)
        return;
      
      // Retrive the next command
      while (($c = count ($this->queuedCommands)) > 0) {
        $this->currentCommand = array_shift ($this->queuedCommands);
        
        // Check the required state
        if (($this->currentCommand [5] === null) || ($this->smtpState == $this->currentCommand [5]))
          break;
        
        // Fire a failed callback
        $this->currentCommand [3]->reject ('Invalid SMTP-State');
        
        if ($c > 1)
          continue;
        
        $this->currentCommand = null;
        
        return;
      }
      
      // Write the command to the queue
      $smtpCommand = $this->currentCommand [0];
      
      if (is_array ($this->currentCommand [1]) && (count ($this->currentCommand [1]) > 0))
        $smtpCommand .= ' ' . implode (' ', $this->currentCommand [1]);
      
      $this->sourceStream->write ($smtpCommand . "\r\n");
      
      // Raise a callback for this
      $this->___callback ('smtpWrite', $this->currentCommand [0], $this->currentCommand [1], $smtpCommand);
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param Events\ABI\Source $sourceStream
     * 
     * @access public
     * @return Events\Promise
     **/
    public function initStreamConsumer (Events\ABI\Stream $sourceStream) : Events\Promise {
      // Check if this is really a new stream
      if ($this->sourceStream === $sourceStream)
        return Events\Promise::resolve ();
      
      // Check if we have a stream assigned
      if (is_object ($this->sourceStream))
        $initPromise = $this->sourceStream->unpipe ($this);
      else
        $initPromise = Events\Promise::resolve ();
      
      return $initPromise->catch (function () { })->then (
        function () use ($sourceStream) {
          // Reset our state
          $this->sourceStream = $sourceStream;
          
          $this->streamBuffer = '';
          $this->clientAuthenticated = false;
          $this->connectingState = self::SMTP_HANDSHAKE_START;
          $this->currentCommand = null;
          $this->queuedCommands = [ ];
          $this->responseCode = null;
          $this->responseLines = [ ];
          $this->lastCode = null;
          $this->lastLines = null;
          $this->mailCurrent = null;
          $this->mailQueue = [ ];
          $this->serverDomain = null;
          $this->serverFeatures = null;
          
          $this->smtpSetState (self::SMTP_STATE_CONNECTING);
          
          // Raise callbacks
          $this->___callback ('eventPipedStream', $sourceStream);
          $this->___callback ('smtpConnecting');
          
          // Create a new promise
          $this->initPromise = new Events\Promise\Deferred ();
          
          return $this->initPromise->getPromise ();
        }
      );
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param Events\ABI\Source $streamSource
     * 
     * @access public
     * @return Events\Promise
     **/
    public function deinitConsumer (Events\ABI\Source $streamSource) : Events\Promise {
      // Check if the source is authentic
      if ($this->sourceStream !== $streamSource)
        return Events\Promise::reject ('Invalid source');
      
      // Remove the stream
      $this->sourceStream = null;
      
      // Reset our state
      $this->close ();
      
      // Raise the final callback
      return Events\Promise::resolve ();
    }
    // }}}
    
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param string $streamData
     * @param Events\ABI\Source $sourceStream
     *
     * @access public
     * @return void
     **/
    public function consume ($streamData, Events\ABI\Source $sourceStream): void
    {
      // Append data to internal buffer
      $this->streamBuffer .= $streamData;
      unset ($streamData);
      
      // Read lines from buffer
      $s = 0;
      
      while (($p = strpos ($this->streamBuffer, "\n", $s)) !== false) {
        // Strip the line from the buffer
        $smtpLine = substr ($this->streamBuffer, $s, $p - $s);
        $s = $p + 1;
        
        // Retrive the code from the line
        $smtpCode = (int)substr ($smtpLine, 0, 3);
        
        // Check if this is a multiline-message
        if ($isMultiline = (($l = strlen ($smtpLine)) > 3))
          $isMultiline = ($smtpLine [3] == '-');
        
        // Handle enhanced response-codes
        if (
          $this->hasFeature ('ENHANCEDSTATUSCODES') &&
          (($p = strpos ($smtpLine, ' ', 9)) !== false)
        )
          # TODO: We don't use this
          $eCode = substr ($smtpLine, 4, $p - 4);
        else
          $p = 3;
        
        // Push the response to local buffer
        if ($this->responseCode === null)
          $this->responseCode = $smtpCode;
        elseif ($this->responseCode != $smtpCode) {
          trigger_error ('SMTP-Protocol-Error: Invalid Response-Code on multi-line reply found. Quitting.', \E_USER_WARNING);
          
          $this->currentCommand = null;
          $this->queuedCommands = [ ];
          
          $this->close ();
          
          break;
        }
        
        $this->responseLines [] = substr ($smtpLine, $p + 1);
        
        // Wait for further responses on multiline-responses
        if ($isMultiline)
          continue;
        
        // Retrive buffered lines
        $this->lastCode = $smtpCode;
        $this->lastLines = $smtpLines = $this->responseLines;
        unset ($smtpLine);
        
        // Clear local buffer
        $this->responseCode = null;
        $this->responseLines = [ ];
        
        // Raise a callback for this
        $this->___callback ('smtpResponse', $smtpCode, $smtpLines);
        
        // Check for continuation
        if (($smtpCode >= 300) && ($smtpCode < 400)) {
          if (is_callable ($this->currentCommand [2])) {
            $this->sourceStream->write ($this->___raiseCallback ($this->currentCommand [2], $smtpCode, $smtpLines));
            
            continue;
          }
          
          trigger_error ('Server wants continuation, but we dont have a callback for this', \E_USER_ERROR);
        }
        
        // Check if we are connecting
        if ($this->smtpState == self::SMTP_STATE_CONNECTING) {
          // Peek the current command
          if ($this->currentCommand)
            $currentCommand = $this->currentCommand;
          else
            $currentCommand = null;
          
          // Handle the server's greeting
          if ($this->connectingState == self::SMTP_HANDSHAKE_START) {
            // Check if the server does not want us to connect
            // The RFC says only 554 here, we check them all though
            if ($smtpCode >= 500) {
              $this->streamBuffer = '';
              
              $this->close ()->finally (
                function () use ($smtpCode) {
                  $this->___callback ('smtpConnectionFailed');
                  
                  if ($this->initPromise) {
                    $this->initPromise->reject ('Received ' . $smtpCode);
                    $this->initPromise = null;
                  }
                }
              );
              
              return;
            }
            
            // Do the client-initiation
            $this->connectingState = self::SMTP_HANDSHAKE_EHLO;
            $this->currentCommand = null;
            
            $this->smtpCommand ('EHLO', [ $this->getClientName () ])->then (
              function () use ($currentCommand) {
                // Raise callbacks
                $this->___callback ('smtpConnected');
                
                if ($this->initPromise) {
                  $this->initPromise->resolve ();
                  $this->initPromise = null;
                }
                
                // Push back pending command
                if ($currentCommand)
                  array_unshift ($this->queuedCommands, $currentCommand);
              },
              function () use ($currentCommand) {
                if ($currentCommand)
                  call_user_func_array ([ $currentCommand [3], 'reject' ], func_get_args ());
              }
            );
            
            continue;
          
          // Handle the response to our own Greeting
          } elseif ($smtpCode >= 500) {
            // Handle strange errors, were both EHLO and HELO failed
            if ($this->connectingState > self::SMTP_HANDSHAKE_EHLO) {
              $this->streamBuffer = '';
              
              $this->close ()->finally (
                function () {
                  $this->___callback ('smtpConnectionFailed');
                  
                  if ($this->initPromise) {
                    $this->initPromise->reject ('Neither HELO nor EHLO were successfull');
                    $this->initPromise = null;
                  }
                }
              );
              
              return;
            }
            
            // Try HELO-Fallback
            $this->connectingState = self::SMTP_HANDSHAKE_FALLBACK;
            $this->currentCommand = null;
            
            $this->smtpCommand ('HELO', [ $this->getClientName () ])->then (
              function () use ($currentCommand) {
                // Raise callbacks
                $this->___callback ('smtpConnected');
                
                if ($this->initPromise) {
                  $this->initPromise->resolve ();
                  $this->initPromise = null;
                }
                
                // Push back pending command
                if ($currentCommand)
                  array_unshift ($this->queuedCommands, $currentCommand);
              },
              function () use ($currentCommand) {
                if ($currentCommand)
                  call_user_func_array ([ $currentCommand [3], 'reject' ], func_get_args ());
              }
            );
            
            continue;
          }
          
          // Retrive domainname of server
          $this->serverDomain = array_shift ($smtpLines);
          
          if (($p = strpos ($this->serverDomain, ' ')) !== false)
            $this->serverDomain = substr ($this->serverDomain, 0, $p);
          
          // Handle an EHLO-Response
          if ($this->connectingState == self::SMTP_HANDSHAKE_EHLO) {
            $this->serverFeatures = [ ];
            
            foreach ($smtpLines as $smtpLine) {
              $featureInfo = explode (' ', trim ($smtpLine));
              $featureKeyword = strtoupper (array_shift ($featureInfo));
              
              $this->serverFeatures [$featureKeyword] = $featureInfo;
            }
            
          // Server does not support EHLO
          } else
            $this->serverFeatures = false;
          
          // Change our protocol-state
          $this->smtpSetState (self::SMTP_STATE_CONNECTED);
        }
        
        // Handle normal replies
        if (($this->currentCommand [4] !== null) && ($smtpCode >= 200) && ($smtpCode < 300))
          $this->smtpSetState ($this->currentCommand [4]);
        
        $this->currentCommand [3]->resolve ($smtpCode, $smtpLines);
        
        // Remove the current command
        $this->currentCommand = null;
        
        // Try to issue any pending commands
        $this->smtpExecuteCommand ();
      }
      
      // Truncate the buffer
      $this->streamBuffer = substr ($this->streamBuffer, $s);
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
    protected function smtpStateChanged (int $newState, int $oldState) : void { }
    // }}}
    
    // {{{ smtpConnecting
    /**
     * Callback: SMTP-Connection is being established
     * 
     * @access protected
     * @return void
     **/
    protected function smtpConnecting () : void { }
    // }}}
    
    // {{{ smtpConnected
    /**
     * Callback: SMTP-Connection is ready for action
     * 
     * @access protected
     * @return void
     **/
    protected function smtpConnected () : void { }
    // }}}
    
    // {{{ smtpConnectionFailed
    /**
     * Callback: SMTP-Connection could not be established
     * 
     * @access protected
     * @return void
     **/
    protected function smtpConnectionFailed () : void { }
    // }}}
    
    // {{{ smtpDisconnected
    /**
     * Callback: SMTP-Connection was closed
     * 
     * @access protected
     * @return void
     **/
    protected function smtpDisconnected () : void { }
    // }}}
    
    // {{{ smtpWrite
    /**
     * Callback: A SMTP-Command was issued to the server
     * 
     * @param string $smtpVerb
     * @param array $commandParameters (optional)
     * @param string $actualLine (optional)
     * 
     * @access protected
     * @return void
     **/
    protected function smtpWrite (string $smtpVerb, array $commandParameters = null, string $actualLine = null) : void { }
    // }}}
    
    // {{{ smtpResponse
    /**
     * Callback: A Response was received from the server
     * 
     * @param int $smtpCode
     * @param array $smtpLines
     * 
     * @access protected
     * @return void
     **/
    protected function smtpResponse (int $smtpCode, array $smtpLines) : void { }
    // }}}
    
    // {{{ eventReadable
    /**
     * Callback: Never used.
     * 
     * @access protected
     * @return void
     **/
    protected function eventReadable () : void { }
    // }}}
    
    // {{{ eventClosed
    /**
     * Callback: The client-connection was closed
     * 
     * @access protected
     * @return void
     **/
    protected function eventClosed () : void { }
    // }}}
  }
