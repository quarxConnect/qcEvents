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
    
    /* Session-Cookies (if enabled) */
    private $sessionCookies = null;
    
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
    
    // {{{ getSocketPool
    /**
     * Retrive the socket-pool for this client
     * 
     * @access public
     * @return qcEvents_Socket_Pool
     **/
    public function getSocketPool () : qcEvents_Socket_Pool {
      return $this->socketPool;
    }
    // }}}
    
    // {{{ getSessionCookies
    /**
     * Retrive all session-cookies from this client
     * 
     * @access public
     * @return array
     **/
    public function getSessionCookies () : array {
      if ($this->sessionCookies === null)
        return array ();
      
      return $this->sessionCookies;
    }
    // }}}
    
    // {{{ setSessionCookies
    /**
     * Overwrite all session-cookies
     *  
     * @param array $Cookies
     * 
     * @access public
     * @return void
     **/
    public function setSessionCookies (array $Cookies) {
      $this->sessionCookies = $Cookies;
    }
    // }}}
    
    // {{{ useSessionCookies
    /**
     * Get/Set wheter to use session-cookies
     * 
     * @param bool $Toggle (optional)
     * 
     * @access public
     * @return bool
     **/
    public function useSessionCookies ($Toggle = null) {
      // Check wheter to return current state
      if ($Toggle === null)
        return ($this->sessionCookies !== null);
      
      // Check wheter to remove all session-cookies
      if ($Toggle === false)
        $this->sessionCookies = null;
      elseif (($Toggle === true) && ($this->sessionCookies === null))
        $this->sessionCookies = array ();
      
      return is_bool ($Toggle);
    }
    // }}}
    
    // {{{ request
    /**
     * Enqueue an HTTP-Request
     * 
     * @param qcEvents_Stream_HTTP_Request $Request
     * @param bool $authenticationPreflight (optional) Try request without authentication-information first (default)
     * 
     * - OR -
     * 
     * @param string $URL The requested URL
     * @param enum $Method (optional) Method to use on the request
     * @param array $Headers (optional) List of additional HTTP-Headers
     * @param string $Body (optional) Additional body for the request
     * @param bool $authenticationPreflight (optional) Try request without authentication-information first (default)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function request () : qcEvents_Promise {
      // Process function-arguements
      $argv = func_get_args ();
      $argc = count ($argv);
      
      if ($argc < 1)
        return qcEvents_Promise::reject ('Missing Request-Arguement');
      
      if ($argv [0] instanceof qcEvents_Stream_HTTP_Request) {
        $Request = $argv [0];
        $authenticationPreflight = (($argc < 2) || ($argv [1] === true));
      } else {
        $Request = new qcEvents_Stream_HTTP_Request ($argv [0]);
        
        // Set additional properties of the request
        if (($argc > 1) && ($argv [1] !== null))
          $Request->setMethod ($argv [1]);
        
        if (($argc > 2) && is_array ($argv [2]))
          foreach ($argv [2] as $Key=>$Value)
            $Request->setField ($Key, $Value);
        
        if (($argc > 3) && ($argv [3] !== null))
          $Request->setBody ($argv [3]);
        
        $authenticationPreflight = (($argc < 5) || ($argv [4] === true));
      }
      
      // Push to our request-queue
      $this->httpRequests [] = $Request;
      
      // Remember some immutable parameters from request
      $Index = array_search ($Request, $this->httpRequests, true);
      $Method = $Request->getMethod ();
      $Username = $Request->getUsername ();
      $Password = $Request->getPassword ();
      
      if ($newCookies = $this->getSessionCookiesForRequest ($Request)) {
        $orgCookies = $Request->getField ('Cookie', true);
        $Cookies = $orgCookies;
        
        $Request->unsetField ('Cookie');
        
        if (count ($Cookies) < 1)
          $Cookies [] = '';
        
        foreach ($newCookies as $Cookie)
          $Cookies [0] .= (strlen ($Cookies [0]) > 0 ? ';' : '') . urlencode ($Cookie ['name']) . '=' . urlencode ($Cookie ['value']);
        
        foreach ($Cookies as $Cookie)
          $Request->setField ('Cookie', $Cookie, false);
      } else
        $orgCookies = null;
      
      // Acquire a socket for this
      $socketSession = $this->getSocketPool ()->getSession ();
      
      return $socketSession->acquireSocket (
        $Request->getHostname (),
        $Request->getPort (),
        qcEvents_Socket::TYPE_TCP,
        $Request->useTLS ()
      )->then (
        function (qcEvents_Socket $Socket) use ($Request, $authenticationPreflight, $Username, $Password) {
          // Handle authenticiation more special
          if (!$Request->hasBody () && $authenticationPreflight && (($Username !== null) || ($Password !== null))) {
            $Request->setMethod ('OPTIONS');
            $Request->setCredentials (null, null);
          } elseif (!$authenticationPreflight && (($Username !== null) || ($Password !== null)))
            $Request->addAuthenticationMethod ('Basic');
          
          // Pipe the socket to our request
          $Socket->pipe ($Request);
          
          // Raise event
          $this->___callback ('httpRequestStart', $Request);
          
          // Watch events on the request
          return $Request->once ('httpRequestResult');
        }
      )->then (
        function (qcEvents_Stream_HTTP_Header $Header = null, $Body = null)
        use ($Request, $authenticationPreflight, $Username, $Password, $Method, $Index, $orgCookies, $socketSession) {
          // Remove from request-queue
          unset ($this->httpRequests [$Index]);
          
          // Restore cookies on request
          if ($orgCookies !== null) {
            $Request->unsetField ('Cookie');
            
            foreach ($orgCookies as $Cookie)
              $Request->setField ('Cookie', $Cookie, false);
          }
          
          // Retrive the current socket for the request
          if ($Socket = $Request->getPipeSource ()) {
            // Check if we may reuse the socket
            if (!$Header ||
                (($Header->getVersion () < 1.1) && ($Header->getField ('Connection') != 'keep-alive')) ||
                ($Header->getField ('Connection') == 'close'))
              $Socket->close ();
            
            // Release the socket (allow to reuse it)
            $Socket->unpipe ($Request);
            $socketSession->releaseSocket ($Socket);
          }
          
          // Abort here if no header was received
          if (!$Header)
            throw new exception ('No header was received');
          
          // Retrive the status of the response
          $Status = $Header->getStatus ();
          
          // Check wheter to process cookies
          if (($this->sessionCookies !== null) && $Header->hasField ('Set-Cookie'))
            $this->updateSessionCookies ($Request, $Header);
          
          // Check for authentication
          if ($authenticationPreflight &&
              (($Username !== null) || ($Password !== null)) &&
              is_array ($authenticationSchemes = $Header->getAuthenticationInfo ())) {
            // Push schemes to request
            foreach ($authenticationSchemes as $authenticationScheme)
              $Request->addAuthenticationMethod ($authenticationScheme ['scheme'], $authenticationScheme ['params']);
            
            // Restore the request's state
            $Request->setMethod ($Method);
            $Request->setCredentials ($Username, $Password);
            
            // Re-enqueue the request
            return $this->request ($Request, false);
          }
          
          // Check for redirects
          if (($Location = $Header->getField ('Location')) &&
              (($Status >= 300) && ($Status < 400)) &&
              (($max = $Request->getMaxRedirects ()) > 0) &&
              is_array ($URI = parse_url ($Location))) {
            // Make sure the URL is fully qualified
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
            
            if ($Status < 307) {
              $Request->setMethod ('GET');
              $Request->setBody (null);
            }
            
            // Re-Enqueue the request
            return $this->request ($Request);
          }
          
          // Fire the callbacks
          $this->___callback ('httpRequestResult', $Request, $Header, $Body);
          
          return new qcEvents_Promise_Solution (array ($Body, $Header, $Request));
        }
      );
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
      
      // Enqueue the request
      $this->request ($Request, $authenticationPreflight)->then (
        function ($Body, qcEvents_Stream_HTTP_Header $Header = null) use ($Callback, $Request, $Private) {
          // Forward socket-error to the callback
          $this->___raiseCallback ($Callback, $Request, $Header, $Body, $Private);
        },
        function () use ($Callback, $Request, $Private) {
          // Forward socket-error to the callback
          $this->___raiseCallback ($Callback, $Request, null, null, $Private);
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
      return $this->getSocketPool ()->setMaximumSockets ($Maximum);
    }
    // }}}
    
    // {{{ getSessionCookiesForRequest
    /**
     * Retrive session-cookies for a given request
     * 
     * @param qcEvents_Stream_HTTP_Request $Request
     * 
     * @access private
     * @return array
     **/
    private function getSessionCookiesForRequest (qcEvents_Stream_HTTP_Request $Request) {
      // Check if we use/have session-cookies
      if ($this->sessionCookies === null)
        return null;
      
      // Prepare search-attributes
      $Time = time ();
      $Hostname = $Request->getHostname ();
      $Path = $Request->getURI ();
      $Secure = $Request->useTLS ();
      
      if (substr ($Path, -1, 1) != '/') {
        $Path = dirname ($Path);
        
        if (strlen ($Path) > 1)
          $Path .= '/';
      }
      
      // Search matching cookies
      $Cookies = array ();
      
      foreach ($this->sessionCookies as $Index=>$Cookie) {
        // Check expire-time
        if (($Cookie ['expires'] !== null) && ($Cookie ['expires'] < $Time)) {
          unset ($this->sessionCookies [$Index]);
          continue;
        }
        
        // Compare domain
        if ((!$Cookie ['origin'] && (strcasecmp (substr ($Hostname, -strlen ($Cookie ['domain']), strlen ($Cookie ['domain'])), $Hostname) != 0)) ||
            (strcasecmp ($Cookie ['domain'], $Hostname) != 0))
          continue;
        
        // Compare path
        if (strncmp ($Cookie ['path'], $Path, strlen ($Cookie ['path'])) != 0)
          continue;
        
        // Check secure-attribute
        if ($Cookie ['secure'] && !$Secure)
          continue;
        
        // Push to cookies
        if (isset ($Cookies ['name']))
          # TODO
          trigger_error ('Duplicate cookie, please fix');
        
        $Cookies [$Cookie ['name']] = $Cookie;
      }
      
      return $Cookies;
    }
    // }}}
    
    // {{{ updateSessionCookies
    /**
     * Inject cookies from a request-result into our session
     * 
     * @param qcEvents_Stream_HTTP_Request $Request
     * @param qcEvents_Stream_HTTP_Header $Header
     * 
     * @access private
     * @return void
     **/
    private function updateSessionCookies (qcEvents_Stream_HTTP_Request $Request, qcEvents_Stream_HTTP_Header $Header) {
      // Make sure we store session-cookies at all
      if ($this->sessionCookies === null)
        return;
      
      // Prepare the origin
      $Origin = $Request->getHostname ();
      $OriginA = array_reverse (explode ('.', $Origin));
      $OriginL = count ($OriginA);
      
      $Path = $Request->getURI ();
      
      if (substr ($Path, -1, 1) != '/') {
        $Path = dirname ($Path);
        
        if (strlen ($Path) > 1)
          $Path .= '/';
      }
      
      // Process all newly set cookies
      foreach ($Header->getField ('Set-Cookie', true) as $setCookie) {
        // Prepare cookie
        $Cookie = array (
          'name' => null,
          'value' => null,
          'expires' => null,
          'domain' => $Origin,
          'origin' => true,
          'path' => $Path,
          'secure' => null,
        );
        
        // Parse the cookie
        foreach (explode (';', $setCookie) as $i=>$Value) {
          // Get name and value of current pair
          if (($p = strpos ($Value, '=')) === false) {
            if ($i == 0)
              continue (2);
            
            $Name = trim ($Value);
            $Value = true;
          } else {
            $Name = ltrim (substr ($Value, 0, $p));
            $Value = substr ($Value, $p + 1);
            
            if ($Value [0] == '"')
              $Value = substr ($Value, 1, -1);
          }
          
          // First pair is name and value of the cookie itself
          if ($i == 0) {
            $Cookie ['name'] = $Name;
            $Cookie ['value'] = $Value;
            
            continue;
          }
          
          // We treat all attributes in lower-case
          $Name = strtolower ($Name);
          
          // Sanatize attributes
          if ($Name == 'max-age') {
            // Make sure the age is valid
            if ((strval ((int)$Value) != $Value) || ((int)$Value == 0))
              continue;
            
            // Rewrite to expires-cookie
            $Name = 'expires';
            $Value = time () + (int)$Value;
          
          } elseif ($Name == 'domain') {
            // Make sure the value is on scope of origin
            $Domain = array_reverse (explode ('.', $Value));
            $Length = count ($Domain);
            
            if (($Length > $OriginL) || ($Length < 2))
              continue;
            
            for ($i = 0; $i < $Length; $i++)
              if (strcasecmp ($Domain [$i], $OriginA [$i]) != 0)
                continue (2);
            
            // Allow domain-matching on the cookie
            $Cookie ['origin'] = false;
            
          } elseif ($Name == 'expires') {
            // Make sure the value is a valid timestamp
            if (($Value = strtotime ($Value)) === false)
              continue;
          
          } elseif ($Name == 'secure') {
            // Make sure the value is a boolean
            if (!is_bool ($Value))
              continue;
          
          } elseif ($Name == 'path') {
            if (substr ($Value, -1, 1) != '/')
              $Value .= '/';
            
          } else
            continue;
          
          $Cookie [$Name] = $Value;
        }
        
        // Inject into our collection
        foreach ($this->sessionCookies as $Index=>$sCookie) {
          // Compare the name
          if (strcmp ($sCookie ['name'], $Cookie ['name']) != 0)
            continue;
          
          // Compare the path
          if (strcmp ($sCookie ['path'], $Cookie ['path']) != 0)
            continue;
          
          // Compare domain
          if ((strcasecmp ($sCookie ['domain'], $Cookie ['domain']) != 0) ||
              ($sCookie ['origin'] != $Cookie ['origin']))
            continue;
          
          // Replace the cookie
          $this->sessionCookies [$Index] = $Cookie;
          
          continue (2);
        }
        
        // Push as new cookie to session
        $this->sessionCookies [] = $Cookie;
      }
    }
    // }}}
    
    
    // {{{ httpRequestStart
    /**
     * Callback: HTTP-Request is stated
     * 
     * @param qcEvents_Stream_HTTP_Request $Request The original HTTP-Request-Object
     * 
     * @access protected
     * @return void
     **/
    protected function httpRequestStart (qcEvents_Stream_HTTP_Request $Request) { }
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