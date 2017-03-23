<?PHP

  /**
   * qcEvents - HTTP Client Implementation
   * Copyright (C) 2014 Bernd Holzmueller <bernd@quarxconnect.de>
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
  require_once ('qcEvents/Stream/HTTP/Request.php');
  require_once ('qcEvents/File.php');
  
  class qcEvents_Client_HTTP extends qcEvents_Hookable {
    /* Our parented event-handler */
    private $eventBase = null;
    
    /* Pending HTTP-Requests */
    private $pendingRequests = array ();
    
    /* Active HTTP-Requests */
    private $activeRequests = array ();
    
    /* Maximum concurrent requests */
    private $activeRequestsMax = 5;
    
    /* Open/unused sockets */
    private $Sockets = array ();
    
    // {{{ __construct
    /**
     * Create a new HTTP-Client Pool
     * 
     * @param qcEvents_Base $eventBase
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $eventBase) {
      $this->eventBase = $eventBase;
    }
    // }}}
    
    // {{{ addNewRequest
    /**
     * Enqueue an HTTP-Request
     * 
     * @param string $URL The requested URL
     * @param enum $Method (optional) Method to use on the request
     * @param array $Headers (optional) List of additional HTTP-Headers
     * @param string $Body (optional) Additional body for the request
     * @param callback $Callback (optional) A callback to raise once the request was finished
     * @param mixed $Private (optional) Private data to pass to the callback
     * 
     * @remark See addRequest() below for callback-definition
     * 
     * @access public
     * @return qcEvents_Stream_HTTP_Request
     **/
    public function addNewRequest ($URL, $Method = null, $Headers = null, $Body = null, callable $Callback = null, $Private = null) {
      // Make sure we have a request-object
      $Request = new qcEvents_Stream_HTTP_Request ($URL);
      
      // Set additional properties of the request
      if ($Method !== null)
        $Request->setMethod ($Method);
      
      if (is_array ($Headers))
        foreach ($Headers as $Key=>$Value)
          $Request->setField ($Key, $Value);
      
      if ($Body !== null)
        $Request->setBody ($Body);
      
      return $this->addRequest ($Request, $Callback, $Private);
    }
    // }}}
    
    // {{{ addRequest
    /**
     * Enqueue a prepared HTTP-Request
     * 
     * @param qcEvents_Stream_HTTP_Request $Request The HTTP-Request to enqueue
     * @param callback $Callback (optional) A callback to raise once the request was finished
     * @param mixed $Private (optional) Private data to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_HTTP_Request $Request, qcEvents_Stream_HTTP_Response $Header, string $Body, mixed $Private) { }
     * 
     * @access public
     * @return qcEvents_Stream_HTTP_Request
     **/
    public function addRequest (qcEvents_Stream_HTTP_Request $Request, callable $Callback = null, $Private = null) {
      // Generate a key for this
      $Key = $Request->getHostname () . ':' . $Request->getPort () . ($Request->useTLS () ? '/s' : '');
      
      // Enqueue the request
      if (isset ($this->pendingRequests [$Key]))
        $this->pendingRequests [$Key][] = array ($Request, $Callback, $Private);
      else
        $this->pendingRequests [$Key] = array (array ($Request, $Callback, $Private));
      
      // Try to start pending requests
      $this->startPendingRequests ();
      
      return $Request;
    }
    // }}}
    
    // {{{ addDownloadRequest
    /**
     * Request a file-download and store file on disk
     * 
     * @param string $URL URL of file to download
     * @param string $Destination Local path to store file to
     * @param callable $Callback (optional) Callback to raise once the download was finished
     * @param mixed $Private (optional) A private parameter to pass to the callback
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Client_HTTP $Client, string $URL, string $Destination, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return qcEvents_Stream_HTTP_Request
     **/
    public function addDownloadRequest ($URL, $Destination, callable $Callback = null, $Private = null) {
      // Prepare Request-Headers
      $Headers = array ();
      
      if (is_file ($Destination)) {
        // Honor last modification of the file
        if (is_array ($stat = stat ($Destination)))
          $Headers ['If-Modified-Since'] = date ('r', $stat ['mtime']);
        
        // Honor stored etag
        if (is_file ($Destination . '.etag'))
          $Headers ['If-None-Match'] = file_get_contents ($Destination . '.etag');
      }
      
      // Create the request
      $Request = $this->addNewRequest ($URL, null, $Headers);
      $File = null;
      
      // Setup hooks
      $Request->addHook ('httpHeaderReady', function (qcEvents_Stream_HTTP_Request $Request, qcEvents_Stream_HTTP_Header $Header) use ($Destination, &$File) {
        // Check if we have to do something
        if ($Header->isError () || !$Header->hasBody ())
          return;
        
        // Create a new file-stream
        $File = new qcEvents_File ($this->eventBase, $Destination, false, true, true);
        
        if (is_file ($Destination . '.etag'))
          unlink ($Destination . '.etag');   
        
        // Set modification-time
        if ((($mod = $Header->getField ('Last-Modified')) !== null) &&
            (($ts = strtotime ($mod)) !== false))
          $File->setModificationTime ($ts);
        
        // Redirect the output of the stream
        $Request->pipe ($File);
      });
      
      $Request->addHook ('httpRequestResult', function (qcEvents_Stream_HTTP_Request $Request, qcEvents_Stream_HTTP_Header $Header = null, $Body = null) use ($Callback, $Private, $URL, $Destination, &$File) {
        // Process etag
        if ($Header && $Header->hasBody () && !$Header->isError () && (($etag = $Header->getField ('ETag')) !== null))
          # TODO: This is not async!
          file_put_contents ($Destination . '.etag', $etag);
        
        // Run the final callback
        if ($File)
          return $File->close (function () use ($Callback, $Private, $URL, $Destination, $Header) {
            $this->___raiseCallback ($Callback, $URL, $Destination, ($Header && !$Header->isError () ? ($Header->hasBody () ? true : null) : false), $Private);
          });
        
        $this->___raiseCallback ($Callback, $URL, $Destination, ($Header && !$Header->isError () ? ($Header->hasBody () ? true : null) : false), $Private);
      });
      
      return $Request;
    }
    // }}}
    
    // {{{ setMaxRequests
    /**
     * Set the number of max concurrent requests
     * 
     * @param int $Maximum
     * 
     * @access public
     * @return bool
     **/
    public function setMaxRequests ($Maximum) {
      // Make sure the new max is at least one (as we refuse to break ourself)
      if ($Maximum < 1)
        return false;
      
      $this->activeRequestsMax = (int)$Maximum;
      $this->startPendingRequests ();
      
      return true;
    }
    // }}}
    
    // {{{ startPendingRequests
    /**
     * Check if we may start a new client-connection
     * 
     * @access private
     * @return void
     **/
    private function startPendingRequests () {
      // Create new sockets while we have space for them and pending requests
      while ((count ($this->activeRequests) < $this->activeRequestsMax) && (count ($this->pendingRequests) > 0))
        // Peek the next request
        foreach ($this->pendingRequests as $Key=>$Requests) {
          $Request = array_shift ($this->pendingRequests [$Key]);
          
          if ($this->startRequest ($Request [0], $Request [1], $Request [2])) {
            if (count ($this->pendingRequests [$Key]) == 0)
              unset ($this->pendingRequests [$Key]);
            
            break;
          }
          
          array_unshift ($this->pendingRequests [$Key], $Request);
        }
    }
    // }}}
    
    // {{{ startRequest
    /**
     * Start a given request
     * 
     * @param qcEvents_Stream_HTTP_Request $Request
     * @param callable $Callback (optional)
     * @param mixed $Private (optional)
     * 
     * @access private
     * @return bool
     **/
    private function startRequest (qcEvents_Stream_HTTP_Request $Request, callable $Callback = null, $Private = null) {
      // Try to acquire a socket for this request
      if (!is_object ($Socket = $this->acquireSocket ($Request->getHostname (), $Request->getPort (), $Request->useTLS ())))
        return false;
      
      // Move the request to active requests
      $this->activeRequests [] = array ($Request, $Callback, $Private, $Socket);
      
      // Pipe the stream to this request
      $Socket->pipe ($Request);
      
      // Watch events on the request
      $Request->addHook (
        'httpRequestResult',
        function (qcEvents_Stream_HTTP_Request $Request, qcEvents_Stream_HTTP_Header $Header = null, $Body = null) {
          // Make sure the request is one of our active ones
          $Key = null;
          
          foreach ($this->activeRequests as $ID=>$Req)
            if ($Req [0] === $Request) {
              $Key = $ID;
              break;
            }
          
          if ($Key === null)
            return;
          
          // Remove from active-queue
          unset ($this->activeRequests [$Key]);
          
          // Check if we may reuse the socket
          if (!$Header ||
              (($Header->getVersion () < 1.1) && ($Header->getField ('Connection') != 'keep-alive')) ||
              ($Header->getField ('Connection') == 'close'))
            $Req [3]->close ();
          
          // Release the socket (allow to reuse it)
          $Req [3]->unpipe ($Req [0]);
          $this->releaseSocket ($Req [3]);
          
          // Check for redirects
          if ($Header &&
              ($Location = $Header->getField ('Location')) &&
              ((($Status = $Header->getStatus ()) >= 300) && ($Status < 400)) &&
              (($max = $Request->getMaxRedirects ()) > 0) &&
              is_array ($URI = parse_url ($Location))) {
            // Make sure the URL is fully qualified
            if ($rebuild = !isset ($URI ['scheme']))
              $URI ['scheme'] = ($Request->useTLS () ? 'https' : 'http');
            
            if (!isset ($URI ['host'])) {
              $URI ['host'] = $Request->getHostname ();
              $rebuild = true;
              
              if ($Request->getPort () != ($Request->useTLS () ? 443 : 80))
                $URI ['port'] = $Request->getPort ();
              else
                unset ($URI ['port']);
            }
            
            if ($rebuild)
              $Location = $URI ['scheme'] . '://' . $URI ['host'] . (isset ($URI ['port']) ? ':' . $URI ['port'] : '') . $URI ['path'] . (isset ($URI ['query']) ? '?' . $URI ['query'] : '');
            
            // Fire a callback first
            $this->___callback ('httpRequestRediect', $Request, $Location, $Header, $Body);
            
            // Set the new location as destination
            $Request->setURL ($Location);
            
            // Lower the number of max requests
            $Request->setMaxRedirects ($max - 1);
            
            // Re-Enqueue the request
            return $this->addRequest ($Request, $Req [1], $Req [2]);
          }
          
          // Fire the callbacks
          if ($Req [1])
            $this->___raiseCallback ($Req [1], $Request, $Header, $Body, $Req [2]);
          
          $this->___callback ('httpRequestResult', $Request, $Header, $Body);
          
          // Check if we can start waiting requests (as we can not trus that releaseSocket() did this for us)
          $this->startPendingRequests ();
          
          // Clean up sockets on pool
          # $this->releaseSockets ();
        }
      );
      
      return true;
    }
    // }}}
    
    // {{{ acquireSocket
    /**
     * Retrive a socket to a given host/port
     * 
     * @param string $Hostname
     * @param int $Port
     * @param bool $TLS
     * 
     * @access private
     * @return qcEvents_Socket
     **/
    private function acquireSocket ($Hostname, $Port, $TLS) {
      // Generate a key for this socket-request
      $Key = $Hostname . ':' . $Port . ($TLS ? '/s' : '');
      
      // Check if there are cached sockets
      if (!isset ($this->Sockets [$Key])) {
        $Socket = new qcEvents_Socket ($this->eventBase, $Hostname, $Port, qcEvents_Socket::TYPE_TCP, $TLS);
        $Socket->addHook ('socketDisconnected', function (qcEvents_Socket $Socket) use ($Key) {
          // Check if the socket may be on pool
          if (!isset ($this->Sockets [$Key]))
            return;
          
          // Try to find the socket
          if (($Index = array_search ($Socket, $this->Sockets [$Key], true)) === false)
            return;
          
          // Remove the socket from pool
          unset ($this->Sockets [$Key][$Index]);
          
          // Check if this pool is now empty
          if (count ($this->Sockets [$Key]) == 0)
            unset ($this->Sockets [$Key]);
        });
        
        return $Socket;
      }
      
      // Peek a socket
      $Socket = array_shift ($this->Sockets [$Key]);
      
      // Check if there are sockets left
      if (count ($this->Sockets [$Key]) == 0)
        unset ($this->Sockets [$Key]);
      
      // Return the socket
      return $Socket;
    }
    // }}}
    
    // {{{ releaseSocket
    /**
     * Return a free socket to our pool
     * 
     * @param qcEvents_Socket $Socket
     * 
     * @access private
     * @return void
     **/
    private function releaseSocket (qcEvents_Socket $Socket) {
      // Generate a key for this socket
      $Key = $Socket->getRemoteHost () . ':' . $Socket->getRemotePort () . ($Socket->tlsEnable () ? '/s' : '');
      
      // Check if the socket is still connected
      if (!$Socket->isConnected ())
        return;
      
      // Push the socket to pool
      if (isset ($this->Sockets [$Key]))
        $this->Sockets [$Key][] = $Socket;
      else
        $this->Sockets [$Key] = array ($Socket);
      
      // Check if there are pending requests for this socket
      if (isset ($this->pendingRequests [$Key]))
        while (count ($this->activeRequests) < $this->activeRequestsMax) {
          $Request = array_shift ($this->pendingRequests [$Key]);
          
          if ($this->startRequest ($Request [0], $Request [1], $Request [2])) {
            if (count ($this->pendingRequests [$Key]) == 0)
              unset ($this->pendingRequests [$Key]);
            
            break;
          }
          
          array_unshift ($this->pendingRequests [$Key], $Request);
        }
    }
    // }}}
    
    // {{{ releaseSockets
    /**
     * Release all unused sockets from our pool
     * 
     * @access private
     * @return void
     **/
    private function releaseSockets () {
      foreach ($this->Sockets as $Key=>$Sockets)
        foreach ($Sockets as $Socket)
          $Socket->close ();
      
      $this->Sockets = array ();
    }
    // }}}
    
    
    // {{{ httpRequestRediect
    /**
     * Callback: A HTTP-Request is being redirected
     * 
     * @param qcEvents_Stream_HTTP_Request $Request The original HTTP-Request-Object
     * @param string $Location The new location that is being redirected to
     * @param qcEvents_Stream_HTTP_Header $Header Repsonse-Headers
     * @param string $Body (optional) Contents Response-Body
     * 
     * @access protected
     * @return void
     **/
    protected function httpRequestRediect (qcEvents_Stream_HTTP_Request $Request, $Location, qcEvents_Stream_HTTP_Header $Header, $Body = null) { }
    // }}}
    
    // {{{ httpRequestResult
    /**
     * Callback: HTTP-Request is finished
     * 
     * @param qcEvents_Stream_HTTP_Request $Request The original HTTP-Request-Object
     * @param qcEvents_Stream_HTTP_Header $Header (optional) Contains Response-Headers, if a response was received. NULL on network-error
     * @param string $Body (optional) Contents Response-Body, if a response was received. NULL on network-error
     * 
     * @access protected
     * @return void
     **/
    protected function httpRequestResult (qcEvents_Stream_HTTP_Request $Request, qcEvents_Stream_HTTP_Header $Header = null, $Body = null) { }
    // }}}
  }

?>