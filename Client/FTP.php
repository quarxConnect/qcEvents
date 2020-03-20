<?PHP

  /**
   * qcEvents - FTP-Client
   * Copyright (C) 2019-2020 Bernd Holzmueller <bernd@quarxconnect.de>
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
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function connect ($Hostname, $Username, $Password, $Account = null, $Port = null) : qcEvents_Promise {
      // Internally store the data
      $this->Hostname = $Hostname;
      $this->Port = ($Port !== null ? intval ($Port) : 21);
      $this->Username = $Username;
      $this->Password = $Password;
      $this->Account = $Account;
      $this->Ready = false;
      
      // Try to acquire a new stream
      return $this->requestStream ()->then (
        function (qcEvents_Stream_FTP_Client $ftpStream) use ($Hostname, $Username, $Account) {
          // Release the stream again
          $this->releaseStream ($ftpStream);
          
          // Fire the final callbacks
          $this->___callback ('ftpConnected', $Hostname, $Username, $Account);
        },
        function () {
          $this->Hostname = $this->Username = $this->Password = '';
          $this->Account = null;
          
          throw new qcEvents_Promise_Solution (func_get_args ());
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
      // Acquire an FTP-Stream
      return $this->requestStream ()->then (
        function (qcEvents_Stream_FTP_Client $ftpStream) use ($Path) {
          // Forward the request
          return $ftpStream->getFilenames ($Path)->finally (
            function () use ($ftpStream) {
              $this->releaseStream ($ftpStream);
            }
          );
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
      return $this->requestStream ()->then (
        function (qcEvents_Stream_FTP_Client $ftpStream = null) use ($Filename) {
          return $ftpStream->retriveFileStream ($Filename)->finally (
            function () use ($ftpStream) {
              $this->releaseStream ($ftpStream);
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ downloadFileBuffered
    /**
     * Download a File from FTP-Server to local buffer
     * 
     * @param string $remotePath Path of file to retrive
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function downloadFileBuffered ($remotePath) : qcEvents_Promise {
      return $this->requestStream ()->then (
        function (qcEvents_Stream_FTP_Client $ftpStream) use ($remotePath) {
          return $Stream->downloadFileBuffered ($remotePath)->finally (
            function () use ($ftpStream) {
              $this->releaseStream ($ftpStream);
            }
          );
        }
      );
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
     * @access private
     * @return qcEvents_Promise
     **/
    private function requestStream () : qcEvents_Promise {
      // Check if we are ready
      if ((!$this->Ready && (count ($this->pendingStreams) != 0)) ||
          (count ($this->pendingStreams) + count ($this->availableStreams) + count ($this->blockedStreams) >= $this->maxStreams))
        return qcEvents_Promise::reject ('No stream available');
      
      // Check if there is already one stream available
      if (count ($this->availableStreams) > 0) {
        $this->blockedStreams [] = $Stream = array_shift ($this->availableStreams);
        
        return qcEvents_Promise::resolve ($Stream);
      }
      
      // Try to acquire a new stream
      $this->pendingStreams [] = $Stream = new qcEvents_Stream_FTP_Client;
      $Key = array_search ($Stream, $this->pendingStreams, true);
      
      // Create and connect socket
      $Socket = new qcEvents_Socket ($this->eventBase);
      
      return $Socket->connect ($this->Hostname, $this->Port, $Socket::TYPE_TCP)->then (
        function () use ($Socket, $Key, $Stream) {
          // Connect Stream with socket
          return $Socket->pipeStream ($Stream, true);
        }
      )->then (
        // FTP-Connection was established
        function () use ($Stream, $Key) {
          // Try to authenticate
          return $Stream->authenticate ($this->Username, $this->Password, $this->Account);
        }
      )->then (
        // FTP-Connection was authenticated
        function () use ($Stream, $Key, $Socket) {
          // Clearify that we are ready
          $this->Ready = true;
          
          // Move from pending to blocked
          unset ($this->pendingStreams [$Key]);
          $this->blockedStreams [] = $Stream;
          
          // Install event-hooks
          $Stream->addHook (
            'eventClosed',
            function (qcEvents_Stream_FTP_Client $Stream) {
              if (($Key = array_search ($Stream, $this->availableStreams, true)) !== false)
                unset ($this->availableStreams [$Key]);
              elseif (($Key = array_search ($Stream, $this->blockedStreams, true)) !== false)
                unset ($this->blockedStreams [$Key]);
              elseif (($Key = array_search ($Stream, $this->pendingStreams, true)) !== false)
                unset ($this->pendingStreams [$Key]);
            }
          );
          
          // Forward the stream
          return $Stream;
        },
        function () use ($Socket, $Key) {
          $Socket->close ();
          
          unset ($this->pendingStreams [$Key]);
          
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