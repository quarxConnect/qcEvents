<?PHP

  /**
   * qcEvents - FTP-Client
   * Copyright (C) 2019 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Hookable.php');
  require_once ('qcEvents/Socket.php');
  require_once ('qcEvents/Stream/FTP/Client.php');
  require_once ('qcEvents/Promise.php');
  
  class qcEvents_Client_FTP extends qcEvents_Hookable {
    /* The eventbase we are assigned to */
    private $eventBase = null;
    
    /* Number of parallel FTP-Streams to use */
    private $maxStreams = 5;
    
    /* FTP-Connection-Information */
    private $Hostname = '';
    private $Port = 21;
    private $Username = '';
    private $Password = '';
    private $Account = null;
    private $Ready = false;
    
    /* Cached Streams */
    private $pendingStreams = array ();
    private $availableStreams = array ();
    private $blockedStreams = array ();
    
    // {{{ __construct
    /**
     * Create a new FTP-Client
     * 
     * @param qcEvents_Base $eventBase
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $eventBase) {
      // Assign our event-base
      $this->eventBase = $eventBase;
    }
    // }}}
    
    // {{{ connect
    /**
     * Connect to foreign FTP-Server
     * 
     * @param string $Hostname Hostname of FTP-Server
     * @param string $Username Username to authenticate with
     * @param string $Password Password to authenticate with
     * @param string $Account (optional) Account to authenticate with
     * @param int $Port (optional) FTP-Port to use
     * @param callable $Callback (optional) A callback to raise once the first connection is available
     * @param mixed $Private (optional) Any private data to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Client_FTP $Self, string $Hostname, string $Username, string $Account = null, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void
     **/
    public function connect ($Hostname, $Username, $Password, $Account = null, $Port = null, callable $Callback = null, $Private = null) {
      // Internally store the data
      $this->Hostname = $Hostname;
      $this->Port = ($Port !== null ? intval ($Port) : 21);
      $this->Username = $Username;
      $this->Password = $Password;
      $this->Account = $Account;
      $this->Ready = false;
      
      // Try to acquire a new stream
      return $this->requestStream (function (qcEvents_Stream_FTP_Client $Stream = null) use ($Hostname, $Username, $Account, $Callback, $Private) {
        // Check if the stream could be acquired or reset local information
        if (!$Stream) {
          $this->Hostname = $this->Username = $this->Password = '';
          $this->Account = null;
        } else
          $this->releaseStream ($Stream);
        
        // Fire the final callbacks
        $this->___raiseCallback ($Callback, $Hostname, $Username, $Account, !!$Stream, $Private);
        
        if ($Stream)
          $this->___callback ('ftpConnected', $Hostname, $Username, $Account);
      });
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
     *   function (qcEvents_Client_FTP $Self, array $Files = null, mixed $Private = null) { }
     * 
     * If there was an error during the execution, $Files will be NULL.
     * 
     * @access public
     * @return void
     **/
    public function getFilenames ($Path = null, callable $Callback, $Private = null) {
      // Acquire an FTP-Stream
      return $this->requestStream (function (qcEvents_Stream_FTP_Client $Stream = null) use ($Path, $Callback, $Private) {
        // Check if a stream could be acquired
        if (!$Stream)
          return $this->___raiseCallback ($Callback, null, $Private);
        
        // Forward the request
        return $Stream->getFilenames ($Path, function (qcEvents_Stream_FTP_Client $Stream, array $Files = null) use ($Callback, $Private) {
          $this->___raiseCallback ($Callback, $Files, $Private);
        });
      });
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
     *   function (qcEvents_Client_FTP $Self, string $Filename, qcEvents_Interface_Stream $Stream = null, mixed $Private = null) { }
     * 
     * The final callback will be raised in the form of
     * 
     *   function (qcEvents_Client_FTP $Self, string $Filename, bool $Status, qcEvents_Interface_Stream $Stream = null, mixed $Private = null) { }
     * 
     * @access public
     * @return void  
     **/
    public function retriveFileStream ($Filename, callable $Callback, $Private = null, callable $finalCallback = null, $finalPrivate = null) {
      // Acquire an FTP-Stream
      return $this->requestStream (function (qcEvents_Stream_FTP_Client $Stream = null) use ($Filename, $Callback, $Private, $finalCallback, $finalPrivate) {
        // Check if a stream could be acquired
        if (!$Stream) {
          $this->___raiseCallback ($Callback, $Filename, null, $Private);
          $this->___raiseCallback ($finalCallback, $Filename, false, null, $finalPrivate);
          
          return;
        }
        
        // Request the stream
        $Stream->retriveFileStream (
          $Filename,
          
          // Rewrite both callbacks
          function (qcEvents_Stream_FTP_Client $streamFTP, $Filename, qcEvents_Interface_Stream $Stream = null) use ($Callback, $Private) {
            $this->___raiseCallback ($Callback, $Filename, $Stream, $Private);
          }, null,
          function (qcEvents_Stream_FTP_Client $streamFTP, $Filename, $Status) use ($finalCallback, $finalPrivate) {
            $this->___raiseCallback ($finalCallback, $Filename, $Status, $finalPrivate);
          }
        );
      });
    }
    // }}}
    
    // {{{ downloadFileBuffered
    /**
     * Download a File from FTP-Server to local buffer
     * 
     * @param string $remotePath Path of file to retrive
     * @param callable $Callback A callback to raise once the operation is complete
     * @param mixed $Private (optional) Private data to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Client_FTP $Self, string $remotePath, string $Content, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return void
     **/
    public function downloadFileBuffered ($remotePath, callable $Callback, $Private = null) {
      return $this->requestStream (function (qcEvents_Stream_FTP_Client $Stream = null) use ($remotePath, $Callback, $Private) {
        // Check if a stream could be acquired
        if (!$Stream)
          return $this->___raiseCallback ($Callback, $remotePath, null, false, $Private);
        
        return $Stream->downloadFileBuffered ($remotePath, function (qcEvents_Stream_FTP_Client $Stream, $remotePath, $Content, $Status) use ($Callback, $Private) {
          // Release the stream
          $this->releaseStream ($Stream);
          
          // Raise the callback
          $this->___raiseCallback ($Callback, $remotePath, $Content, $Status, $Private);
        });
      });
    }
    // }}}
    
    // {{{ close
    /**
     * Close all connections from this FTP-Client
     * 
     * @access public
     * @return void
     **/
    public function close () : qcEvents_Promise {
      // Collect all active streams
      $Streams = array_merge ($this->pendingStreams, $this->availableStreams, $this->blockedStreams);
      
      // Reset all streams
      $this->pendingStreams = $this->availableStreams = $this->blockedStreams = array ();
      $this->Ready = false;
      
      // Enqueue close
      $Promises = array ();
      
      foreach ($Streams as $Stream)
        $Promises [] = $Stream->close ();
      
      return qcEvents_Promise::all ($Promises)->then (function () { });
    }
    // }}}
    
    // {{{ requestStream
    /**
     * Acquire a free ftp-stream
     * 
     * @param callable $Callback
     * 
     * @access private
     * @return void
     **/
    private function requestStream (callable $Callback) {
      // Check if we are ready
      if ((!$this->Ready && (count ($this->pendingStreams) != 0)) ||
          (count ($this->pendingStreams) + count ($this->availableStreams) + count ($this->blockedStreams) >= $this->maxStreams))
        return call_user_func ($Callback, null);
      
      // Check if there is already one stream available
      if (count ($this->availableStreams) > 0) {
        $this->blockedStreams [] = $Stream = array_shift ($this->availableStreams);
        
        return call_user_func ($Callback, $Stream);
      }
      
      // Try to acquire a new stream
      $this->pendingStreams [] = $Stream = new qcEvents_Stream_FTP_Client;
      $Key = array_search ($Stream, $this->pendingStreams, true);
      
      // Create and connect socket
      $Socket = new qcEvents_Socket ($this->eventBase);
      
      return $Socket->connect ($this->Hostname, $this->Port, $Socket::TYPE_TCP)->then (
        function () use ($Socket, $Key, $Stream, $Callback) {
          // Connect Stream with socket
          return $Socket->pipeStream ($Stream, true)->then (
            function () use ($Stream, $Callback, $Key) {
              // Try to authenticate
              return $Stream->authenticate ($this->Username, $this->Password, $this->Account, function (qcEvents_Stream_FTP_Client $Stream, $Status) use ($Callback, $Key, $Socket) {
                // Check if FTP could be authenticated
                if (!$Status) {
                  $Socket->close ();
                  
                  unset ($this->pendingStreams [$Key]);
                  return call_user_func ($Callback, null);
                }
                
                // Clearify that we are ready
                $this->Ready = true;
                
                // Move from pending to blocked
                unset ($this->pendingStreams [$Key]);
                $this->blockedStreams [] = $Stream;
                
                // Install event-hooks
                $Stream->addHook ('eventClosed', function (qcEvents_Stream_FTP_Client $Stream) {
                  if (($Key = array_search ($Stream, $this->availableStreams, true)) !== false)
                    unset ($this->availableStreams [$Key]);
                  elseif (($Key = array_search ($Stream, $this->blockedStreams, true)) !== false)
                    unset ($this->blockedStreams [$Key]);
                  elseif (($Key = array_search ($Stream, $this->pendingStreams, true)) !== false)
                    unset ($this->pendingStreams [$Key]);
                });
                
                // Forward the stream
                return call_user_func ($Callback, $Stream);
              });
            }
          )->catch (
            function () use ($Socket, $Callback) {
              $Socket->close ();
              
              call_user_func ($Callback, null);
              
              throw new qcEvents_Promise_Solution (func_get_args ());
            }
          );
        },
        function () use ($Key, $Callback) {
          unset ($this->pendingStreams [$Key]);
          call_user_func ($Callback, null);
          
          throw new qcEvents_Promise_Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ releaseStream
    /**
     * Release a stream after it was acquired
     * 
     * @param qcEvents_Stream_FTP_Client $Stream
     * 
     * @access private
     * @return void
     **/
    private function releaseStream (qcEvents_Stream_FTP_Client $Stream) {
      // Try to find the stream
      if (($Key = array_search ($Stream, $this->blockedStreams, true)) === false)
        return;
      
      // Move to available
      unset ($this->blockedStreams [$Key]);
      $this->availableStreams [] = $Stream;
    }
    // }}}
    
    
    protected function ftpConnected ($Hostname, $Username, $Account) { }
  }

?>