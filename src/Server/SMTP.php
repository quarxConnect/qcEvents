<?php

  /**
   * qcEvents - SMTP-Server Implementation
   * Copyright (C) 2012-2022 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  declare (strict_types=1);
  
  namespace quarxConnect\Events\Server;
  use \quarxConnect\Events;
  
  /**
   * SMTP-Server
   * -----------
   * Simple SMTP-Server-Implementation (RFC 5321)
   * 
   * @class SMTP
   * @package qcEvents
   * @revision 01
   **/
  class SMTP implements Events\ABI\Stream\Consumer {
    use Events\Feature\Hookable;
    
    private $streamSource = null;
    
    /* Protocol-States */
    private const SMTP_STATE_DISCONNECTED = 0;
    private const SMTP_STATE_CONNECTING = 1;
    private const SMTP_STATE_CONNECTED = 2;
    private const SMTP_STATE_TRANSACTION = 3;
    private const SMTP_STATE_DISCONNECTING = 4;
    
    /* Our current protocol-state */
    private $smtpState = SMTP::SMTP_STATE_DISCONNECTED;
    
    /* Are we ready to accept messages */
    private $smtpReady = true;
    
    /* Do we allow pipelining */
    private $smtpPipelining = true;
    
    /* Internal buffer for incoming SMTP-Data */
    private $smtpBuffer = '';
    
    /* Current SMTP-Command being executed */
    private $smtpCommand = null;
    
    /* Registered SMTP-Commands */
    private $smtpCommands = [ ];
    
    /* Remote Name from HELO/EHLO */
    private $smtpRemoteName = null;
    
    /* Originator of mail */
    private $mailOriginator = null;
    
    /* Receivers for mail */
    private $mailReceivers = [ ];
    
    /* Body of current mail */
    private $mailData = [ ];
    
    // {{{ __construct   
    /**
     * Create a new SMTP-Server
     * 
     * @access friendly
     * @return void
     **/
    function __construct () {
      // Register SMTP-Commands
      $this->smtpAddCommand (
        'QUIT',
        function (SMTP\Command $smtpCommand) {
          $this->smtpReady = false;
          
          $smtpCommand->setResponse (
            221,
            $this->smtpDomainname () . ' Service closing transmission channel',
          )->then (
            function () {
              if ($this->streamSource)
                return $this->streamSource->close ();
            }
          );
          
          return $smtpCommand;
        },
        self::SMTP_STATE_CONNECTING
      );
      
      $this->smtpAddCommand (
        'HELO',
        $smtpHelo = function (SMTP\Command $smtpCommand) {
          // Check if there is a parameter given
          if (!$smtpCommand->hasParameter ()) {
            $smtpCommand->setResponse (501);
            
            return $smtpCommand;
          }
          
          // Store the remote name
          $this->smtpRemoteName = $smtpCommand->getParameter ();
          # TODO: Sanatize this
          
          // Change our current state
          $this->smtpSetState (self::SMTP_STATE_CONNECTED);
          
          // Write out features
          $smtpFeatures = array ($this->smtpDomainname ());
          
          // Check for extended HELO
          if ($smtpCommand == 'EHLO') {
            $smtpFeatures [] = '8BITMIME';
          
            if ($this->smtpPipelining)
              $smtpFeatures [] = 'PIPELINING';
          }
          
          $smtpCommand->setResponse (250, $smtpFeatures);
          
          return $smtpCommand;
        },
        self::SMTP_STATE_CONNECTING
      );
      $this->smtpAddCommand ('EHLO', $smtpHelo, self::SMTP_STATE_CONNECTING);
      
      $this->smtpAddCommand (
        'NOOP',
        function (SMTP\Command $smtpCommand) {
          $smtpCommand->setResponse (250);
          
          return $smtpCommand;
        },
        self::SMTP_STATE_CONNECTING
      );
      
      $this->smtpAddCommand (
        'MAIL',
        function (SMTP\Command $smtpCommand) {
          // Check if we are in the right protocol-state
          if ($this->smtpState !== self::SMTP_STATE_CONNECTED) {
            $smtpCommand->setResponse (503);
            
            return $smtpCommand;
          }
          
          // Check if there is a parameter given
          if (!$smtpCommand->hasParameter ()) {
            $smtpCommand->setResponse (501);
            
            return $smtpCommand;
          }
          
          // Retrive the Originator
          $mailOriginator = $smtpCommand->getParameter ();
          
          // Check if this is realy a MAIL FROM:
          if (strtoupper (substr ($mailOriginator, 0, 5)) != 'FROM:') {
            $smtpCommand->setResponse (501);
            
            return $smtpCommand;
          }
          
          $mailOriginator = ltrim (substr ($mailOriginator, 5));
          
          // Parse the parameter
          try {
            $mailParameters = $this->smtpExplodeMailParams ($mailOriginator);
          } catch (\Throwable $error) {
            $smtpCommand->setResponse (501);
            
            return $smtpCommand;
          }
          
          // Fire the callback to validate and set
          $this->___awaitHooks (
            'smtpSetOriginator',
            250,
            $mailParameters [0],
            $mailParameters [1]
          )->then (
            function (int $smtpCode) use ($smtpCommand) {
              // Check if the command was successfull (and switch into transaction-state)
              if ($smtpCode < 300)
                $this->smtpSetState (self::SMTP_STATE_TRANSACTION);
              
              // Finish the command
              $smtpCommand->setResponse ($smtpCode);
              
              return $smtpCommand;
            },
            function (\Throwable $error) use ($smtpCommand) {
              // Just mark the command as failed
              $smtpCommand->setResponse (451);
              
              return $smtpCommand;
            }
          );
        },
        self::SMTP_STATE_CONNECTED
      );
      
      $this->smtpAddCommand (
        'RCPT',
        function (SMTP\Command $smtpCommand) {
          // Check if there is a parameter given
          if (!$smtpCommand->hasParameter ()) {
            $smtpCommand->setResponse (501);
            
            return $smtpCommand;
          }
          
          // Retrive the Receiver
          $mailReceiver = $smtpCommand->getParameter ();
          
          // Check if this is realy a RCPT TO:
          if (strtoupper (substr ($mailReceiver, 0, 3)) != 'TO:') {
            $smtpCommand->setResponse (501);
            
            return $smtpCommand;
          }
          
          $mailReceiver = ltrim (substr ($mailReceiver, 3));
          
          // Parse the parameter
          try {
            $receiverParameters = $this->smtpExplodeMailParams ($mailReceiver);
          } catch (\Throwable $error) {
            $smtpCommand->setResponse (501);
            
            return $smtpCommand;
          }
          
          // Fire the callback to validate and set
          $this->___awaitHooks (
            'smtpAddReceiver',
            250,
            $receiverParameters [0],
            $receiverParameters [1]
          )->then (
            function (int $smtpCode) use ($smtpCommand) {
              // Check if the command was successfull (and switch into transaction-state)
              if ($smtpCode < 300)
                $this->smtpSetState (self::SMTP_STATE_TRANSACTION);
              
              // Finish the command
              $smtpCommand->setResponse ($smtpCode);
              
              return $smtpCommand;
            },
            function (\Throwable $error) use ($smtpCommand) {
              // Just mark the command as failed
              $smtpCommand->setResponse (451);
              
              return $smtpCommand;
            }
          );
        },
        self::SMTP_STATE_TRANSACTION
      );
      
      $this->smtpAddCommand (
        'DATA',
        function (SMTP\Command $smtpCommand) {
          // Check if there is a parameter given
          if ($smtpCommand->hasParameter ()) {
            $smtpCommand->setResponse (504);
            
            return $smtpCommand;
          }
          
          // Accept incoming mail-data
          $smtpCommand->setIntermediateResponse (
            354,
            null,
            function (string $receivedLine) {
              // Check for end of incoming data
              if ($receivedLine == '.')
                return true;
              
              // Check for a transparent '.'
              if ($receivedLine == '..')
                $receivedLine = '.';
              
              return $receivedLine;
            }
          )->then (
            function (string $intermediateData) use ($smtpCommand) {
              return $smtpCommand->setResponse (250);
            },
            function (\Throwable $error) use ($smtpCommand) {
              return $smtpCommand->setResponse (554, $error->getMessage ());
            }
          );
          
          return $smtpCommand;
        },
        self::SMTP_STATE_TRANSACTION
      );
      
      $this->smtpAddCommand (
        'RSET',
        function (SMTP\Command $smtpCommand) {
          // Remove any data transmitted for an transaction
          $this->mailOriginator = null;
          $this->mailReceivers = [ ];
          
          // Set our internal state
          $this->smtpSetState (self::SMTP_STATE_CONNECTED);
          
          // Complete the command
          $smtpCommand->setResponse (250);
          
          return $smtpCommand;
        },
        self::SMTP_STATE_CONNECTED
      );
      
      $commandUnimplemented = function (SMTP\Command $smtpCommand) {
        $smtpCommand->setResponse (502);
        
        return $smtpCommand;
      };
      
      $this->smtpAddCommand ('HELP', $commandUnimplemented, self::SMTP_STATE_CONNECTED);
      $this->smtpAddCommand ('EXPN', $commandUnimplemented, self::SMTP_STATE_CONNECTED);
      $this->smtpAddCommand ('VRFY', $commandUnimplemented, self::SMTP_STATE_CONNECTED);
    }
    // }}}
    
    // {{{ smtpDomainname
    /**
     * Retrive the domainname of this smtp-server
     * 
     * @access protected
     * @return string
     **/
    protected function smtpDomainname () {
      return gethostname ();
    }
    // }}}
    
    // {{{ smtpGreetingLines
    /**
     * Retrive all lines for the greeting
     * 
     * @access protected
     * @return array
     **/
    protected function smtpGreetingLines () : array {
      return [ 'ESMTP qcEvents-Mail/0.1' ];
    }
    // }}}
    
    // {{{ smtpAddCommand
    /**
     * Register a command-handler for SMTP
     * 
     * @param string $smtpCommand The Command-Verb
     * @param callable $commandCallback The callback to run for the command
     * @param enum $minState (optional) The minimal state we have to be in for this command
     * 
     * @access protected
     * @return void
     **/
    protected function smtpAddCommand (string $smtpCommand, callable $commandCallback, int $minState = self::SMTP_STATE_DISCONNECTED) : void {
      $this->smtpCommands [$smtpCommand] = [ $commandCallback, $minState ];
    }
    // }}}
    
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param mixed $streamData
     * @param Events\ABI\Source $streamSource
     * 
     * @access public
     * @return void
     **/
    public function consume ($streamData, Events\ABI\Source $streamSource) : void {
      // Sanity-Check the given source
      if ($this->streamSource !== $streamSource)
        return;
      
      // Append the received data to our internal buffer
      if (!is_string ($streamData))
        return;
      
      if (strlen ($streamData) > 0) {
        $this->smtpBuffer .= $streamData;
        unset ($streamData);
      }
      
      // Check if there is a command running
      if ($this->smtpCommand) {
        // Check for pipelining
        if (!$this->smtpPipelining) {   
          $this->smtpSendResponse (520);
          $this->smtpReady = false;
          $this->close ();
          
          return;
        }
        
        // Check if the command waits for additional data
        $smtpCode = $this->smtpCommand->getCode ();
        
        if (!($dataWait = (($smtpCode > 299) && ($smtpCode < 400))))
          return;
      }
      
      // Check for commands that are ready
      while (($p = strpos ($this->smtpBuffer, "\n")) !== false) {
        // Retrive the command from the line
        $smtpCommand = rtrim (substr ($this->smtpBuffer, 0, $p));
        $this->smtpBuffer = substr ($this->smtpBuffer, $p + 1);
        
        // Check for an active command
        if ($this->smtpCommand !== null) {
          // Check if the command waits for additional data
          $smtpCode = $this->smtpCommand->getCode ();
          
          if (($smtpCode > 299) && ($smtpCode < 400)) {
            $this->smtpCommand->receiveLine ($smtpCommand);
            
            continue;
          }
          
          // Check for pipelining
          if (!$this->smtpPipelining) {
            $this->smtpSendResponse (520);
            $this->smtpReady = false;
            $this->close ();
            
            return;
          }
        }
        
        // Check if there are parameters
        if (($p = strpos ($smtpCommand, ' ')) !== false) {
          $smtpParameter = ltrim (substr ($smtpCommand, $p + 1));
          $smtpCommand = strtoupper (substr ($smtpCommand, 0, $p));
        } else {
          $smtpParameter = null;
          $smtpCommand = strtoupper ($smtpCommand);
        }
        
        // Register the command
        $this->smtpCommand = new SMTP\Command ($this, $smtpCommand, $smtpParameter);
        
        // Check if we are accepting commands (always allow QUIT-Command to be executed)
        if (!$this->smtpReady && ($smtpCommand != 'QUIT')) {
          $this->smtpCommand->setResponse (503);
          
          continue;
        }
        
        // Check if the command is known
        if (!isset ($this->smtpCommands [$smtpCommand])) {
          $this->smtpCommand->setResponse (500);
          
          continue;
        }
        
        // Check our state
        if ($this->smtpState < $this->smtpCommands [$smtpCommand][1]) {
          $this->smtpCommand->setResponse (503);
          
          continue;
        }
        
        // Invoke the command
        call_user_func ($this->smtpCommands [$smtpCommand][0], $this->smtpCommand);
      }
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param Events\ABI\Stream $streamSource
     * 
     * @access public
     * @return Events\Promise
     **/
    public function initStreamConsumer (Events\ABI\Stream $streamSource) : Events\Promise {
      // Set new status
      $this->streamSource = $streamSource;
      $this->smtpSetState (self::SMTP_STATE_CONNECTING);
      
      // Check if the smtp-service is ready
      if (!$this->smtpReady)
        return $this->smtpSendResponse (554, '');
      
      // Retrive all lines for the greeting
      $greetingLines = $this->smtpGreetingLines ();
      
      // Prepend our domainname to the response
      if (count ($greetingLines) > 0)
        $greetingLines [0] = $this->smtpDomainname () . ' ' . $greetingLines [0];
      else
        $greetingLines [] = $this->smtpDomainname ();
          
      // Write out the response
      return $this->smtpSendResponse (220, $greetingLines)->then (
        function () {
          $this->___callback ('eventPipedStream', $this->streamSource);
        },
        function (\Throwable $writeError) {
          $this->streamSource = null;
          $this->smtpSetState (self::SMTP_STATE_DISCONNECTED);
          
          throw $writeError;
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
      $this->streamSource = null;
      
      return Events\Promise::resolve ();
    }
    // }}}
    
    // {{{ close
    /**
     * Close this event-interface
     * 
     * @access public
     * @return Events\Promise
     **/
    public function close () : Events\Promise {
      if (!$this->streamSource || !$this->smtpReady)
        return Events\Promise::resolve ();
      
      $this->smtpReady = false;
      
      return $this->smtpSendResponse (520)->catch (
        function () {
          // No-Op
        }
      )->then (
        function () {
          if (!$this->streamSource)
            return;
          
          return $this->streamSource->close ();
        }
      );
    }
    // }}}
    
    // {{{ smtpSetState
    /**
     * Set our protocol-state
     * 
     * @param enum $newState The protocol-state to set
     * 
     * @access protected
     * @return void
     **/
    protected function smtpSetState (int $newState) : void {
      $this->smtpState = $newState;
    }
    // }}}
    
    // {{{ smtpExplodeMailParams
    /** 
     * Split up mail-adress and parameters
     * 
     * @param string $mailData
     * 
     * @access private
     * @return array
     **/
    private function smtpExplodeMailParams (string $mailData) : array {
      // Check where to start 
      $haveBrackets = ($mailData [0] == '<');
      $p = ($haveBrackets ? 1 : 0);
      $l = strlen ($mailData);
      
      // Retrive the localpart
      if ($mailData [$p] == '"') {
        for ($i = $p + 1; $i < $l; $i++)
          if ($mailData [$i] == '\\') {
            $c = ord ($mailData [++$i]);
            
            if (($c < 32) || ($c > 126))
              break;
          } elseif ($mailData [$i] != '"') {
            $c = ord ($mailData [$i]);
            
            if (($c < 32) || ($c == 34) || ($c == 92) || ($c > 126))
              break;
          } else
            break;
          
        $Localpart = substr ($mailData, $p, $i - $p + 2);
        $p = $i + 2;
      } else {
        for ($i = $p; $i < $l; $i++) {
          $C = ord ($mailData [$i]);
          
          if (($C < 33) || ($C == 34) || (($C > 39) && ($C < 42)) || ($C == 44) || (($C > 57) && ($C < 61)) ||
              ($C == 62) || ($C == 64) || (($C > 90) && ($C < 94)) || ($C > 126))
            break;
        }
        
        $Localpart = substr ($mailData, $p, $i - $p);
        $p = $i;
      }
      
      if ($mailData [$p++] != '@')
        return false;
      
      // Retrive the domain
      if (($e = strpos ($mailData, ($haveBrackets ? '>' : ' '), $p)) === false)
        return false;
      
      $Domain = substr ($mailData, $p, $e - $p);
      $p = $e + 1;
      
      $Mail = $Localpart . '@' . $Domain;
      
      // Check for additional parameter
      $Parameter = ltrim (substr ($mailData, $p));
      $Parameters = array ();
      
      if (strlen ($Parameter) > 0)
        foreach (explode (' ', $Parameter) as $Value)
          if (($p = strpos ($Value, '=')) !== false)
            $Parameters [substr ($Value, 0, $p)] = substr ($Value, $p + 1);
          else
            $Parameters [$Value] = true;
      
      return array ($Mail, $Parameters);
    }
    // }}}
       
    // {{{ smtpCommandReady
    /**
     * A command was finished
     * 
     * @param SMTP\Command $smtpCommand The received command
     * 
     * @access public
     * @return Events\Promise
     **/
    public function smtpCommandReady (SMTP\Command $smtpCommand) : Events\Promise {
      // Check if this command is the first running command
      if ($smtpCommand !== $this->smtpCommand)
        return Events\Promise::reject ('Not our command');
      
      // Write out its response
      $smtpCode = $this->smtpCommand->getCode ();
      
      $responsePromise = $this->smtpSendResponse (
        $smtpCode,
        $this->smtpCommand->getMessage ()
      );
      
      // Check if this is an intermediate response or release the command
      if (($smtpCode < 300) || ($smtpCode > 399)) {
        $this->smtpCommand = null;
        
        // Proceed to next command
        if (strlen ($this->smtpBuffer) > 0)
          $this->consume ('', $this->streamSource);
      }
      
      // Return the promise
      return $responsePromise;
    }
    // }}}
    
    // {{{ smtpSendResponse
    /**
     * Write out an SMTP-Response
     * 
     * @param int $smtpCode
     * @param string|array $smtpMessage
     * 
     * @access private
     * @return Events\Promise
     **/
    private function smtpSendResponse (int $smtpCode, $smtpMessage = null) : Events\Promise {
      static $smtpCodes = [
        221 => 'Service closing transmission channel',
        250 => 'Ok',
        354 => 'Start mail input; end with <CRLF>.<CRLF>',
        421 => 'Service not available, closing transmission channel',
        451 => 'Requested action aborted: error in processing',
        500 => 'Syntax error, command unrecognized',
        501 => 'Syntax error in parameters or arguments',
        502 => 'Command not implemented',
        503 => 'bad sequence of commands',
        504 => 'Command parameter not implemented',
        
        520 => 'Pipelining not allowed', # This is not on the RFC
        550 => 'Requested action not taken: mailbox unavailable',
        554 => 'Transaction failed',
      ];
      
      // Check if to return a default response-message
      if (($smtpMessage === null) && (isset ($smtpCodes [$smtpCode])))
        $smtpMessage = $smtpCodes [$smtpCode];
      
      // Make sure the message is an array
      if (!is_array ($smtpMessage))
        $smtpMessage = [ $smtpMessage ];
      else
        $smtpMessage = array_values ($smtpMessage);
      
      // Write out all message-lines
      $smtpResponse = '';
      
      for ($i = 0; $i < count ($smtpMessage); $i++)
        $smtpResponse .= $smtpCode . ($i < count ($smtpMessage) - 1 ? '-' : ' ') . $smtpMessage [$i] . "\r\n";
      
      return $this->streamSource->write ($smtpResponse);
    }
    // }}}
    
    
    // {{{ smtpSetOriginator
    /**
     * Callback: Try to store the originator of a mail-transaction
     * 
     * @param int $smtpCode
     * @param string $mailOriginator
     * @param array $originatorParameters
     * 
     * @access protected
     * @return int|Events\Promise
     **/
    protected function smtpSetOriginator (int $smtpCode, string $mailOriginator, array $originatorParameters) {
      // Don't store originator if smtp-code indicates something different
      if ($smtpCode >= 300)
        return $smtpCode;
      
      // Simply store the originator
      $this->mailOriginator = $mailOriginator;
      
      // Just forward the code
      return $smtpCode;
    }
    // }}}
    
    // {{{ smtpAddReceiver
    /**
     * Callback: Try to add a recevier to the current mail-transaction
     * 
     * @param int $smtpCode
     * @param string $mailReceiver
     * @param array $receiverParameters
     * 
     * @access protected
     * @return int|Events\Promise
     **/
    protected function smtpAddReceiver (int $smtpCode, string $mailReceiver, array $receiverParameters) {
      // Don't store originator if smtp-code indicates something different
      if ($smtpCode >= 300)
        return $smtpCode;
      
      // Append to receivers
      $this->mailReceivers [] = $mailReceiver;
      
      // Just forward the code
      return $smtpCode;
    }
    // }}}
    
    // {{{ smtpMessageReceived
    /**
     * Callback: Message-Data was completely received
     * 
     * @param string $messageBody The actual message-data
     * 
     * @access protected
     * @return void
     **/
    protected function smtpMessageReceived (string $messageBody) : void {
      // No-Op
    }
    // }}}
    
    
    // {{{ eventPipedStream
    /**
     * Callback: A stream was attached to this consumer
     * 
     * @param Events\ABI\Stream $streamSource
     * 
     * @access protected
     * @return void
     **/
    protected function eventPipedStream (Events\ABI\Stream $streamSource) : void {
      // No-Op
    }
    // }}}
    
    // {{{ eventClosed
    /**
     * Callback: The interface was closed
     * 
     * @access public
     * @return void
     **/
    protected function eventClosed () : void {
      // No-Op
    }
    // }}}

    // {{{ eventUnpiped
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param Events\ABI\Source $streamSource
     * 
     * @access protected
     * @return void
     **/
    protected function eventUnpiped (Events\ABI\Source $streamSource) : void {
      // No-Op
    }
    // }}}
  }
