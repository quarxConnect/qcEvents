<?php

  /**
   * qcEvents - Asyncronous FTP Client-Stream
   * Copyright (C) 2015-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  namespace quarxConnect\Events\Stream\FTP;
  use \quarxConnect\Events;
  use \quarxConnect\Events\ABI;
  
  /**
   * FTP Client Stream
   * -----------------
   * FTP Client Implementation (RFC 969)
   * This Stream is implemented independant of the underlying Stream.
   * It can be anything from a standard-compilant TCP-Socket to a pipe. Feel free!
   * 
   * @see https://tools.ietf.org/html/rfc959
   * 
   * @class quarxConnect\Events\Stream\FTP\Client
   * @extends quarxConnect\Events\Hookable
   * @implements qcEvents_Interface_Stream_Consumer
   * @package qcEvents
   * @revision 01
   * @author Bernd Holzmueller <bernd@quarxconnect.de>
   * 
   * @todo CDUP - CHANGE TO PARENT DIRECTORY
   * @todo SMNT - STRUCTURE MOUNT		SMNT <SP> <pathname> <CRLF>
   * @todo REIN - REINITIALIZE
   * @todo PORT - DATA PORT
   * @todo STOR - STORE				STOR <SP> <pathname> <CRLF>
   * @todo STOU - STORE UNIQUE
   * @todo APPE - APPEND (with create)		APPE <SP> <pathname> <CRLF>
   * @todo ALLO - ALLOCATE			ALLO <SP> <decimal-integer> [<SP> R <SP> <decimal-integer>] <CRLF>
   * @todo REST - RESTART			REST <SP> <marker> <CRLF>
   * @todo RNFR - RENAME FROM			RNFR <SP> <pathname> <CRLF>
   * @todo RNTO - RENAME TO			RNTO <SP> <pathname> <CRLF>
   * @todo ABOR - ABORT
   * @todo DELE - DELETE			DELE <SP> <pathname> <CRLF>
   * @todo RMD  - REMOVE DIRECTORY		RMD  <SP> <pathname> <CRLF>
   * @todo MKD  - MAKE DIRECTORY		MKD  <SP> <pathname> <CRLF>
   * @todo LIST - LIST				LIST [<SP> <pathname>] <CRLF>
   * @todo SITE - SITE PARAMETERS		SITE <SP> <string> <CRLF>
   * @todo SYST - SYSTEM
   **/
  class Client extends Events\Hookable implements ABI\Stream\Consumer {
    /* FTP-Protocol states */
    private const STATE_DISCONNECTED = 0;
    private const STATE_CONNECTING = 1;
    private const STATE_CONNECTED = 2;
    private const STATE_AUTHENTICATING = 3;
    private const STATE_AUTHENTICATED = 4;
    
    private $ftpState = Client::STATE_DISCONNECTED;
    
    /* Representation-Types */
    public const TYPE_ASCII = 0;
    public const TYPE_EBCDIC = 1;
    public const TYPE_IMAGE = 2;
    
    /* Structure types */
    public const STRUCTURE_FILE = 0;
    public const STRUCTURE_RECORD = 1;
    public const STRUCTURE_PAGE = 2;
    
    /* File-Transfer modes */
    public const MODE_STREAM = 0;
    public const MODE_BLOCK = 1;
    public const MODE_COMPRESSED = 2;
    
    /* Entire Input-Buffer */
    private $inputBuffer = '';
    
    /* Buffer for current response from server */
    private $receiveBuffer = '';
    
    /* Defered promise for stream-initialization */
    private $initPromise = null;
    
    /* Our piped source-stream */
    private $sourceStream = null;
    
    /* Currently active FTP-Command */
    private $activeCommand = null;
    
    /* Pending FTP-Commands */
    private $pendingCommands = [ ];
    
    // {{{ noOp
    /**
     * Just do nothing, but send something over the wire
     * 
     * @access public
     * @return Events\Promise
     **/
    public function noOp () : Events\Promise {
      return $this->ftpCommand ('NOOP')->then (
        function () { }
      );
    }
    // }}}
    
    // {{{ authenticate
    /**
     * Try to authenticate this FTP-Stream
     * 
     * @param string $userName
     * @param string $userPassword
     * @param string $userAccount (optional)
     * 
     * @access public
     * @return Events\Promsie
     **/
    public function authenticate (string $userName, string $userPassword, string $userAccount = null) : Events\Promise {
      return $this->ftpCommand (
        'USER',
        [ $userName ],
        function (Client $Self, int $responseCode, string $responseText) use ($userPassword, $userAccount) {
          // A USER-Call here would result in 331 or 332
          // A PASS-Call here would result in        332
          // A ACCT-Call will never get here
          
          // Update our state
          $this->ftpState = self::STATE_AUTHENTICATING;
          
          // Write out the password if it is required
          if ($responseCode == 331)
            return $this->writeCommand ('PASS', [ $userPassword ]);
          
          // Check for a protocoll-violation
          if ($responseCode != 332)
            return $this->raiseProtocolError ();
          
          // Check if we have an account available
          if ($userAccount === null)
            return false;
          
          return $this->writeCommand ('ACCT', [ $userAccount ]);
        }
      )->then (
        function (int $responseCode, string $responseText) use ($userName, $userAccount) {
          // A USER-Call here would result in      230, 530, 500, 501,      421
          // A PASS-Call here would result in 202, 230, 530, 500, 501, 503, 421
          // A ACCT-Call here would result in 202, 230, 530, 500, 501, 503, 421
          
          // Update the state
          $this->ftpState = self::STATE_AUTHENTICATED;
          
          // Fire the callback
          $this->___callback ('ftpAuthenticated', $userName, $userAccount);
        },
        function () {
          // Update the state
          $this->ftpState = self::STATE_CONNECTED;
          
          // Forward the rejection
          throw new Events\Promise\Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ changeDirectory
    /**
     * Change working-directory on server
     * 
     * @param string $toDirectory The path of the directory to change to
     * 
     * @access public
     * @return Events\Promise
     **/
    public function changeDirectory (string $toDirectory) : Events\Promise {
      return $this->ftpCommand (
        'CWD',
        [ $toDirectory ]
      )->then (
        function () { }
      );
    }
    // }}}
    
    // {{{ getWorkingDirectory
    /**
     * Retrive the current working-directory from FTP-Server
     * 
     * @access public
     * @return Events\Promise
     **/
    public function getWorkingDirectory () : Events\Promise {
      return $this->ftpCommand ('PWD')->then (
        function (int $responseCode, string $responseText) {
          if ($responseText [0] == '"')
            $responseText = substr ($responseText, 1, strpos ($responseText, '"', 2) - 1);
          
          $this->___callback ('ftpWorkingDirectory', $responseText);
        }
      );
    }
    // }}}
    
    // {{{ getStatus
    /**
     * Retrive the status of FTP-Server or a file on that server
     * 
     * @param string $forPath (optional) Pathname for file
     * 
     * @access public
     * @return Events\Promise
     **/
    public function getStatus (string $forPath = null) : Events\Promise {
      return $this->runFTPCommand (
        'STAT',
        ($forPath ? [ $forPath ] : null)
      )->then (
        function (int $responseCode, string $responseText) {
          return $responseText;
        }
      );
    }
    // }}}
    
    // {{{ getFilenames
    /**
     * Retrive all filenames existant at a given path or the current one
     * 
     * @param string $onDirectory (optional) The path to use, if NULL the current one will be used
     * 
     * @access public
     * @return Events\Promise
     **/
    public function getFilenames (string $onDirectory = null) : Events\Promise {
      return $this->ftpDataCommandBuffered (
        'NLST',
        $onDirectory,
        self::TYPE_ASCII,
        self::STRUCTURE_FILE,
        self::MODE_STREAM,
      )->then (
        function (int $responseCode, string $responseText, string $responseData) {
          return explode ("\r\n", substr ($responseData, 0, -2));
        }
      );
    }
    // }}}
    
    // {{{ retriveFileStream
    /**
     * Download a file from the server, return a stream-handle for further processing
     * 
     * @param string $fileName Path of the file to download
     * 
     * @access public
     * @return Events\Promise
     **/
    public function retriveFileStream (string $fileName) : Events\Promise {
      return $this->ftpDataCommandStream (
        'RETR',
        $fileName,
        self::TYPE_IMAGE,
        self::STRUCTURE_FILE,
        self::MODE_STREAM,
        true
      );
    }
    // }}}
    
    // {{{ downloadFile
    /**
     * Download file from server and write to filesystem
     * 
     * @param string $remotePath Path of file on server
     * @param string $localPath Local path to write the file to
     * 
     * @access public
     * @return Events\Promise
     **/
    public function downloadFile (string $remotePath, string $localPath) : Events\Promise {
      return $this->retriveFileStream (
        $remotePath
      )->then (
        function (ABI\Stream $commandStream, Events\Promise $finalPromise) use ($localPath) {
          $commandStream->pipe (
            new Events\File ($commandStream->getEventBase (), $localPath, false, true)
          );
          
          return $finalPromise;
        }
      );
    }
    // }}}
    
    // {{{ downloadFileBuffered
    /**
     * Download file from server and write to filesystem
     * 
     * @param string $remotePath Path of file on server
     * 
     * @access public
     * @return Events\Promise
     **/
    public function downloadFileBuffered (string $remotePath) : Events\Promise {
      return $this->ftpDataCommandBuffered (
        'RETR',
        $remotePath,
        self::TYPE_IMAGE,
        self::STRUCTURE_FILE,
        self::MODE_STREAM,
      );
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
      // Try to close gracefully
      if (!$this->initPromise && $this->sourceStream && ($this->ftpState != self::STATE_CONNECTING))
        return $this->ftpCommand ('QUIT')->catch (
          function () { }
        )->then (
          function () {
            $sourceStream = $this->sourceStream;
            $this->sourceStream = null;
            
            $this->___callback ('eventClosed');
            
            if ($this->sourceStream)
              return $sourceStream->close ();
          }
        );
      
      $this->initPromise->reject ('Stream closed');
      $this->initPromise = null;
      
      $this->___callback ('eventClosed');
      
      return Events\Promise::resolve ();
    }
    // }}}
    
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param mixed $inputData
     * @param ABI\Source $sourceStream
     * 
     * @access public
     * @return void
     **/
    public function consume ($inputData, ABI\Source $sourceStream) : void {
      // Append to internal buffer
      $this->inputBuffer .= $inputData;
      unset ($inputData);

      // Check if we have received complete lines
      $s = 0;

      while (($e = strpos ($this->inputBuffer, "\r\n", $s)) !== false) {
        // Peek the next complete line
        $inputLength = $e - $s;
        $inputLine = substr ($this->inputBuffer, $s, $inputLength);
        
        // Move pointer to end-of-line
        $s = $e + 2;

        // Check if this is a final response
        $responseCode = substr ($inputLine, 0, 3);
        
        if (($inputLength > 3) && ($inputLine [3] == ' ') && (($iCode = (int)$responseCode) == $responseCode) && ((strlen ($this->receiveBuffer) < 3) || ($responseCode == substr ($this->receiveBuffer, 0, 3)))) {
          $this->processFTPResponse ($iCode, rtrim (substr ($this->receiveBuffer, 4) . substr ($inputLine, 4)));
          $this->receiveBuffer = '';

        // Just append to buffer
        } else
          $this->receiveBuffer .= trim ($inputLine) . "\n";
      }

      // Truncate the input-buffer
      if ($s > 0)
        $this->inputBuffer = substr ($this->inputBuffer, $s);
    }
    // }}}
    
    // {{{ processFTPResponse
    /**
     * Process a received FTP-Response
     * 
     * @param int $responseCode The response-code from the server
     * @param string $responseText The text-response from the server
     * 
     * @access private
     * @return void
     **/
    private function processFTPResponse (int $responseCode, string $responseText) : void {
      // Run initial callback for this
      if ($this->___callback ('ftpRead', $responseCode, $responseText) === false)
        return;
      
      // Check if we are receiving a HELO from FTP
      if ($this->ftpState == self::STATE_CONNECTING) {
        // Check the code
        if (($responseCode >= 100) && ($responseCode < 200))
          return;
        
        // Change our state
        if ($responseCode < 400) {
          $this->ftpState = self::STATE_CONNECTED;
          $this->___callback ('ftpConnected');
        } else {
          $this->ftpState = self::STATE_DISCONNECTED;
          $this->___callback ('ftpDisconnected');
        }
        
        // Fire the callback
        if ($this->initPromise) {
          if ($responseCode < 400)
            $this->initPromise->resolve ();
          else
            $this->initPromise->reject ('Received ' . $responseCode);
          
          $this->initPromise = null;
        }
        
        return;
      }
      
      // Look for an active command
      if (!$this->activeCommand) {
        $this->raiseProtocolError ();
        
        return;
      }
      
      // Check for an intermediate reply
      if ((($responseCode >= 100) && ($responseCode < 200)) || (($responseCode >= 300) && ($responseCode < 400))) {
        // Check if the command is prepared for this
        if (!$this->activeCommand [2])
          $this->ftpFinishCommand (false, $responseCode, $responseText);
        
        // Fire a callback for this
        elseif ($this->___raiseCallback ($this->activeCommand [2], $responseCode, $responseText) === false)
          $this->ftpFinishCommand (false, $responseCode, $responseText);
        
        return;
      }
      
      $this->ftpFinishCommand ((($responseCode >= 200) && ($responseCode < 300)), $responseCode, $responseText);
    }
    // }}}
    
    // {{{ ftpFinishCommand
    /**
     * Finish the current command that is being processed and move to the next one
     * 
     * @param bool $finalStatus The overall Status-Indicator
     * @param int $responseCode The last response-code received from the server
     * @param string $responseText The last text-response received from the server
     * 
     * @access private
     * @return void
     **/
    private function ftpFinishCommand (bool $finalStatus, int $responseCode, string $responseText) : void {
      // Peek and free the current command
      $activeCommand = $this->activeCommand;
      $this->activeCommand = null;
      
      // Resolve the promise
      if ($finalStatus)
        $activeCommand [3]->resolve ($responseCode, $responseText);
      else
        $activeCommand [3]->reject ($responseText, $responseCode);
      
      // Move to next command
      $this->ftpStartPendingCommand ();
    }
    // }}}
    
    // {{{ raiseProtocolError
    /**
     * A protocol-error was detected on the wire
     * 
     * @access private
     * @return bool Always FALSE
     **/
    private function raiseProtocolError () : bool {
      $this->___callback ('ftpProtocolError');
      $this->close ();
      
      return false;
    }
    // }}}
    
    // {{{ ftpCommand
    /**
     * Enqueue an FTP-Command for execution
     * 
     * @param string $commandName The actual command to run
     * @param array $commandParameters (optional) Any parameter for that command
     * @param callable $intermediateCallback (optional) A callback to run whenever an intermediate response was received
     * @param bool $forceNext (optional) Force the command to be executed next
     * 
     * The intermediate callback will be raised in the form of
     * 
     *   function (Client $Self, int $responseCode, string $responseText) { }
     * 
     * If the intermediate callback returns FALSE the entire operation will be canceled
     * 
     * @access private
     * @return Events\Promise
     **/
    private function ftpCommand (string $commandName, array $commandParameters = null, callable $intermediateCallback = null, bool $forceNext = false) : Events\Promise {
      // Create a promse for that
      $deferredPromise = new Events\Promise\Defered ();
      
      // Append the command to our queue
      $nextCommand = [
        $commandName,
        $commandParameters,
        $intermediateCallback,
        $deferredPromise
      ];
      
      if ($forceNext)
        array_unshift ($this->pendingCommands, $nextCommand);
      else
        $this->pendingCommands [] = $nextCommand;
      
      // Try to issue the command
      $this->ftpStartPendingCommand ();
      
      return $deferredPromise->getPromise ();
    }
    // }}}
    
    // {{{ ftpDataCommandStream
    /**
     * Setup a data-connection and run a given command on that
     * 
     * @param string $commandName The command to issue once the connection was established
     * @param array $commandParameters (optional) Parameters to pass to the previous command
     * @param enum $dataType (optional) Character Representation-Type
     * @param enum $dataStructure (optional) File-Structure-Type
     * @param enum $dataMode (optional) Transfer-Mode
     * @param bool $waitForStream (optional) Wait for the data-stream to be finished until final promise returns
     * 
     * @access private
     * @return Events\Promise
     **/
    private function ftpDataCommandStream (string $commandName, array $commandParameters = null, int $dataType = null, int $dataStructure = null, int $dataMode = null, bool $waitForStream = true) : Events\Promise {
      // Prepare parameters
      static $typeMap = [
        self::TYPE_ASCII => 'A',
        self::TYPE_EBCDIC => 'E',
        self::TYPE_IMAGE => 'I',
      ];
      
      static $structureMap = [
        self::STRUCTURE_FILE => 'F',
        self::STRUCTURE_RECORD => 'R',
        self::STRUCTURE_PAGE => 'P',
      ];
      
      static $modeMap = [
        self::MODE_STREAM => 'S',
        self::MODE_BLOCK => 'B',
        self::MODE_COMPRESSED => 'C',
      ];
      
      $dataType = $typeMap [$dataType] ?? self::TYPE_ASCII;
      $dataStructure = $structureMap [$dataStructure] ?? self::STRUCTURE_FILE;
      $dataMode = $modeMap [$dataMode] ?? self::MODE_STREAM;
      
      if ($dataType !== null)
        $typePromise = $this->ftpCommand (
          'TYPE',
          [ $dataType ]
        );
      else
        $typePromise = Events\Promise::resolve ();
      
      return $typePromise->then (
        function () use ($dataStructure) {
          if ($dataStructure === null)
            return;
          
          return $this->ftpCommand (
            'STRU',
            [ $dataStructure ],
            null,
            true
          );
        }
      )->then (
        function () use ($dataMode) {
          if ($dataMode === null)
            return;
          
          return $this->ftpCommand (
            'MODE',
            [ $dataMode ],
            null,
            true
          );
        }
      )->then (
        function () {
          return $this->ftpCommand (
            'PASV',
            null,
            null,
            true
          );
        }
      )->then (
        function (int $responseCode, string $responseText) use ($commandName, $commandParameters, $waitForStream) {
          // Sanatize the respose
          if ((($s = strpos ($responseText, '(')) === false) ||
              (($e = strpos ($responseText, ')', $s)) === false))
            throw new \Error ('Missing host-address on PASV-Response');
          
          // Parse the destination
          $destinationHost = explode (',', substr ($responseText, $s + 1, $e - $s - 1));
          
          $destinationIP = $destinationHost [0] . '.' . $destinationHost [1] . '.' . $destinationHost [2] . '.' . $destinationHost [3];
          $destinationPort = ((int)$destinationHost [4] << 8) | (int)$destinationHost [5];
          
          // Block execution of further commands
          $this->activeCommand = true;
          
          // Create a socket to this connection
          $dataSocket = new Events\Socket ($this->sourceStream->getEventBase ());
          
          return $dataSocket->connect ($destinationIP, $destinationPort, $dataSocket::TYPE_TCP)->then (
            function () use ($dataSocket, $commandName, $commandParameters, $waitForStream) {
              // Unblock FTP-Commands
              if ($this->activeCommand === true)
                $this->activeCommand = null;
              
              // Issue the original command
              $commandPromise = $this->ftpCommand (
                $commandName,
                $commandParameters,
                function () { }
              )->catch (
                function () use ($dataSocket) {
                  $dataSocket->close ();
                  
                  throw new Events\Promise\Solution (func_get_args ());
                }
              );
              
              if ($waitForStream)
                $commandPromise = $commandPromise->then (
                  function (int $responseCode, string $responseText) use ($dataSocket) {
                    if ($dataSocket->isConnected ())
                      return $dataSocket->once (
                        'eventClosed',
                        function () use ($responseCode, $responseText) {
                          return new Events\Promise\Solution ([ $responseCode, $responseText ]);
                        }
                      );
                    
                    return new Events\Promise\Solution ([ $responseCode, $responseText ]);
                  }
                );
              
              // Forward the result
              return new Events\Promise\Solution ([ $dataSocket, $commandPromise ]);
            },
            function () {
              // Unblock FTP-Commands
              if ($this->activeCommand === true)
                $this->activeCommand = null;
              
              // Abort the last attemp
              $this->ftpCommand ('ABOR', null, null, true);
              
              // Throw an error
              throw new \Error ('Failed to establish FTP-Data-Connection');
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ ftpDataCommandBuffered
    /**
     * Run an FTP-Command that retrives it results via a data-stream and return the result as whole
     * 
     * @param string $commandName The command to issue once the connection was established
     * @param array $commandParameters (optional) Parameters to pass to the previous command
     * @param enum $Type (optional) Character Representation-Type
     * @param enum $Structure (optional) File-Structure-Type
     * @param enum $Mode (optional) Transfer-Mode
     * 
     * @access private
     * @return Events\Promise
     **/
    private function ftpDataCommandBuffered (string $commandName, array $commandParameters = null, int $dataType = null, int $dataStructure = null, int $dataMode = null) : Events\Promise {
      // Create a local buffer
      $readBuffer = '';
      
      // Run the command with a "normal" stream
      return $this->ftpDataCommandStream (
        $commandName,
        $commandParameters,
        $dataType,
        $dataStructure,
        $dataMode,
        true
      )->then (
        function (ABI\Stream $commandStream, Events\Promise $finalPromise) {
          // Create a local buffer
          $readBuffer = '';
          
          $commandStream->addHook (
            'eventReadable',
            function (ABI\Stream $commandStream) use (&$readBuffer) {
              $readBuffer .= $commandStream->read ();
            }
          );
          
          return $finalPromise->then (
            function (int $responseCode, string $responseText) use ($commandStream, &$readBuffer) {
              $commandStream->removeHooks ('eventReadable');
              
              return new Events\Promise\Solution ([ $responseCode, $responseText, $readBuffer ]);
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ ftpStartPendingCommand
    /**
     * Try to write the next command in queue to the wire
     * 
     * @access private
     * @return void
     **/
    private function ftpStartPendingCommand () : void {
      // Check if another command is still active or if there are no pending commands
      if ($this->activeCommand || (count ($this->pendingCommands) == 0))
        return;
      
      // Move next pending command to active
      $this->activeCommand = array_shift ($this->pendingCommands);
      
      // Write the command to the wire
      $this->writeCommand ($this->activeCommand [0], $this->activeCommand [1]);
    }
    // }}}
    
    // {{{ writeCommand
    /**
     * Write a given command to the wire
     * 
     * @param string $commandName
     * @param array $commandParameters
     * 
     * @access private
     * @return void
     **/
    private function writeCommand (string $commandName, array $commandParameters = null) : void {
      // Start the commandline with the command
      $commandLine = $commandName;
      
      // Check wheter to append some parameters
      if ($commandParameters)
        # TODO: What if a parameter contains a space?
        $commandLine .= ' ' . implode (' ', $commandParameters);
      
      // Raise a callback for this
      if ($this->___callback ('ftpWrite', $commandLine) === false)
        return;
      
      // Terminate the commandline
      $commandLine .= "\r\n";
      
      // Write out the command
      $this->sourceStream->write ($commandLine);
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param ABI\Source $sourceStream
     * 
     * @access public
     * @return Events\Promise
     **/
    public function initStreamConsumer (ABI\Stream $sourceStream) : Events\Promise {
      // Reject any pending initialization-promise
      if ($this->initPromise)
        $this->initPromise->reject ('Replaced by new source-stream');
      
      // Update our internal state
      $this->ftpState = self::STATE_CONNECTING;
      $this->inputBuffer = '';
      $this->receiveBuffer = '';
      $this->activeCommand = null;
      $this->pendingCommands = [ ];
      $this->sourceStream = $sourceStream;
      $this->initPromise = new Events\Promise\Defered ();
      
      return $this->initPromise->getPromise ();
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param ABI\Source $sourceStream
     * 
     * @access public
     * @return Events\Promise 
     **/
    public function deinitConsumer (ABI\Source $sourceStream) : Events\Promise {
      if ($this->initPromise) {
        $this->initPromise->reject ('deinitConsumer() callbed before stream was initialized');
        $this->initPromise = null;
      }
      
      return Events\Promise::resolve ();
    }
    // }}}
    
    protected function ftpRead (int $responseCode, string $responseText) : void { }
    protected function ftpWrite (string $commandLine) : void { }
    protected function ftpConnected () : void { }
    protected function ftpDisconnected () : void { }
    protected function ftpAuthenticated (string $userName, string $userAccount = null) : void { }
    protected function ftpWorkingDirectory (string $newDirectory) : void { }
    protected function ftpProtocolError () : void { }
    protected function eventReadable () : void { }
    protected function eventClosed () : void { }
  }
