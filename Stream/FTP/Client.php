<?PHP

  /**
   * qcEvents - Asyncronous FTP Client-Stream
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
  
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Interface/Stream/Consumer.php');
  
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
    const STATE_CONNECTING = 0;
    const STATE_CONNECTED = 1;
    const STATE_AUTHENTICATING = 2;
    const STATE_AUTHENTICATED = 3;
    const STATE_DISCONNECTED = 4;
    
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
    
    /* Current protocol-state */
    private $State = qcEvents_Stream_FTP_Client::STATE_CONNECTING;
    
    /* Entire Input-Buffer */
    private $Buffer = '';
    
    /* Buffer for current response */
    private $rBuffer = '';
    
    private $Stream = null;
    private $StreamCallback = null;
    
    private $Command = null;
    private $CommandQueue = array ();
    
    // {{{ noop
    /**
     * Just do nothing, but send something over the wire
     * 
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access public
     * @return void
     **/
    public function noop (callable $Callback = null, $Private = null) {
      return $this->runFTPCommand ('NOOP', null, function () use ($Callback, $Private) { $this->___raiseCallback ($Callback, $Private); });
    }
    // }}}
    
    // {{{ authenticate
    /**
     * Try to authenticate this FTP-Stream
     * 
     * @param string $Username
     * @param string $Password
     * @param string $Account (optional)
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_FTP_Client $Self, bool $Status, string $Username, string $Account = null, mixed $Private = null) { }
     * 
     * @access public
     * @return void
     **/
    public function authenticate ($Username, $Password, $Account = null, callable $Callback = null, $Private = null) {
      return $this->runFTPCommand (
        'USER', $Username,
        function (qcEvents_Stream_FTP_Client $Self, $Status, $Code, $Response) use ($Username, $Password, $Account, $Callback, $Private) {
          // A USER-Call here would result in      230, 530, 500, 501,      421
          // A PASS-Call here would result in 202, 230, 530, 500, 501, 503, 421
          // A ACCT-Call here would result in 202, 230, 530, 500, 501, 503, 421
          
          // Update the state
          $this->State = ($Status ? self::STATE_AUTHENTICATED : self::STATE_CONNECTED);
          
          // Fire the callback
          if ($Status)
            $this->___callback ('ftpAuthenticated', $Username, $Account);
          
          $this->___raiseCallback ($Callback, $Status, $Username, $Account, $Private);
        }, null,
        function (qcEvents_Stream_FTP_Client $Self, $Code, $Response) use ($Password, $Account) {
          // A USER-Call here would result in 331 or 332
          // A PASS-Call here would result in        332
          // A ACCT-Call will never get here
          
          // Update our state
          $this->State = self::STATE_AUTHENTICATING;
          
          // Write out the password if it is required
          if ($Code == 331)
            return $this->writeCommand ('PASS', $Password);
          
          // Check for a protocoll-violation
          if ($Code != 332)
            return $this->raiseProtocolError ();
          
          // Check if we have an account available
          if ($Account === null)
            return false;
          
          return $this->writeCommand ('ACCT', $Account);
        }
      );
    }
    // }}}
    
    // {{{ changeDirectory
    /**
     * Change working-directory on server
     * 
     * @param string $Path The path of the directory to change to
     * @param callable $Callback (optional) A callback to raise once the operation is complete
     * @param mixed $Private (optional) A private parameter to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_FTP_Client $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void
     **/
    public function changeDirectory ($Path, callable $Callback = null, $Private = null) {
      return $this->runFTPCommand (
        'CWD', $Path,
        function (qcEvents_Stream_FTP_Client $Self, $Status, $Code, $Response) use ($Callback, $Private) {
          $this->___raiseCallback ($Callback, $Status, $Private);
        }
      );    
    }
    // }}}
    
    // {{{ getWorkingDirectory
    /**
     * Retrive the current working-directory from FTP-Server
     * 
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_FTP_Client $Self, string $Path, mixed $Private = null) { }
     * 
     * If there was an error $Path is FALSE.
     * 
     * @access public
     * @return void
     **/
    public function getWorkingDirectory (callable $Callback, $Private = null) {
      return $this->runFTPCommand (
        'PWD', null,
        function (qcEvents_Stream_FTP_Client $Self, $Status, $Code, $Response) use ($Callback, $Private) {
          if ($Status) {
            $Path = $Response;
            
            if ($Path [0] == '"')
              $Path = substr ($Path, 1, strpos ($Path, '"', 2) - 1);
            
            $this->___callback ('ftpWorkingDirectory', $Path);
          } else
            $Path = false;
          
          $this->___raiseCallback ($Callback, $Path, $Private);
        }
      );
    }
    // }}}
    
    // {{{ getStatus
    /**
     * Retrive the status of FTP-Server or a file on that server
     * 
     * @param string $Path (optional) Pathname for file
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_FTP_Client $Self, string $Status = null, mixed $Private = null) { }
     * 
     * @access public
     * @return void
     **/
    public function getStatus ($Path = null, callable $Callback, $Private = null) {
      return $this->runFTPCommand (
        'STAT', $Path,
        function (qcEvents_Stream_FTP_Client $Self, $Status, $Code, $Response) use ($Callback, $Private) {
          $this->___raiseCallback ($Callback, ($Status ? $Response : null), $Private);
        }
      );
    }
    // }}}
    
    // {{{ getFilenames
    /**
     * Retrive all filenames existant at a given path or the current one
     * 
     * @param string $Path (optional) The path to use, if NULL the current one will be used
     * @param callable $Callback (optional) A callback to be raised once the operation was completed
     * @param mixed $Private (optional) A private parameter to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_FTP_Client $Self, array $Files = null, mixed $Private = null) { }
     * 
     * If there was an error during the execution, $Files will be NULL.
     * 
     * @access public
     * @return void
     **/
    public function getFilenames ($Path = null, callable $Callback, $Private = null) {
      return $this->runFTPDataCommandBuffered (
        'NLST', $Path,
        self::TYPE_ASCII,
        self::STRUCTURE_FILE,
        self::MODE_STREAM,
        
        // Callback to be raised once the transfer was completed
        function (qcEvents_Stream_FTP_Client $Self, $Status, $Buffer, $Code, $Response) use ($Callback, $Private) {
          if ($Status)
            $Files = explode ("\r\n", substr ($Buffer, 0, -2));
          else
            $Files = null;
          
          $this->___raiseCallback ($Callback, $Files, $Private);
        }
      );
    }
    // }}}
    
    // {{{ retriveFileStream
    /**
     * Download a file from the server, return a stream-handle for further processing
     * 
     * @param string $Filename Path of the file to download
     * @param callable $Callback A callback to raise once the stream is ready
     * @param mixed $Private (optional) Any private parameter to pass to the callback
     * @param callable $finalCallback (optional) A callback to raise once the download was completed
     * @param mixed $finalPrivate (optional) Any private parameter to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_FTP_Client $Self, string $Filename, qcEvents_Interface_Stream $Stream = null, mixed $Private = null) { }
     * 
     * The final callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_FTP_Client $Self, string $Filename, bool $Status, qcEvents_Interface_Stream $Stream = null, mixed $Private = null) { }
     * 
     * @access public
     * @return void
     **/
    public function retriveFileStream ($Filename, callable $Callback, $Private = null, callable $finalCallback = null, $finalPrivate = null) {
      $Input = null;
      
      return $this->runFTPDataCommandStream (
        'RETR', $Filename,
        self::TYPE_IMAGE,
        self::STRUCTURE_FILE,
        self::MODE_STREAM,
        
        function (qcEvents_Stream_FTP_Client $Self, $Status, $Code, $Response) use ($Filename, $finalCallback, $finalPrivate, &$Input) {
          $this->___raiseCallback ($finalCallback, $Filename, $Status, $Input, $finalPrivate);
        }, null,
        function (qcEvents_Stream_FTP_Client $Self, qcEvents_Interface_Stream $Stream = null)  use ($Filename, $Callback, $Private, &$Input) {
          $Input = $Stream;
          $this->___raiseCallback ($Callback, $Filename, $Stream, $Private);
        }
      );
    }
    // }}}
    
    // {{{ downloadFile
    /**
     * Download file from server and write to filesystem
     * 
     * @param string $remotePath Path of file on server
     * @param string $localPath Local path to write the file to
     * @param callable $Callback (optional) A callback to raise once the operation is complete
     * @param mixed $Private (optional) Private data to pass to the callback
     * 
     * @access public
     * @return void
     **/
    public function downloadFile ($remotePath, $localPath, callable $Callback = null, $Private = null) {
      return $this->retriveFileStream (
        $remotePath,
        
        // Pipe the stream to a local file once it is ready
        function (qcEvents_Stream_FTP_Client $Self, $Filename, qcEvents_Interface_Stream $Stream = null) use ($localPath) {
          if ($Stream)
            $Stream->pipe (new qcEvents_File ($Stream->getEventBase (), $localPath, false, true));
        }, null,
        
        // Just forward the result once it is completed 
        function (qcEvents_Stream_FTP_Client $Self, $Filename, $Status, qcEvents_Interface_Stream $Stream = null) use ($Callback, $Private) {
          $this->___raiseCallback ($Callback, $Status, $Private);
        }
      );
    }
    // }}}
    
    // {{{ downloadFileBuffered
    /**
     * Download file from server and write to filesystem
     * 
     * @param string $remotePath Path of file on server
     * @param callable $Callback A callback to raise once the operation is complete
     * @param mixed $Private (optional) Private data to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_FTP_Client $Self, string $remotePath, string $Content, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void
     **/
    public function downloadFileBuffered ($remotePath, callable $Callback, $Private = null) {
      $Buffer = '';
      
      return $this->retriveFileStream (
        $remotePath,
        
        // Pipe the stream to a local file once it is ready
        function (qcEvents_Stream_FTP_Client $Self, $Filename, qcEvents_Interface_Stream $Stream = null) use (&$Buffer) {
          if (!$Stream)
            return;
          
          $Stream->addHook ('eventReadable', function (qcEvents_Interface_Stream $Stream) use (&$Buffer) {
            $Buffer .= $Stream->read ();
          });
        }, null,
        
        // Just forward the result once it is completed 
        function (qcEvents_Stream_FTP_Client $Self, $Filename, $Status, qcEvents_Interface_Stream $Stream = null) use (&$Buffer, $Callback, $Private) {
          // Reset the buffer if download failed
          if (!$Status)
            $Buffer = null;
          
          // Raise the callback
          $this->___raiseCallback ($Callback, $Filename, $Buffer, $Status, $Private);
        }
      );
    }
    // }}}
    
    // {{{ close
    /**
     * Close this event-interface
     * 
     * @param callable $Callback (optional) Callback to raise once the interface is closed
     * @param mixed $Private (optional) Private data to pass to the callback
     * 
     * @access public
     * @return void
     **/
    public function close (callable $Callback = null, $Private = null) {
      // Try to close gracefully
      if (!$this->StreamCallback && $this->Stream && ($this->State != self::STATE_CONNECTING))
        return $this->runFTPCommand ('QUIT', null, function () use ($Callback, $Private) {
          if ($this->Stream)
            return $this->Stream->close (function () use ($Callback, $Private) {
              $this->___callback ('eventClosed');
              $this->___raiseCallback ($Callback, $Private);
            });
          
          $this->___callback ('eventClosed');
          $this->___raiseCallback ($Callback, $Private);
        });
      
      $this->___raiseCallback ($this->StreamCallback [0], $this->StreamCallback [1], false, $this->StreamCallback [2]);
      $this->StreamCallback = null;
      
      $this->___callback ('eventClosed');
      $this->___raiseCallback ($Callback, $Private);
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
      // Append to internal buffer
      $this->Buffer .= $Data;
      unset ($Data);

      // Check if we have received complete lines
      $s = 0;

      while (($e = strpos ($this->Buffer, "\r\n", $s)) !== false) {
        // Peek the next complete line
        $Line = substr ($this->Buffer, $s, $e - $s);
        
        // Move pointer to end-of-line
        $s = $e + 2;

        // Check if this is a final response
        $Code = substr ($Line, 0, 3);

        if (($Line [3] == ' ') && (($iCode = intval ($Code)) == $Code) && ((strlen ($this->rBuffer) < 3) || ($Code == substr ($this->rBuffer, 0, 3)))) {
          $this->processFTPResponse ($iCode, rtrim (substr ($this->rBuffer, 4) . substr ($Line, 4)));
          $this->rBuffer = '';

        // Just append to buffer
        } else
          $this->rBuffer .= trim ($Line) . "\n";
      }

      // Truncate the input-buffer
      if ($s > 0)
        $this->Buffer = substr ($this->Buffer, $s);
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
      // Check if we are receiving a HELO from FTP
      if ($this->State == self::STATE_CONNECTING) {
        // Check the code
        if (($Code >= 100) && ($Code < 200))
          return;

        // Change our state
        if ($Code < 400) {
          $this->State = self::STATE_CONNECTED;
          $this->___callback ('ftpConnected');
        } else {
          $this->State = self::STATE_DISCONNECTED;
          $this->___callback ('ftpDisconnected');
        }

        // Fire the callback
        if ($this->StreamCallback) {
          $this->___raiseCallback ($this->StreamCallback [0], $this->StreamCallback [1], ($Code < 400), $this->StreamCallback [2]);
          $this->StreamCallback = null;
        }

        return;
      }
      
      // Look for an active command
      if (!$this->Command) {
        $this->raiseProtocolError ();
        
        return;
      }
      
      // Check for an intermediate reply
      if ((($Code >= 100) && ($Code < 200)) || (($Code >= 300) && ($Code < 400))) {
        // Check if the command is prepared for this
        if (!$this->Command [4])
          return $this->ftpFinishCommand (false, $Code, $Response);
        
        // Fire a callback for this
        if ($this->___raiseCallback ($this->Command [4], $Code, $Response, $this->Command [5]) === false)
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
      $Command = $this->Command;
      $this->Command = null;
      
      // Raise the callback
      $this->___raiseCallback ($Command [2], $Status, $Code, $Response, $Command [3]);
      
      // Move to next command
      $this->dispatchFTPCommand ();
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
    
    // {{{ runFTPCommand
    /**
     * Enqueue an FTP-Command for execution
     * 
     * @param string $Command The actual command to run
     * @param mixed $Parameters Any parameter for that command
     * @param callable $finalCallback A callback to run once the operation was completed
     * @param mixed $finalPrivate (optional) Any private parameter to pass to that callback
     * @param callable $intermediateCallback (optional) A callback to run whenever an intermediate response was received
     * @param mixed $intermediatePrivate (optional) Any private parameter to pass to that callback
     * 
     * The final callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_FTP_Client $Self, bool $Status, int $Code, string $Response, mixed $Private = null) { }
     * 
     * The intermediate callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_FTP_Client $Self, int $Code, string $Response, mixed $Private = null) { }
     * 
     * If the intermediate callback returns FALSE the entire operation will be canceled
     * 
     * @access private
     * @return void
     **/
    private function runFTPCommand ($Command, $Parameters, callable $finalCallback, $finalPrivate = null, callable $intermediateCallback = null, $intermediatePrivate = null) {
      // Append the command to our queue
      $this->CommandQueue [] = array ($Command, $Parameters, $finalCallback, $finalPrivate, $intermediateCallback, $intermediatePrivate);
      
      // Try to issue the command
      $this->dispatchFTPCommand ();
    }
    // }}}
    
    // {{{ runFTPDataCommandStream
    /**
     * Setup a data-connection and run a given command on that
     * 
     * @param string $Command The command to issue once the connection was established
     * @param mixed $Parameters Parameters to pass to the previous command
     * @param enum $Type (optional) Character Representation-Type
     * @param enum $Structure (optional) File-Structure-Type
     * @param enum $Mode (optional) Transfer-Mode
     * @param callable $finalCallback A callback to raise once the whole operation was completed
     * @param mixed $finalPrivate (optional) Any parameter to pass to the final callback
     * @param callable $intermediateCallback A callback to raise once the data-connection is ready
     * @param mixed $intermediatePrivate (optional) Any parameter to pass to the intermediate callback
     * 
     * The final callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_FTP_Client $Self, bool $Status, int $Code, string $Response, mixed $Private = null) { }
     * 
     * The intermediate callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_FTP_Client $Self, qcEvents_Interface_Stream $Stream, mixed $Private = null) { }
     * 
     * @access private
     * @return void
     **/
    private function runFTPDataCommandStream ($Command, $Parameters, $Type = self::TYPE_ASCII, $Structure = self::STRUCTURE_FILE, $Mode = self::MODE_STREAM, callable $finalCallback, $finalPrivate = null, callable $intermediateCallback, $intermediatePrivate = null) {
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
      
      // Prepare the connection-handler
      $Handler = null;
      $Handler = function (qcEvents_Stream_FTP_Client $Self, $Status, $Code, $Response) use (&$Handler, &$Type, &$Structure, &$Mode, $Command, $Parameters, $finalCallback, $finalPrivate, $intermediateCallback, $intermediatePrivate) {
        // Check if we failed
        if ($Status === false)
          return $this->___raiseCallback ($finalCallback, false, $Code, $Response, $finalPrivate);
        
        // Check if we have to setup connection-parameters
        if ($Type !== null) {
          $pCommand = 'TYPE';
          $Parameter = $Type;
          $Type = null;
        } elseif ($Structure !== null) {
          $pCommand = 'STRU';
          $Parameter = $Structure;
          $Structure = null;
        } elseif ($Mode !== null) {
          $pCommand = 'MODE';
          $Parameter = $Mode;
          $Mode = null;
        } else
          $pCommand = null;
        
        // Proceed with connection-setup
        if ($pCommand !== null) {
          // Push into normal command-queue if we do this for the first time
          if ($Status === null)
            return $this->runFTPCommand ($pCommand, $Parameter, $Handler);
          
          array_unshift (
            $this->CommandQueue,
            array ($pCommand, $Parameter, $Handler, null, null, null)
          );
        
        // or enter the connection
        } else
          array_unshift (
            $this->CommandQueue,
            array ('PASV', null, function (qcEvents_Stream_FTP_Client $Self, $Status, $Code, $Response) use ($Command, $Parameters, $finalCallback, $finalPrivate, $intermediateCallback, $intermediatePrivate) {
              // Make sure the command was successfull
              if (!$Status || (($s = strpos ($Response, '(')) === false) || (($e = strpos ($Response, ')', $s)) === false))
                return $this->___raiseCallback ($finalCallback, false, $Code, $Response, $finalPrivate);
              
              // Parse the destination
              $Host = explode (',', substr ($Response, $s + 1, $e - $s - 1));
              
              $IP = $Host [0] . '.' . $Host [1] . '.' . $Host [2] . '.' . $Host [3];
              $Port = (intval ($Host [4]) << 8) | intval ($Host [5]);
              
              // Create a socket to this connection
              $Socket = new qcEvents_Socket ($this->Stream->getEventBase ());
              
              // Try to connect to destination and forward the handle to our caller upon completion
              $Socket->connect ($IP, $Port, $Socket::TYPE_TCP, false, null, function (qcEvents_Socket $Socket, $Status) use ($intermediateCallback, $intermediatePrivate) {
                if (!$Status)
                  $Socket = null;
                
                $this->___raiseCallback ($intermediateCallback, $Socket, $intermediatePrivate);
              });
              
              // Issue the original command
              array_unshift (
                $this->CommandQueue,
                array (
                  $Command,
                  $Parameters,
                  function (qcEvents_Stream_FTP_Client $Self, $Status, $Code, $Response) use ($Socket, $finalCallback, $finalPrivate) {
                    // Check if the socket is still connected
                    if ($Socket->isConnected ())
                      return $Socket->addHook ('eventClosed', function () use ($Status, $Code, $Response, $finalCallback, $finalPrivate) {
                        $this->___raiseCallback ($finalCallback, $Status, $Code, $Response, $finalPrivate);
                      });
                    
                    $this->___raiseCallback ($finalCallback, $Status, $Code, $Response, $finalPrivate);
                  }, null,
                  function (qcEvents_Stream_FTP_Client $Self, $Code, $Response) { /* Just let this pass */ }, null)
              );
              
              // Try to issue the command 
              $this->dispatchFTPCommand ();
            },
            null, null, null)
          );
          
        // Try to issue the command 
        $this->dispatchFTPCommand ();
      };
        
      // Invoke the handler
      return $Handler ($this, null, 0, '');
    }
    // }}}
    
    // {{{ runFTPDataCommandBuffered
    /**
     * Run an FTP-Command that retrives it results via a data-stream and return the result as whole
     * 
     * @param string $Command The command to issue once the connection was established
     * @param mixed $Parameters Parameters to pass to the previous command
     * @param enum $Type (optional) Character Representation-Type
     * @param enum $Structure (optional) File-Structure-Type
     * @param enum $Mode (optional) Transfer-Mode
     * @param callable $Callback (optional) A callback to raise once the operation was completed
     * @param mixed $Private (optional) Any private parameter to pass to that parameter
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_FTP_Client $Self, bool $Status, string $Buffer = null, int $Code, string $Response, mixed $Private = null) { }
     * 
     * @access private
     * @return void
     **/
    private function runFTPDataCommandBuffered ($Command, $Parameters, $Type = null, $Structure = null, $Mode = null, callable $Callback, $Private = null) {
      // Create a local buffer
      $Buffer = '';
      
      // Run the command with a "normal" stream
      return $this->runFTPDataCommandStream (
        $Command, $Parameters, $Type, $Structure, $Mode,
        
        // Callback to be raised once the transfer was completed
        function (qcEvents_Stream_FTP_Client $Self, $Status, $Code, $Response) use ($Callback, $Private, &$Buffer) {
          $this->___raiseCallback ($Callback, $Status, $Buffer, $Code, $Response, $Private);
        }, null,
        
        // Callback to be raised once the data-stream is ready
        function (qcEvents_Stream_FTP_Client $Self, qcEvents_Interface_Stream $Stream = null) use (&$Buffer) {
          // Check if the stream is really ready
          if (!$Stream) {
            $Buffer = null;
            
            return;
          }
          
          // Register our reader-thread
          $Stream->addHook ('eventReadable', function (qcEvents_Interface_Stream $Stream) use (&$Buffer) {
            $Buffer .= $Stream->read ();
          });
        }
      );
    }
    // }}}
    
    // {{{ dispatchFTPCommand
    /**
     * Try to write the next command in queue to the wire
     * 
     * @access private
     * @return void
     **/
    private function dispatchFTPCommand () {
      if ($this->Command || (count ($this->CommandQueue) == 0))
        return;
      
      $this->Command = array_shift ($this->CommandQueue);
      $this->writeCommand ($this->Command [0], $this->Command [1]);
    }
    // }}}
    
    // {{{ writeCommand
    /**
     * Write a given command to the wire
     * 
     * @param string $Command
     * @param mixed $Parameters
     * 
     * @access private
     * @return void
     **/
    private function writeCommand ($Command, $Parameters) {
      // Start the commandline with the command
      $Commandline = $Command;
      
      // Check wheter to append some parameters
      if ($Parameters) {
        if (!is_array ($Parameters))
          $Parameters = array ($Parameters);
        
        # TODO: What if a parameter contains a space?
        $Commandline .= ' ' . implode (' ', $Parameters);
      }
      
      // Terminate the commandline
      $Commandline .= "\r\n";
      
      // Write out the command
      $this->Stream->write ($Commandline);
    }
    // }}}
    
    // {{{ initStreamConsumer
    /**
     * Setup ourself to consume data from a stream
     * 
     * @param qcEvents_Interface_Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of
     *  
     *   function (qcEvents_Interface_Stream $Source, qcEvents_Interface_Stream_Consumer $Destination, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return callable
     **/
    public function initStreamConsumer (qcEvents_Interface_Stream $Source, callable $Callback = null, $Private = null) {
      if ($this->StreamCallback)
        $this->___raiseCallback ($this->StreamCallback [0], $this->StreamCallback [1], false, $this->StreamCallback [2]);
      
      // Update our internal state
      $this->State = self::STATE_CONNECTING;
      $this->Buffer = '';
      $this->rBuffer = '';
      $this->Command = null;
      $this->CommandQueue = array ();
      $this->Stream = $Source;
      
      if ($Callback)
        $this->StreamCallback = array ($Callback, $Source, $Private);
      else
        $this->StreamCallback = null;
    }
    // }}}
    
    // {{{ deinitConsumer
    /**
     * Callback: A source was removed from this consumer
     * 
     * @param qcEvents_Interface_Source $Source
     * @param callable $Callback (optional) Callback to raise once the pipe is ready
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of 
     * 
     *   function (qcEvents_Interface_Source $Source, qcEvents_Interface_Consumer $Destination, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void  
     **/
    public function deinitConsumer (qcEvents_Interface_Source $Source, callable $Callback = null, $Private = null) {
      if ($this->StreamCallback) {
        $this->___raiseCallback ($this->StreamCallback [0], $this->StreamCallback [1], false, $this->StreamCallback [2]);
        $this->StreamCallback = null;
      }
      
      $this->___raiseCallback ($Callback, $Source, $this, true, $Private);
    }
    // }}}
    
    
    protected function ftpConnected () { }
    protected function ftpDisconnected () { }
    protected function ftpAuthenticated ($Username, $Account = null) { }
    protected function ftpWorkingDirectory ($Path) { }
    protected function ftpProtocolError () { }
    protected function eventReadable () { }
    protected function eventClosed () { }
  }

?>