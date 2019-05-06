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
  require_once ('qcEvents/Socket/Pool.php');
  require_once ('qcEvents/Stream/HTTP/Request.php');
  require_once ('qcEvents/File.php');
  
  class qcEvents_Client_HTTP extends qcEvents_Hookable {
    /* Our parented event-handler */
    private $eventBase = null;
    
    /* All queued HTTP-Requests */
    private $httpRequests = array ();
    
    /* Socket-Pool */
    private $socketPool = null;
    
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
      $this->socketPool = new qcEvents_Socket_Pool ($eventBase);
    }
    // }}}
    
    // {{{ getEventBase
    /**
     * Retrive the handle of the current event-loop-handler
     * 
     * @access public
     * @return qcEvents_Base May be NULL if none is assigned
     **/
    public function getEventBase () {
      return $this->eventBase;
    }
    // }}}
    
    // {{{ request
    /**
     * Enqueue an HTTP-Request
     * 
     * @param string $URL The requested URL
     * @param enum $Method (optional) Method to use on the request
     * @param array $Headers (optional) List of additional HTTP-Headers
     * @param string $Body (optional) Additional body for the request
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function request ($URL, $Method = null, $Headers = null, $Body = null) : qcEvents_Promise {
      return new qcEvents_Promise (function ($resolve, $reject) use ($URL, $Method, $Headers, $Body) {
        $this->addNewRequest (
          $URL,
          $Method,
          $Headers,
          $Body,
          function (qcEvents_Client_HTTP $Self, qcEvents_Stream_HTTP_Request $Request, qcEvents_Stream_HTTP_Header $Header = null, string $Body = null) use ($resolve, $reject) {
            // Check if there is a header for the response
            if (!$Header)
              return $reject ('Request failed without response');
            
            // Check if there was an error
            if ($Header->isError ())
              return $reject ('Request failed with an error', $Header, $Body);
            
            // Forward the result
            $resolve ($Body, $Header);
          }
        );
      });
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
     * @param bool $authenticationPreflight (optional) Try request without authentication-information first (default)
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Stream_HTTP_Request $Request, qcEvents_Stream_HTTP_Header $Header = null, string $Body = null, mixed $Private = null) { }
     * 
     * @access public
     * @return qcEvents_Stream_HTTP_Request
     **/
    public function addRequest (qcEvents_Stream_HTTP_Request $Request, callable $Callback = null, $Private = null, $authenticationPreflight = true) {
      // Check if the request is already being processed
      if (in_array ($Request, $this->httpRequests, true))
        return $Request;
      
      // Push to our request-queue
      $this->httpRequests [] = $Request;
      
      // Remember some immutable parameters from request
      $Index = array_search ($Request, $this->httpRequests, true);
      $Method = $Request->getMethod ();
      $Username = $Request->getUsername ();
      $Password = $Request->getPassword ();
      
      // Acquire a socket for this
      $this->socketPool->acquireSocket (
        $Request->getHostname (),
        $Request->getPort (),
        qcEvents_Socket::TYPE_TCP,
        $Request->useTLS ()
      )->then (
        function (qcEvents_Socket $Socket) use ($Request, $authenticationPreflight, $Username, $Password) {
          // Pipe the socket to our request
          $Socket->pipe ($Request);
          
          // Handle authenticiation more special
          if (!$Request->hasBody () && $authenticationPreflight && (($Username !== null) || ($Password !== null))) {
            $Request->setMethod ('OPTIONS');
            $Request->setCredentials (null, null);
          }
        },
        function () use ($Callback, $Request, $Private) {
          // Forward socket-error to the callback
          $this->___raiseCallback ($Callback, $Request, null, null, $Private);
        }
      );
      
      // Watch events on the request
      $Request->once ('httpRequestResult')->then (
        function (qcEvents_Stream_HTTP_Request $Request, qcEvents_Stream_HTTP_Header $Header = null, $Body = null)
        use ($authenticationPreflight, $Username, $Password, $Method, $Index, $Callback, $Private) {
          // Remove from request-queue
          unset ($this->httpRequests [$Index]);
          
          // Retrive the current socket for the request
          $Socket = $Request->getPipeSource ();
          
          // Check if we may reuse the socket
          if (!$Header ||
              (($Header->getVersion () < 1.1) && ($Header->getField ('Connection') != 'keep-alive')) ||
              ($Header->getField ('Connection') == 'close'))
            $Socket->close ();
          
          // Release the socket (allow to reuse it)
          $Socket->unpipe ($Request);
          $this->socketPool->releaseSocket ($Socket);
          
          // Retrive the status of the response
          if ($Header)
            $Status = $Header->getStatus ();
          else
            $Status = 500;
          
          // Check for authentication
          if ($authenticationPreflight &&
              (($Username !== null) || ($Password !== null)) &&
              (($Status == 401) || ($Header && ($Header->hasField ('WWW-Authenticate'))))) {
            // Retrive supported methods
            $Methods = explode (',', $Header->getField ('WWW-Authenticate'));
            $onMethod = false;
            $aMethod = null;
            
            for ($i = 0; $i < count ($Methods); $i++) {
              $Token = $Methods [$i];
              $tToken = trim ($Token);
              
              if (($p = strpos ($tToken, ' ')) !== false) {
                if ($aMethod !== null)
                  $Request->addAuthenticationMethod ($aMethod, $mParams);
                
                $onMethod = true;
                $aMethod = substr ($tToken, 0, $p);
                $tToken = $Token = ltrim (substr (ltrim ($Token), $p + 1));
                
                $mParams = array ();
              } elseif (!$onMethod) {
                if ($aMethod !== null) {
                  $Request->addAuthenticationMethod ($aMethod, $mParams);
                  $aMethod = null;
                }
                
                $Request->addAuthenticationMethod ($tToken);
                
                continue;
              }
              
              if (($p = strpos ($tToken, '=')) !== false) {
                $Name = substr ($tToken, 0, $p);
                $Value = substr (ltrim ($Token), $p + 1);
                
                // Check for quotation
                if ((strlen ($Value) > 0) && (($Value [0] == '"') || ($Value [0] == "'"))) {
                  $Stop = $Value [0];
                  $Value = substr ($Value, 1);
                  
                  if (($p = strpos ($Value, $Stop)) === false) {
                    for ($j = $i + 1; $j < count ($Methods); $j++) {
                      if (($p = strpos ($Methods [++$i], $Stop)) !== false) {
                        $Value .= substr ($Methods [$i], 0, $p) . substr ($Methods [$i], $p + 1);
                        
                        break;
                      } else
                        $Value .= $Methods [$i];
                    }
                  } else
                    $Value = substr ($Value, 0, $p) . substr ($Value, $p + 1);
                }
                
                $mParams [$Name] = $Value;
              } else
                $mParams [] = $Token;
            }
            
            if ($aMethod !== null)
              $Request->addAuthenticationMethod ($aMethod, $mParams);
            
            // Restore the request's state
            $Request->setMethod ($Method);
            $Request->setCredentials ($Username, $Password);
            
            // Re-enqueue the request
            return $this->addRequest ($Request, $Callback, $Private, false);
          }
          
          // Check for redirects
          if ($Header &&
              ($Location = $Header->getField ('Location')) &&
              (($Status >= 300) && ($Status < 400)) &&
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
            return $this->addRequest ($Request, $Callback, $Private);
          }
 
          // Fire the callbacks
          if ($Callback)
            $this->___raiseCallback ($Callback, $Request, $Header, $Body, $Private);
 
          $this->___callback ('httpRequestResult', $Request, $Header, $Body);
        }
      );
      
      // Return the request again
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
          return $File->close ()->finally (
            function () use ($Callback, $Private, $URL, $Destination, $Header) {
              $this->___raiseCallback ($Callback, $URL, $Destination, ($Header && !$Header->isError () ? ($Header->hasBody () ? true : null) : false), $Private);
            }
          );
        
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
      // Just forward to our pool
      return $this->socketPool->setMaximumSockets ($Maximum);
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