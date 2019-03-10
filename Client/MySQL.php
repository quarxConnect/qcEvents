<?PHP

  /**
   * qcEvents - MySQL Client Implementation
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
  require_once ('qcEvents/Socket.php');
  require_once ('qcEvents/Stream/MySQL.php');
  require_once ('qcEvents/Promise.php');
  
  /**
   * MySQL Client
   * ------------
   * Non-blocking and asyncronous MySQL-Client for MySQL 3.2+ / 4.1+
   * 
   * @class qcEvents_Socket_Client_MySQL
   * @extends qcEvents_Hookable
   * @package qcEvents
   * @revision 02
   * 
   * @todo Add support to change the current user
   **/
  class qcEvents_Client_MySQL extends qcEvents_Hookable {
    /* Our event-loop */
    private $eventLoop = null;
    
    /* Our active MySQL-Streams */
    private $Streams = array ();
    
    /* Pending MySQL-Stream - may be a socket or a stream! */
    private $streamPending = null;
    
    /* Maximum number of active streams */
    private $maxStreams = 5;
    
    /* The name of the host we are connected to */
    private $Hostname = null;
    
    /* The port the MySQL-Server is running on */
    private $Port = null;
    
    /* The Username we are authenticated with */
    private $Username = null;
    
    /* The Password we are authenticated with */
    private $Password = null;
    
    /* Our current default database */
    private $Database = null;
    
    /* Our desired default database */
    private $pendingDatabase = null;
    
    // {{{ __construct
    /**
     * Create a new MySQL-Client Pool
     * 
     * @param qcEvents_Base $eventLoop
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $eventLoop) {
      $this->eventLoop = $eventLoop;
    }
    // }}}
    
    // {{{ connect
    /**
     * Establish a usual TCP-Connection with a MySQL-Server
     * 
     * @param string $Hostname
     * @param int $Port
     * @param string $Username
     * @param string $Password
     * @param int $Pool (optional) Size of the connection-pool, we'll always create an initial connection
     * @param callable $Callback (optional) Callback to raise once the operation was finished
     * @param mixed $Private (optional) Private data to pass to the callback
     * 
     * The Callback will be raised in the form of
     * 
     *   function (qcEvents_Client_MySQL $Client, String $Hostname, Int $Port, String $Username, Bool $Status, Mixed $Private) { }
     * 
     * @access public
     * @return bool
     **/
    public function connect ($Hostname, $Port, $Username, $Password, $Pool = 5, callable $Callback = null, $Private = null) {
      // Check if there are already openend streams
      if (count ($this->Streams) > 0)
        return $this->close ()->finally (
          function () use ($Hostname, $Port, $Username, $Password, $Pool, $Callback, $Private) {
            $this->connect ($Hostname, $Port, $Username, $Password, $Pool, $Callback, $Private);
          }
        );
      
      // Create a socket for the stream
      $Socket = new qcEvents_Socket ($this->eventLoop);
      
      // Set the pool-size
      if ($Pool !== null)
        $this->maxStreams = max (1, $Pool);
      
      // Raise an initial callback
      $this->___callback ('mysqlConnecting', $Hostname, $Port, $Username);
      
      // Try to connect
      return $Socket->connect ($Hostname, $Port, qcEvents_Socket::TYPE_TCP)->then (
        function () use ($Socket, $Hostname, $Port, $Username, $Password, $Pool, $Callback, $Private) {
          // Create a new MySQL-Stream for this
          $Stream = new qcEvents_Stream_MySQL;
          
          // Connect both streams
          $Socket->pipeStream ($Stream);
          
          // Try to authenticate
          return $Stream->authenticate ($Username, $Password, $this->Database, function (qcEvents_Stream_MySQL $Stream, $Username, $Status) use ($Hostname, $Port, $Password, $Callback, $Private) {
            // Check if the authentication succeeded and setup the pool
            if ($Status) {
              $Stream->addHook ('mysqlDisconnected', array ($this, 'mysqlStreamClosed'));
              $this->Streams = array ($Stream);
              
              $this->Hostname = $Hostname;
              $this->Port = $Port;
              $this->Username = $Username;
              $this->Password = $Password;
              
              $this->___callback ('mysqlConnected', $Hostname, $Port, $Username);
            } else {
              $this->___callback ('mysqlAuthenticationFailed', $Hostname, $Port, $Username);
              $this->___callback ('mysqlConnectFailed', $Hostname, $Port, $Username);
            }
            
            // Fire the final callback
            return $this->___raiseCallback ($Callback, $Hostname, $Port, $Username, $Status, $Private);
          });
        },
        function () use ($Hostname, $Port, $Username, $Callback, $Private) {
          // Raise all callbacks
          $this->___callback ('mysqlConnectFailed', $Hostname, $Port, $Username);
          $this->___raiseCallback ($Callback, $Hostname, $Port, $Username, false, $Private);
          
          // Forward the error
          return new qcEvents_Promise_Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ getDatabase
    /**
     * Retrive the name of the default database
     * 
     * @access public
     * @return string
     **/
    public function getDatabase () {
      return $this->Database;
    }
    // }}}
    
    // {{{ setDatabase
    /**
     * Change the default database of this MySQL-Client
     * 
     * @param string $Database
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Client_MySQL $Self, string $Database, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function setDatabase ($Database, callable $Callback = null, $Private = null) {
      // Check if we are changing anything
      if ($this->Database == $Database)
        return $this->___raiseCallback ($Callback, $Database, true, $Private);
      
      // Set the database as pending
      $this->pendingDatabase = $Database;
      
      // Enqueue the change on all streams
      foreach ($this->Streams as $Stream)
        $Stream->setDatabase ($Database, function (qcEvents_Stream_MySQL $Stream, $Database, $Status) use ($Callback, $Private) {
          // Check if the call failed
          if (!$Status) {
            // Check if this is a duplicate
            if ($this->Database == $this->pendingDatabase)
              return;
            
            // Reset the pending database
            $this->pendingDatabase = $this->Database;
            
            // Raise the callback
            return $this->___raiseCallback ($Callback, $Database, false, $Private);
          }
          
          // Check if all streams are using the new database
          foreach ($this->Streams as $Stream)
            if ($Stream->getDatabase () !== $Database)
              return;
          
          // Set the new database on this pool
          $this->Database = $Database;
          
          // Fire the callback once all streams have the same database
          $this->___callback ('mysqlDatabaseChanged', $Database);  
          $this->___raiseCallback ($Callback, $Database, true, $Private);
        });
      
      // Check if we have connected streams and stop
      if (count ($this->Streams) > 0)
        return true;
      
      // Set the new database on this pool
      $this->Database = $Database;
      
      $this->___callback ('mysqlDatabaseChanged', $Database);
      $this->___raiseCallback ($Callback, $Database, true, $Private);
      
      return true;
    }
    // }}}
    
    // {{{ exec
    /**
     * Execute a MySQL-Statement and only care about if it was successfull
     * 
     * @param string $Query
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Client_MySQL $Self, string $Query, bool $Status, int $affectedRows, int $lastInsertID, int $Flags, int $Warnings, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function exec ($Query, callable $Callback = null, $Private = null) {
      // Try to acquire a MySQL-Stream
      if (!is_object ($Stream = $this->acquireStream ())) {
         $this->___raiseCallback ($Callback, $Query, false, null, null, null, null, $Private);
         
        return false;
      }
      
      // Dispatch the query to the stream
      return $Stream->exec ($Query,
        // Remap the callback-parameters to ourself
        function (qcEvents_Stream_MySQL $Stream, $Query, $Status, $affectedRows, $lastInsertID, $Flags, $Warnings) use ($Callback, $Private) {
          $this->___raiseCallback ($Callback, $Query, $Status, $affectedRows, $lastInsertID, $Flags, $Warnings, $Private);
        }
      );
    }
    // }}}
    
    // {{{ query
    /**
     * Execute a MySQL-Query and retrive the result at once
     * 
     * @param string $Query
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Client_MySQL $Self, string $Query, bool $Status, array $Fields, array $Rows, int $Flags, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function query ($Query, callable $Callback, $Private = null) {
      // Try to acquire a MySQL-Stream
      if (!is_object ($Stream = $this->acquireStream ())) {
         $this->___raiseCallback ($Callback, $Query, false, null, null, null, $Private);
         
        return false;
      }
       
      // Dispatch the query to the stream
      return $Stream->query ($Query,
        // Remap the callback-parameters to ourself
        function (qcEvents_Stream_MySQL $Stream, $Query, $Status, $Fields, $Rows, $Flags) use ($Callback, $Private) {
          $this->___raiseCallback ($Callback, $Query, $Status, $Fields, $Rows, $Flags, $Private);
        }
      );
    }
    // }}}
    
    // {{{ queryAsync
    /**
     * Execute a MySQL-Query and retrive the result one-by-one
     * 
     * @param string $Query
     * @param callable $Callback
     * @param mixed $Private (optional)
     * @param callable $FinalCallback (optional)
     * @param mxied $FinalPrivate (optional)
     * 
     * The per-row callback will be raised in the form of
     * 
     *   function (qcEvents_Client_MySQL $Self, array $Fields, array $Row, mixed $Private = null) { }
     * 
     * The final callback will be raised in the form of
     * 
     *   function (qcEvents_Client_MySQL $Self, stirng $Query, bool $Status, int $Flags, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function queryAsync ($Query, callable $Callback, $Private = null, callable $FinalCallback = null, $FinalPrivate = null) {
      // Try to acquire a MySQL-Stream
      if (!is_object ($Stream = $this->acquireStream ())) {
        $this->___raiseCallback ($FinalCallback, $Query, false, null, $FinalPrivate);
        
        return false;
      }
      
      // Dispatch the query to the stream
      return $Stream->queryAsync ($Query,
        
        // Remap the callback-parameters to ourself
        function (qcEvents_Stream_MySQL $Stream, $Fields, $Row) use ($Callback, $Private) {
          $this->___raiseCallback ($Callback, $Fields, $Row, $Private);
        }, null,
        
        function (qcEvents_Stream_MySQL $Stream, $Query, $Status, $Flags) use ($FinalCallback, $FinalPrivate) {
          $this->___raiseCallback ($FinalCallback, $Query, $Status, $Flags, $FinalPrivate);
        }
      );
    }
    // }}}
    
    // {{{ listFields
    /**
     * Request the fields of a given table
     * 
     * @param string $Table
     * @param string $Wildcard (optional)
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Client_MySQL $Self, string $Table, string $Wildcard, array $Fields, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function listFields ($Table, $Wildcard = null, callable $Callback, $Private = null) {
      // Try to acquire a MySQL-Stream
      if (!is_object ($Stream = $this->acquireStream ())) {
        $this->___raiseCallback ($Callback, $Table, $Wildcard, null, false, $Private);
        
        return false;
      }
       
      // Dispatch the query to the stream
      return $Stream->listFields ($Table, $Wildcard, function (qcEvents_Stream_MySQL $Self, $Table, $Wildcard, $Fields, $Status) use ($Callback, $Private) {
        $this->___raiseCallback ($Callback, $Table, $Wildcard, $Fields, $Status, $Private);
      });
    }
    // }}}
    
    // {{{ ping
    /**
     * Ping the MySQL-Server
     * 
     * @param callable $Callback
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Client_MySQL $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function ping (callable $Callback, $Private = null) {
      // Try to acquire a MySQL-Stream
      if (!is_object ($Stream = $this->acquireStream ())) {
        $this->___raiseCallback ($Callback, false, $Private);
        
        return false;
      }
       
      // Dispatch the query to the stream
      return $Stream->ping (function (qcEvents_Stream_MySQL $Self, $Status) use ($Callback, $Private) {
        $this->___raiseCallback ($Callback, $Status, $Private);
      });
    }
    // }}}
    
    // {{{ refresh
    /**
     * Perform a refresh on the MySQL-Server
     * 
     * @param enum $What
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Client_MySQL $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function refresh ($What, callable $Callback = null, $Private = null) {
      // Try to acquire a MySQL-Stream
      if (!is_object ($Stream = $this->acquireStream ())) {
        $this->___raiseCallback ($Callback, $What, false, $Private);
        
        return false;
      }
       
      // Dispatch the query to the stream
      return $Stream->refresh ($What, function (qcEvents_Stream_MySQL $Self, $Status) use ($Callback, $Private) {
        $this->___raiseCallback ($Callback, $Status, $Private);
      });
    }
    // }}}
    
    // {{{ kill
    /**
     * Ask the server to kill a given connection
     * 
     * @param int $Connection
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Client_MySQL $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function kill ($Connection, callable $Callback = null, $Private = null) {
      // Try to acquire a MySQL-Stream
      if (!is_object ($Stream = $this->acquireStream ())) {
        $this->___raiseCallback ($Callback, $Connection, false, $Private);
        
        return false;
      }
       
      // Dispatch the query to the stream
      return $Stream->kill ($Connection, function (qcEvents_Stream_MySQL $Self, $Status) use ($Callback, $Private) {
        $this->___raiseCallback ($Callback, $Status, $Private);
      });
    }
    // }}}
    
    // {{{ shutdown
    /**
     * Ask the MySQL-Server to shutdown itself
     * 
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Client_MySQL $Self, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return bool
     **/
    public function shutdown (callable $Callback = null, $Private = null) {
      // Try to acquire a MySQL-Stream
      if (!is_object ($Stream = $this->acquireStream ())) {
        $this->___raiseCallback ($Callback, false, $Private);
        
        return false;
      }
       
      // Dispatch the query to the stream
      return $Stream->shutdown (function (qcEvents_Stream_MySQL $Self, $Status) use ($Callback, $Private) {
        $this->___raiseCallback ($Callback, $Status, $Private);
      });
    }
    // }}}
    
    // {{{ close
    /**
     * Close all connections to the MySQL-Server
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function close () : qcEvents_Promise {
      $closeFunc = function ($Stream) {
        // Unregister the stream
        if ($this->streamPending == $Stream)
          $this->streamPending = null;
        
        elseif (($key = array_search ($Stream, $this->Streams, true)) !== false)
          unset ($this->Streams [$key]);
        
        // Check if there are remaing streams
        if ((count ($this->Streams) > 0) || $this->streamPending)
          return;
        
        // Fire callbacks
        $this->___callback ('mysqlDisconnected');
        $this->___raiseCallback ($Callback, true, $Private);
      };
      
      // Check if there is nothing to close
      if ((count ($this->Streams) == 0) && !$this->streamPending) {
        $this->___callback ('mysqlDisconnected');
        
        return qcEvents_Promise::resolve ();
      }
      
      // Request close on all streams
      $Promises = array ();
      
      foreach ($this->Streams as $Stream)
        $Promises [] = $Stream->close ();
      
      $this->Streams = array ();
      
      if ($this->streamPending) {
        $Promises [] = $this->streamPending->close ();
        $this->streamPending = null;
      }
      
      return qcEvents_Promise::all ($Promises)->then (function () { });
    }
    // }}}
    
    
    // {{{ acquireStream
    /**
     * Try to find a free stream to use for a instruction
     * 
     * @access private
     * @return qcEvents_Stream_MySQL
     **/
    private function acquireStream () {
      // Try to reuse an existing (free) stream
      $dbStream = null;
      
      foreach ($this->Streams as $Stream)
        if (($this->pendingDatabase === null) || ($Stream->getDatabase () == $this->pendingDatabase)) {
          if (!$Stream->isBusy ())
            return $Stream;
          
          $dbStream = $Stream;
        }
      
      // Check wheter to schedule creation of a new stream
      if ((count ($this->Streams) < $this->maxStreams) && !$this->streamPending) {
        $this->streamPending = new qcEvents_Socket ($this->eventLoop);
        $Stream = new qcEvents_Stream_MySQL;
        
        // Try to connect
        $this->streamPending->connect ($this->Hostname, $this->Port, qcEvents_Socket::TYPE_TCP)->then (
          function () use ($Stream) {
            // Create a new MySQL-Stream for this
            $Socket = $this->streamPending;
            $this->streamPending = $Stream;
            
            // Connect both streams
            return $Socket->pipeStream ($Stream, true, function (qcEvents_Interface_Stream $Socket, $Status) use ($Stream) {
              // Check if the connection wasn't successfull
              if (!$Status)
                return ($this->streamPending = null);
              
              // Try to authenticate
              $this->streamPending->authenticate ($this->Username, $this->Password, $this->Database, function (qcEvents_Stream_MySQL $Stream, $Username, $Status) {
                // Check if the authentication succeeded and setup the pool
                if ($Status) {
                  $Stream->addHook ('mysqlDisconnected', array ($this, 'mysqlStreamClosed'));
                  $this->Streams [] = $Stream;
                }
                  
                $this->streamPending = null;
              });
            });
          },
          function () {
            $this->streamPending = null;
            
            throw new qcEvents_Promise_Solution (func_get_args ());
          }
        );
      }
      
      // Just return the last stream
      # TODO: Find better strategies for this
      return $dbStream;
    }
    // }}}
    
    // {{{ mysqlStreamClosed
    /**
     * Internal Callback: One of our streams was just closed
     * 
     * @param qcEvents_Stream_MySQL $Stream
     * 
     * @access public
     * @return void
     **/
    public final function mysqlStreamClosed (qcEvents_Stream_MySQL $Stream) {
      // Check if the stream is one of ours
      if (($key = array_search ($Stream, $this->Streams, true)) === false)
        return;
      
      // Remove from stream
      unset ($this->Streams [$key]);
      
      // Check if we are now disconnected
      if (count ($this->Streams) > 0)
        return;
      
      // Fire the callback
      $this->___callback ('mysqlDisconnected');
    }
    // }}}
    
    
    // {{{ mysqlConnecting
    /**
     * Callback: An attemp to establish connection to MySQL-Server is made
     * 
     * @param string $Hostname
     * @param int $Port
     * @param string $Username
     * 
     * @access protected
     * @return void
     **/
    protected function mysqlConnecting ($Hostname, $Port, $Username) { }
    // }}}
    
    // {{{ mysqlConnected
    /**
     * Callback: Connection to MySQL-Server was established and authenticated
     * 
     * @param string $Hostname
     * @param int $Port
     * @param string $Username
     * 
     * @access protected
     * @return void
     **/
    protected function mysqlConnected ($Hostname, $Port, $Username) { }
    // }}}
    
    // {{{ mysqlAuthenticationFailed
    /**
     * Callback: Authentication on server failed
     * 
     * @param string $Hostname
     * @param int $Port
     * @param string $Username
     * 
     * @access protected
     * @return void
     **/
    protected function mysqlAuthenticationFailed ($Hostname, $Port, $Username) { }
    // }}}
    
    // {{{ mysqlConnectFailed
    /**
     * Callback: Connection to MySQL-Server could not be established
     * 
     * @param string $Hostname
     * @param int $Port
     * @param string $Username
     * 
     * @access protected
     * @return void
     **/
    protected function mysqlConnectFailed ($Hostname, $Port, $Username) { }
    // }}}
    
    // {{{ mysqlDatabaseChanged
    /**
     * Callback: The default database was changed
     * 
     * @param string $Database
     * 
     * @access protected
     * @return void
     **/
    protected function mysqlDatabaseChanged ($Database) { }
    // }}}
    
    // {{{ mysqlDisconnected
    /** 
     * Callback: Connection to MySQL-Server was closed
     * 
     * @access protected
     * @return void
     **/
    protected function mysqlDisconnected () { }
    // }}}
  }

?>