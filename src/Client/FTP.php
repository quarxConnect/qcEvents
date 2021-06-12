<?php

  /**
   * qcEvents - FTP-Client
   * Copyright (C) 2019-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  namespace quarxConnect\Events\Client;
  use \quarxConnect\Events;
  use \quarxConnect\Events\ABI;
  use \quarxConnect\Events\Stream;
  
  class FTP extends Events\Hookable {
    use Events\Feature\Based;
    
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
    private $pendingStreams = [ ];
    private $availableStreams = [ ];
    private $blockedStreams = [ ];
    
    // {{{ __construct
    /**
     * Create a new FTP-Client
     * 
     * @param Events\Base $eventBase
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Events\Base $eventBase) {
      // Assign our event-base
      $this->setEventBase ($eventBase);
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
     * @return Events\Promise
     **/
    public function connect (string $Hostname, string $Username, string $Password, string $Account = null, int $Port = null) : Events\Promise {
      // Internally store the data
      $this->Hostname = $Hostname;
      $this->Port = $Port ?? 21;
      $this->Username = $Username;
      $this->Password = $Password;
      $this->Account = $Account;
      $this->Ready = false;
      
      // Try to acquire a new stream
      return $this->requestStream ()->then (
        function (Stream\FTP\Client $ftpStream) use ($Hostname, $Username, $Account) {
          // Release the stream again
          $this->releaseStream ($ftpStream);
          
          // Fire the final callbacks
          $this->___callback ('ftpConnected', $Hostname, $Username, $Account);
        },
        function () {
          $this->Hostname = $this->Username = $this->Password = '';
          $this->Account = null;
          
          throw new Events\Promise\Solution (func_get_args ());
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
      // Acquire an FTP-Stream
      return $this->requestStream ()->then (
        function (Stream\FTP\Client $ftpStream) use ($onDirectory) {
          // Forward the request
          return $ftpStream->getFilenames ($onDirectory)->finally (
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
     * @param string $fileName Path of the file to download
     * 
     * @access public
     * @return Events\Promise
     **/
    public function retriveFileStream ($fileName) : Events\Promise {
      return $this->requestStream ()->then (
        function (Stream\FTP\Client $ftpStream) use ($fileName) {
          return $ftpStream->retriveFileStream ($fileName)->finally (
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
     * @return Events\Promise
     **/
    public function downloadFileBuffered (string $remotePath) : Events\Promise {
      return $this->requestStream ()->then (
        function (Stream\FTP\Client $ftpStream) use ($remotePath) {
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
     * @return Events\Promise
     **/
    public function close () : Events\Promise {
      // Collect all active streams
      $Streams = array_merge ($this->pendingStreams, $this->availableStreams, $this->blockedStreams);
      
      // Reset all streams
      $this->pendingStreams = $this->availableStreams = $this->blockedStreams = [ ];
      $this->Ready = false;
      
      // Enqueue close
      $Promises = [ ];
      
      foreach ($Streams as $Stream)
        $Promises [] = $Stream->close ()->catch (function () { });
      
      return Events\Promise::all ($Promises)->then (function () { });
    }
    // }}}
    
    // {{{ requestStream
    /**
     * Acquire a free ftp-stream
     * 
     * @access private
     * @return Events\Promise
     **/
    private function requestStream () : Events\Promise {
      // Check if we are ready
      if ((!$this->Ready && (count ($this->pendingStreams) != 0)) ||
          (count ($this->pendingStreams) + count ($this->availableStreams) + count ($this->blockedStreams) >= $this->maxStreams))
        return Events\Promise::reject ('No stream available');
      
      // Check if there is already one stream available
      if (count ($this->availableStreams) > 0) {
        $this->blockedStreams [] = $Stream = array_shift ($this->availableStreams);
        
        return Events\Promise::resolve ($Stream);
      }
      
      // Try to acquire a new stream
      $this->pendingStreams [] = $Stream = new Stream\FTP\Client ();
      $Key = array_search ($Stream, $this->pendingStreams, true);
      
      // Create and connect socket
      $ftpSocket = new Events\Socket ($this->getEventBase ());
      
      return $ftpSocket->connect ($this->Hostname, $this->Port, $ftpSocket::TYPE_TCP)->then (
        function () use ($ftpSocket, $Key, $Stream) {
          // Connect Stream with socket
          return $ftpSocket->pipeStream ($Stream, true);
        }
      )->then (
        // FTP-Connection was established
        function () use ($Stream, $Key) {
          // Try to authenticate
          return $Stream->authenticate ($this->Username, $this->Password, $this->Account);
        }
      )->then (
        // FTP-Connection was authenticated
        function () use ($Stream, $Key) {
          // Clearify that we are ready
          $this->Ready = true;
          
          // Move from pending to blocked
          unset ($this->pendingStreams [$Key]);
          $this->blockedStreams [] = $Stream;
          
          // Install event-hooks
          $Stream->addHook (
            'eventClosed',
            function (Stream\FTP\Client $ftpStream) {
              if (($Key = array_search ($ftpStream, $this->availableStreams, true)) !== false)
                unset ($this->availableStreams [$Key]);
              elseif (($Key = array_search ($ftpStream, $this->blockedStreams, true)) !== false)
                unset ($this->blockedStreams [$Key]);
              elseif (($Key = array_search ($ftpStream, $this->pendingStreams, true)) !== false)
                unset ($this->pendingStreams [$Key]);
            }
          );
          
          // Forward the stream
          return $Stream;
        },
        function () use ($ftpSocket, $Key) {
          $ftpSocket->close ();
          
          unset ($this->pendingStreams [$Key]);
          
          throw new Events\Promise\Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ releaseStream
    /**
     * Release a stream after it was acquired
     * 
     * @param Stream\FTP\Client $ftpStream
     * 
     * @access private
     * @return void
     **/
    private function releaseStream (Stream\FTP\Client $ftpStream) : void {
      // Try to find the stream
      if (($streamIndex = array_search ($ftpStream, $this->blockedStreams, true)) === false)
        return;
      
      // Move to available
      unset ($this->blockedStreams [$streamIndex]);
      $this->availableStreams [] = $ftpStream;
    }
    // }}}
    
    
    protected function ftpConnected (string $ftpHostname, string $userName, string $userAccount = null) : void { }
  }
