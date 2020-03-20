<?PHP

  /**
   * qcEvents - Asyncronous FTP Client-Stream
   * Copyright (C) 2015-2020 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  require_once ('qcEvents/Promise.php');
  require_once ('qcEvents/Defered.php');
  
  /**
   * FTP Client Stream
   * -----------------
   * FTP Client Implementation (RFC 969)
   * This Stream is implemented independant of the underlying Stream.
   * It can be anything from a standard-compilant TCP-Socket to a pipe. Feel free!
   * 
   * @see https://tools.ietf.org/html/rfc959
   * 
   * @class qcEvents_Stream_FTP_Client
   * @extends qcEvents_Hookable
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
  class qcEvents_Stream_FTP_Client extends qcEvents_Hookable implements qcEvents_Interface_Stream_Consumer {
    /* FTP-Protocol states */
    const STATE_DISCONNECTED = 0;
    const STATE_CONNECTING = 1;
    const STATE_CONNECTED = 2;
    const STATE_AUTHENTICATING = 3;
    const STATE_AUTHENTICATED = 4;
    
    private $ftpState = qcEvents_Stream_FTP_Client::STATE_DISCONNECTED;
    
    /* Representation-Types */
    const TYPE_ASCII = 0;
    const TYPE_EBCDIC = 1;
    const TYPE_IMAGE = 2;
    
    /* Structure types */
    const STRUCTURE_FILE = 0;
    const STRUCTURE_RECORD = 1;
    const STRUCTURE_PAGE = 2;
    
    /* File-Transfer modes */
    const MODE_STREAM = 0;
    const MODE_BLOCK = 1;
    const MODE_COMPRESSED = 2;
    
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
    private $pendingCommands = array ();
    
    // {{{ noOp
    /**
     * Just do nothing, but send something over the wire
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function noOp () : qcEvents_Promise {
      return $this->ftpCommand ('NOOP')->then (
        function () { }
      );
    }
    // }}}
    
    // {{{ authenticate
    /**
     * Try to authenticate this FTP-Stream
     * 
     * @param string $Username
     * @param string $Password
     * @param string $Account (optional)
     * 
     * @access public
     * @return qcEvents_Promsie
     **/
    public function authenticate ($Username, $Password, $Account = null) : qcEvents_Promise {
      return $this->ftpCommand (
        'USER',
        array ($Username),
        function (qcEvents_Stream_FTP_Client $Self, $Code, $Response) use ($Password, $Account) {
          // A USER-Call here would result in 331 or 332
          // A PASS-Call here would result in        332
          // A ACCT-Call will never get here
          
          // Update our state
          $this->ftpState = self::STATE_AUTHENTICATING;
          
          // Write out the password if it is required
          if ($Code == 331)
            return $this->writeCommand ('PASS', array ($Password));
          
          // Check for a protocoll-violation
          if ($Code != 332)
            return $this->raiseProtocolError ();
          
          // Check if we have an account available
          if ($Account === null)
            return false;
          
          return $this->writeCommand ('ACCT', array ($Account));
        }
      )->then (
        function ($responseCode, $responseText) use ($Username, $Account) {
          // A USER-Call here would result in      230, 530, 500, 501,      421
          // A PASS-Call here would result in 202, 230, 530, 500, 501, 503, 421
          // A ACCT-Call here would result in 202, 230, 530, 500, 501, 503, 421
          
          // Update the state
          $this->ftpState = self::STATE_AUTHENTICATED;
          
          // Fire the callback
          $this->___callback ('ftpAuthenticated', $Username, $Account);
        },
        function () {
          // Update the state
          $this->ftpState = self::STATE_CONNECTED;
          
          // Forward the rejection
          throw new qcEvents_Promise_Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ changeDirectory
    /**
     * Change working-directory on server
     * 
     * @param string $Path The path of the directory to change to
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function changeDirectory ($Path) : qcEvents_Promise {
      return $this->ftpCommand (
        'CWD',
        array ($Path)
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
     * @return qcEvents_Promise
     **/
    public function getWorkingDirectory () : qcEvents_Promise {
      return $this->ftpCommand ('PWD')->then (
        function ($responseCode, $responseText) {
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
     * @param string $Path (optional) Pathname for file
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function getStatus ($Path = null) : qcEvents_Promise {
      return $this->runFTPCommand (
        'STAT',
        ($Path ? array ($Path) : null)
      )->then (
        function ($responseCode, $responseText) {
          return $responseText;
        }
      );
    }
    // }}}
    
    // {{{ getFilenames
    /**
     * Retrive all filenames existant at a given path or the current one
     * 
     * @param string $Path (optional) The path to use, if NULL the current one will be used
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function getFilenames ($Path = null) : qcEvents_Promise {
      return $this->ftpDataCommandBuffered (
        'NLST',
        $Path,
        self::TYPE_ASCII,
        self::STRUCTURE_FILE,
        self::MODE_STREAM,
      )->then (
        function ($responseCode, $responseText, $responseData) {
          return explode ("\r\n", substr ($responseData, 0, -2));
        }
      );
    }
    // }}}
    
    // {{{ retriveFileStream
    /**
     * Download a file from the server, return a stream-handle for further processing
     * 
     * @param string $Filename Path of the file to download
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function retriveFileStream ($Filename) : qcEvents_Promise {
      return $this->runFTPDataCommandStream (
        'RETR',
        $Filename,
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
     * @return qcEvents_Promise
     **/
    public function downloadFile ($remotePath, $localPath) : qcEvents_Promise {
      return $this->retriveFileStream (
        $remotePath
      )->then (
        function (qcEvents_Interface_Stream $commandStream, qcEvents_Promise $finalPromise) use ($localPath) {
          $commandStream->pipe (new qcEvents_File ($commandStream->getEventBase (), $localPath, false, true));
          
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
     * @return qcEvents_Promise
     **/
    public function downloadFileBuffered ($remotePath) : qcEvents_Promise {
      return $this->ftpDataCommandBuffered (
        'RETR',
        $Filename,
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
     * @return qcEvents_Promise
     **/
    public function close () : qcEvents_Promise {
      // Try to close gracefully
      if (!$this->initPromise && $this->sourceStream && ($this->ftpState != self::STATE_CONNECTING))
        return $this->ftpCommand ('QUIT')->catch (function () { })->then (
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
      
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    // {{{ consume
    /**
     * Consume a set of data
     * 
     * @param mixed $inputData
     * @param qcEvents_Interface_Source $sourceStream
     * 
     * @access public
     * @return void
     **/
    public function consume ($inputData, qcEvents_Interface_Source $sourceStream) {
      // Append to internal buffer
      $this->inputBuffer .= $inputData;
      unset ($inputData);

      // Check if we have received complete lines
      $s = 0;

      while (($e = strpos ($this->inputBuffer, "\r\n", $s)) !== false) {
        // Peek the next complete line
        $Line = substr ($this->inputBuffer, $s, $e - $s);
        
        // Move pointer to end-of-line
        $s = $e + 2;

        // Check if this is a final response
        $Code = substr ($Line, 0, 3);

        if (($Line [3] == ' ') && (($iCode = intval ($Code)) == $Code) && ((strlen ($this->receiveBuffer) < 3) || ($Code == substr ($this->receiveBuffer, 0, 3)))) {
          $this->processFTPResponse ($iCode, rtrim (substr ($this->receiveBuffer, 4) . substr ($Line, 4)));
          $this->receiveBuffer = '';

        // Just append to buffer
        } else
          $this->receiveBuffer .= trim ($Line) . "\n";
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
     * @param int $Code The response-code from the server
     * @param string $Response The text-response from the server
     * 
     * @access private
     * @return void
     **/
    private function processFTPResponse ($Code, $Response) {
      // Run initial callback for this
      if ($this->___callback ('ftpRead', $Code, $Response) === false)
        return fasle;
      
      // Check if we are receiving a HELO from FTP
      if ($this->ftpState == self::STATE_CONNECTING) {
        // Check the code
        if (($Code >= 100) && ($Code < 200))
          return;
        
        // Change our state
        if ($Code < 400) {
          $this->ftpState = self::STATE_CONNECTED;
          $this->___callback ('ftpConnected');
        } else {
          $this->ftpState = self::STATE_DISCONNECTED;
          $this->___callback ('ftpDisconnected');
        }
        
        // Fire the callback
        if ($this->initPromise) {
          if ($Code < 400)
            $this->initPromise->resolve ();
          else
            $this->initPromise->reject ('Received ' . $Code);
          
          $this->initPromise = null;
        }
        
        return;
      }
      
      // Look for an active command
      if (!$this->activeCommand)
        return $this->raiseProtocolError ();
      
      // Check for an intermediate reply
      if ((($Code >= 100) && ($Code < 200)) || (($Code >= 300) && ($Code < 400))) {
        // Check if the command is prepared for this
        if (!$this->activeCommand [2])
          return $this->ftpFinishCommand (false, $Code, $Response);
        
        // Fire a callback for this
        if ($this->___raiseCallback ($this->activeCommand [2], $Code, $Response) === false)
          return $this->ftpFinishCommand (false, $Code, $Response);
        
        return;
      }
      
      return $this->ftpFinishCommand ((($Code >= 200) && ($Code < 300)), $Code, $Response);
    }
    // }}}
    
    // {{{ ftpFinishCommand
    /**
     * Finish the current command that is being processed and move to the next one
     * 
     * @param bool $Status The overall Status-Indicator
     * @param int $Code The last response-code received from the server
     * @param stirng $Response The last text-response received from the server
     * 
     * @access private
     * @return void
     **/
    private function ftpFinishCommand ($Status, $Code, $Response) {
      // Peek and free the current command
      $activeCommand = $this->activeCommand;
      $this->activeCommand = null;
      
      // Resolve the promise
      if ($Status)
        $activeCommand [3]->resolve ($Code, $Response);
      else
        $activeCommand [3]->reject ($Response, $Code);
      
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
    private function raiseProtocolError () {
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
     * 
     * The intermediate callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_FTP_Client $Self, int $Code, string $Response) { }
     * 
     * If the intermediate callback returns FALSE the entire operation will be canceled
     * 
     * @access private
     * @return qcEvents_Promise
     **/
    private function ftpCommand ($commandName, array $commandParameters = null, callable $intermediateCallback = null, $forceNext = false) : qcEvents_Promise {
      // Create a promse for that
      $deferredPromise = new qcEvents_Defered;
      
      // Append the command to our queue
      $nextCommand = array (
        $commandName,
        $commandParameters,
        $intermediateCallback,
        $deferredPromise
      );
      
      if ($forceNext)
        array_unshift ($this->pendingCommands, $nextCommand);
      else
        $this->pendingCommands [] = $nextCommand;
      
      // Try to issue the command
      $this->ftpStartPendingCommand ();
      
      return $deferredPromise->getPromise ();
    }
    // }}}
    
    // {{{ runFTPDataCommandStream
    /**
     * Setup a data-connection and run a given command on that
     * 
     * @param string $commandName The command to issue once the connection was established
     * @param array $commandParameters (optional) Parameters to pass to the previous command
     * @param enum $Type (optional) Character Representation-Type
     * @param enum $Structure (optional) File-Structure-Type
     * @param enum $Mode (optional) Transfer-Mode
     * @param bool $waitForStream (optional) Wait for the data-stream to be finished until final promise returns
     * 
     * @access private
     * @return qcEvents_Promise
     **/
    private function ftpDataCommandStream ($commandName, array $commandParameters = null, $Type = self::TYPE_ASCII, $Structure = self::STRUCTURE_FILE, $Mode = self::MODE_STREAM, $waitForStream = true) : qcEvents_Promise {
      // Prepare parameters
      static $tMap = array (
        self::TYPE_ASCII => 'A',
        self::TYPE_EBCDIC => 'E',
        self::TYPE_IMAGE => 'I',
      );
      
      static $sMap = array (
        self::STRUCTURE_FILE => 'F',
        self::STRUCTURE_RECORD => 'R',
        self::STRUCTURE_PAGE => 'P',
      );
      
      static $mMap = array (
        self::MODE_STREAM => 'S',
        self::MODE_BLOCK => 'B',
        self::MODE_COMPRESSED => 'C',
      );
      
      $Type = (isset ($tMap [$Type]) ? $tMap [$Type] : self::TYPE_ASCII);
      $Structure = (isset ($sMap [$Structure]) ? $sMap [$Structure] : self::STRUCTURE_FILE);
      $Mode = (isset ($mMap [$Mode]) ? $mMap [$Mode] : self::MODE_STREAM);
      
      if ($Type !== null)
        $typePromise = $this->ftpCommand (
          'TYPE',
          array ($Type)
        );
      else
        $typePromise = qcEvents_Promise::resolve ();
      
      return $typePromise->then (
        function () use ($Structure) {
          if ($Structure === null)
            return;
          
          return $this->ftpCommand (
            'STRU',
            array ($Structure),
            null,
            true
          );
        }
      )->then (
        function () use ($Mode) {
          if ($Mode === null)
            return;
          
          return $this->ftpCommand (
            'MODE',
            array ($Mode),
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
        function ($responseCode, $responseText) use ($commandName, $commandParameters, $waitForStream) {
          // Sanatize the respose
          if ((($s = strpos ($responseText, '(')) === false) ||
              (($e = strpos ($responseText, ')', $s)) === false))
            throw new Error ('Missing host-address on PASV-Response');
          
          // Parse the destination
          $Host = explode (',', substr ($responseText, $s + 1, $e - $s - 1));
          
          $IP = $Host [0] . '.' . $Host [1] . '.' . $Host [2] . '.' . $Host [3];
          $Port = (intval ($Host [4]) << 8) | intval ($Host [5]);
          
          // Block execution of further commands
          $this->activeCommand = true;
          
          // Create a socket to this connection
          $dataSocket = new qcEvents_Socket ($this->sourceStream->getEventBase ());
          
          return $dataSocket->connect ($IP, $Port, $Socket::TYPE_TCP)->then (
            function () use ($dataSocket, $commandName, $commandParameters, $waitForStream) {
              // Unblock FTP-Commands
              if ($this->activeCommand === true)
                $this->activeCommand = null;
              
              // Issue the original command
              $commandPromise = $this->ftpCommand (
                $commandName,
                $commandParameters
              )->catch (
                function () use ($dataSocket) {
                  $dataSocket->close ();
                  
                  throw new qcEvents_Promise_Solution (func_get_args ());
                }
              );
              
              if ($waitForStream)
                $commandPromise = $commandPromise->then (
                  function ($responseCode, $responseText) use ($dataSocket) {
                    if ($dataSocket->isConnected ())
                      return $dataSocket->once (
                        'eventClosed',
                        function () use ($responseCode, $responseText) {
                          return qcEvents_Promise_Solution (array ($responseCode, $responseText));
                        }
                      );
                    
                    return qcEvents_Promise_Solution (array ($responseCode, $responseText));
                  }
                );
              
              // Forward the result
              return new qcEvents_Promise_Solution (array ($dataSocket, $commandPromise));
            },
            function () {
              // Unblock FTP-Commands
              if ($this->activeCommand === true)
                $this->activeCommand = null;
              
              // Abort the last attemp
              $this->ftpCommand ('ABOR', null, null, true);
              
              // Throw an error
              throw new Error ('Failed to establish FTP-Data-Connection');
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
     * @return void
     **/
    private function ftpDataCommandBuffered ($commandName, array $commandParameters = null, $Type = null, $Structure = null, $Mode = null) : qcEvents_Promise {
      // Create a local buffer
      $readBuffer = '';
      
      // Run the command with a "normal" stream
      return $this->runFTPDataCommandStream (
        $commandName,
        $commandParameters,
        $Type,
        $Structure,
        $Mode,
        true
      )->then (
        function (qcEvents_Interface_Stream $commandStream, qcEvents_Promise $finalPromise) {
          // Create a local buffer
          $readBuffer = '';
          
          $commandStream->addHook (
            'eventReadable',
            function (qcEvents_Interface_Stream $commandStream) use (&$readBuffer) {
              $readBuffer .= $commandStream->read ();
            }
          );
          
          return $finalPromise->then (
            function ($responseCode, $responseText) use ($commandStream, &$readBuffer) {
              $commandStream->removeHooks ('eventReadable');
              
              return $readBuffer;
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
    private function ftpStartPendingCommand () {
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
    private function writeCommand ($commandName, array $commandParameters = null) {
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
     * @param qcEvents_Interface_Source $sourceStream
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function initStreamConsumer (qcEvents_Interface_Stream $sourceStream) : qcEvents_Promise {
      // Reject any pending initialization-promise
      if ($this->initPromise)
        $this->initPromise->reject ('Replaced by new source-stream');
      
      // Update our internal state
      $this->ftpState = self::STATE_CONNECTING;
      $this->inputBuffer = '';
      $this->receiveBuffer = '';
      $this->activeCommand = null;
      $this->pendingCommands = array ();
      $this->sourceStream = $sourceStream;
      $this->initPromise = new qcEvents_Defered;
      
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
      if ($this->initPromise) {
        $this->initPromise->reject ('deinitConsumer() callbed before stream was initialized');
        $this->initPromise = null;
      }
      
      return qcEvents_Promise::resolve ();
    }
    // }}}
    
    protected function ftpRead ($responseCode, $responseText) { }
    protected function ftpWrite ($commandLine) { }
    protected function ftpConnected () { }
    protected function ftpDisconnected () { }
    protected function ftpAuthenticated ($Username, $Account = null) { }
    protected function ftpWorkingDirectory ($Path) { }
    protected function ftpProtocolError () { }
    protected function eventReadable () { }
    protected function eventClosed () { }
  }

?>