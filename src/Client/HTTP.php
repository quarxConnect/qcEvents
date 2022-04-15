<?php

  /**
   * quarxConnect Events - HTTP Client Implementation
   * Copyright (C) 2014-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  use quarxConnect\Events\Stream;
  use quarxConnect\Events;
  
  class HTTP extends Events\Hookable {
    /* All queued HTTP-Requests */
    private $httpRequests = [ ];
    
    /* Socket-Pool */
    private $socketPool = null;
    
    /* Session-Cookies (if enabled) */
    private $sessionCookies = null;
    
    /* Path to save session-cookies at */
    private $sessionPath = null;
    
    // {{{ __construct
    /**
     * Create a new HTTP-Client Pool
     * 
     * @param Events\Base $eventBase
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Events\Base $eventBase) {
      $this->socketPool = new Events\Socket\Pool ($eventBase);
    }
    // }}}
    
    // {{{ getEventBase
    /**
     * Retrive the handle of the current event-loop-handler
     * 
     * @access public
     * @return Events\Base May be NULL if none is assigned
     **/
    public function getEventBase () : ?Events\Base {
      return $this->socketPool->getEventBase ();
    }
    // }}}
    
    // {{{ setEventBase
    /**
     * Change assigned event-base
     * 
     * @access public
     * @return void
     **/
    public function setEventBase (Events\Base $eventBase) : void {
      $this->socketPool->setEventBase ($eventBase);
    }
    // }}}
    
    // {{{ getSocketPool
    /**
     * Retrive the socket-pool for this client
     * 
     * @access public
     * @return Events\Socket\Pool
     **/
    public function getSocketPool () : Events\Socket\Pool {
      return $this->socketPool;
    }
    // }}}
    
    // {{{ useSessionCookies
    /**
     * Get/Set wheter to use session-cookies
     * 
     * @param bool $setState (optional)
     * 
     * @access public
     * @return bool
     **/
    public function useSessionCookies (bool $setState = null) : bool {
      // Check wheter to return current state
      if ($setState === null)
        return ($this->sessionCookies !== null);
      
      // Check wheter to remove all session-cookies
      if ($setState === false)
        $this->sessionCookies = null;
      elseif ($this->sessionCookies === null)
        $this->sessionCookies = [ ];
      
      return true;
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
        throw new \Exception ('Session-Cookies were not enabled');
      
      return $this->sessionCookies;
    }
    // }}}
    
    // {{{ setSessionCookies
    /**
     * Overwrite all session-cookies
     *  
     * @param array $sessionCookies
     * 
     * @access public
     * @return void
     **/
    public function setSessionCookies (array $sessionCookies) : void {
      if ($this->sessionCookies === null)
        throw new \Exception ('Session-Cookies were not enabled');
      
      # TODO: Sanatize cookies
      
      $this->sessionCookies = $sessionCookies;
      
      if ($this->sessionPath)
        $this->saveSessionCookies ();
    }
    // }}}
    
    // {{{ setSessionPath
    /**
     * Set a path to store session-cookies at
     * 
     * @param string $sessionPath
     * 
     * @access public
     * @return Events\Promise
     **/
    public function setSessionPath (string $sessionPath) : Events\Promise {
      if ($this->sessionCookies === null)
        return Events\Promise::reject ('Session-Cookies were not enabled');
      
      if (is_dir ($sessionPath))
        return Events\Promise::reject ('Destination is a directory');
      
      if (!is_file ($sessionPath) && !is_writable (dirname ($sessionPath)))
        return Events\Promise::reject ('Destination is not writable');
      
      if (!is_file ($sessionPath)) {
        $this->sessionPath = $sessionPath;
        
        if (count ($this->sessionCookies) == 0)
          return Events\Promise::resolve ();
        
        // Push new cookies to disk
        return Events\File::writeFileContents (
          $this->getEventBase (),
          $this->sessionPath . '.tmp',
          serialize ($this->sessionCookies)
        )->then (
          function () {
            if (rename ($this->sessionPath . '.tmp', $this->sessionPath))
              return;
            
            unlink ($this->sessionPath . '.tmp');
            
            throw new \Exception ('Failed to store session-cookies at destination');
          }
        )->catch (
          function () {
            $this->sessionPath = null;
            
            throw new Events\Promise\Solution (func_get_args ());
          }
        );
      }
      
      return Events\File::readFileContents (
        $this->getEventBase (),
        $sessionPath
      )->then (
        function (string $fileContents) use ($sessionPath) {
          if (!is_array ($storedCookies = unserialize ($fileContents)))
            throw new \Exception ('Session-Path does not contain stored cookies');
          
          $haveCookies = (count ($this->sessionCookies) > 0);
          $cookiesChanged = false;
          
          $this->sessionPath = $sessionPath;
          $this->sessionCookies = $this->mergeSessionCookies (
            $storedCookies,
            $this->sessionCookies,
            $cookiesChanged
          );
          
          if (!$cookiesChanged || !$haveCookies)
            return;
          
          // Push new cookies to disk
          return Events\File::writeFileContents (
            $this->getEventBase (),
            $this->sessionPath . '.tmp',
            serialize ($this->sessionCookies)
          )->then (
            function () {
              if (rename ($this->sessionPath . '.tmp', $this->sessionPath))
                return;
              
              unlink ($this->sessionPath . '.tmp');
              
              throw new \Exception ('Failed to store session-cookies at destination');
            }
          )->catch (
            function () {
              $this->sessionPath = null;
              
              throw new Events\Promise\Solution (func_get_args ());
            }
          );
        }
      );
    }
    // }}}
    
    // {{{ request
    /**
     * Enqueue an HTTP-Request
     * 
     * @param Stream\HTTP\Request $Request
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
     * @return Events\Promise
     **/
    public function request () : Events\Promise {
      // Process function-arguements
      $argv = func_get_args ();
      $argc = count ($argv);
      
      if ($argc < 1)
        return Promise::reject ('Missing Request-Arguement');
      
      if ($argv [0] instanceof Stream\HTTP\Request) {
        $Request = $argv [0];
        $authenticationPreflight = (($argc < 2) || ($argv [1] === true));
      } else {
        $Request = new Stream\HTTP\Request ($argv [0]);
        
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
      
      // Create new socket-session
      $socketSession = $this->getSocketPool ()->getSession ();
      
      return $this->requestInternal ($socketSession, $Request, $authenticationPreflight)->finally (
        function () use ($socketSession) {
          $socketSession->close ();
        }
      );
    }
    // }}}
    
    // {{{ requestInternal
    /**
     * Enqueue a request on a socket-session
     * 
     * @param Events\Socket\Pool\Session $socketSession
     * @param Stream\HTTP\Request $Request
     * @param bool $authenticationPreflight
     * 
     * @access private
     * @return Events\Promise
     **/
    private function requestInternal (Events\Socket\Pool\Session $socketSession, Stream\HTTP\Request $Request, $authenticationPreflight) : Events\Promise {
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
      return $socketSession->createConnection (
        $Request->getHostname (),
        $Request->getPort (),
        Events\Socket::TYPE_TCP,
        $Request->useTLS ()
      )->then (
        function (Events\Socket $Socket) use ($Request, $authenticationPreflight, $Username, $Password) {
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
        function (Stream\HTTP\Header $Header = null, $Body = null)
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
            $socketSession->releaseConnection ($Socket);
          }
          
          // Abort here if no header was received
          if (!$Header)
            throw new \exception ('No header was received');
          
          // Retrive the status of the response
          $Status = $Header->getStatus ();
          
          // Check wheter to process cookies
          if (($this->sessionCookies !== null) && $Header->hasField ('Set-Cookie'))
            $cookiePromise = $this->updateSessionCookies ($Request, $Header)->catch (function () { });
          else
            $cookiePromise = Events\Promise::resolve ();
          
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
            return $cookiePromise->then (
              function () use ($socketSession, $Request) {
                return $this->requestInternal ($socketSession, $Request, false);
              }
            );
          }
          
          // Check for redirects
          if (
            ($nextLocation = $Header->getField ('Location')) &&
            (($Status >= 300) && ($Status < 400)) &&
            (($maxRedirects = $Request->getMaxRedirects ()) > 0) &&
            is_array ($nextURI = parse_url ($nextLocation))
          ) {
            // Make sure the URL is fully qualified
            if ($rebuildLocation = !isset ($nextURI ['scheme']))
              $nextURI ['scheme'] = ($Request->useTLS () ? 'https' : 'http');
            
            if (!isset ($nextURI ['host'])) {
              $nextURI ['host'] = $Request->getHostname ();
              $rebuildLocation = true;
              
              if ($Request->getPort () != ($Request->useTLS () ? 443 : 80))
                $nextURI ['port'] = $Request->getPort ();
              else
                unset ($nextURI ['port']);
            }
            
            // Check for a relative redirect
            if (
              (substr ($nextURI ['path'], 0, 1) == '.') ||
              (strpos ($nextURI ['path'], '/../') !== false) ||
              (strpos ($nextURI ['path'], '/./') !== false)
            ) {
              $pathStackIn = explode ('/', dirname ($Request->getURI ()) . '/' . $nextURI ['path']);
              $pathStack = [ ];
              $rebuildLocation = true;
              
              foreach ($pathStackIn as $pathSegment) {
                if ((strlen ($pathSegment) == 0) || ($pathSegment == '.'))
                  continue;
                
                if ($pathSegment == '..')
                  array_pop ($pathStack);
                else
                  $pathStack [] = $pathSegment;
              }
              
              $nextURI ['path'] = '/' . implode ('/', $pathStack);
            }
            
            if ($rebuildLocation)
              $nextLocation = $nextURI ['scheme'] . '://' . $nextURI ['host'] . (isset ($nextURI ['port']) ? ':' . $nextURI ['port'] : '') . $nextURI ['path'] . (isset ($nextURI ['query']) ? '?' . $nextURI ['query'] : '');
            
            // Fire a callback first
            $this->___callback ('httpRequestRediect', $Request, $nextLocation, $Header, $Body);
            
            // Set the new location as destination
            $Request->setURL ($nextLocation);
            
            // Lower the number of max requests
            $Request->setMaxRedirects ($maxRedirects - 1);
            
            if ($Status < 307) {
              $Request->setMethod ('GET');
              $Request->setBody (null);
            }
            
            // Re-Enqueue the request
            return $cookiePromise->then (
              function () use ($socketSession, $Request) {
                return $this->requestInternal ($socketSession, $Request, true);
              }
            );
          }
          
          // Fire the callbacks
          $this->___callback ('httpRequestResult', $Request, $Header, $Body);
          
          return $cookiePromise->then (
            function () use ($Body, $Header, $Request) {
              return new Events\Promise\Solution ([ $Body, $Header, $Request ]);
            }
          );
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
     * @return Stream\HTTP\Request
     **/
    public function addNewRequest ($URL, $Method = null, $Headers = null, $Body = null, callable $Callback = null, $Private = null) : Stream\HTTP\Request {
      // Make sure we have a request-object
      $Request = new Stream\HTTP\Request ($URL);
      
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
     * @param Stream\HTTP\Request $Request The HTTP-Request to enqueue
     * @param callback $Callback (optional) A callback to raise once the request was finished
     * @param mixed $Private (optional) Private data to pass to the callback
     * @param bool $authenticationPreflight (optional) Try request without authentication-information first (default)
     * 
     * The callback will be raised in the form of
     * 
     *   function (Stream\HTTP\Request $Request, Stream\HTTP\Header $Header = null, string $Body = null, mixed $Private = null) { }
     * 
     * @access public
     * @return Stream\HTTP\Request
     **/
    public function addRequest (Stream\HTTP\Request $Request, callable $Callback = null, $Private = null, $authenticationPreflight = true) : Stream\HTTP\Request {
      // Check if the request is already being processed
      if (in_array ($Request, $this->httpRequests, true))
        return $Request;
      
      // Enqueue the request
      $this->request ($Request, $authenticationPreflight)->then (
        function ($Body, Stream\HTTP\Header $Header = null) use ($Callback, $Request, $Private) {
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
     *   function (HTTP $Client, string $URL, string $Destination, bool $Status, mixed $Private = null) { }
     * 
     * @access public
     * @return Stream\HTTP\Request
     **/
    public function addDownloadRequest ($URL, $Destination, callable $Callback = null, $Private = null) : Stream\HTTP\Request {
      // Prepare Request-Headers
      $Headers = [ ];
      
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
      $Request->addHook (
        'httpHeaderReady',
        function (Stream\HTTP\Request $Request, Stream\HTTP\Header $Header) use ($Destination, &$File) {
          // Check if we have to do something
          if ($Header->isError () || !$Header->hasBody ())
            return;
          
          // Create a new file-stream
          $File = new Events\File ($this->getEventBase (), $Destination, false, true, true);
          
          if (is_file ($Destination . '.etag'))
            unlink ($Destination . '.etag');   
          
          // Set modification-time
          if ((($mod = $Header->getField ('Last-Modified')) !== null) &&
              (($ts = strtotime ($mod)) !== false))
            $File->setModificationTime ($ts);
          
          // Redirect the output of the stream
          $Request->pipe ($File);
        }
      );
      
      $Request->addHook (
        'httpRequestResult',
        function (Stream\HTTP\Request $Request, Stream\HTTP\Header $Header = null, $Body = null) use ($Callback, $Private, $URL, $Destination, &$File) {
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
     * @param Stream\HTTP\Request $Request
     * 
     * @access private
     * @return array
     **/
    private function getSessionCookiesForRequest (Stream\HTTP\Request $Request) : array {
      // Check if we use/have session-cookies
      if ($this->sessionCookies === null)
        return [ ];
      
      // Prepare search-attributes
      if (($targetHostname = $Request->getHostname ()) === null)
        return [ ];
      
      $Time = time ();
      $Path = $Request->getURI ();
      $Secure = $Request->useTLS ();
      
      if (substr ($Path, -1, 1) != '/') {
        $Path = dirname ($Path);
        
        if (strlen ($Path) > 1)
          $Path .= '/';
      }
      
      // Search matching cookies
      $Cookies = [ ];
      
      foreach ($this->sessionCookies as $Index=>$Cookie) {
        // Check expire-time
        if (($Cookie ['expires'] !== null) && ($Cookie ['expires'] < $Time)) {
          unset ($this->sessionCookies [$Index]);
          continue;
        }
        
        // Compare domain
        if (
          (
            !$Cookie ['origin'] &&
            (strcasecmp (substr ($targetHostname, -strlen ($Cookie ['domain']), strlen ($Cookie ['domain'])), $Cookie ['domain']) != 0)
          ) || (
            $Cookie ['origin'] &&
            (strcasecmp ($Cookie ['domain'], $targetHostname) != 0)
          )
        )
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
     * @param Stream\HTTP\Request $Request
     * @param Stream\HTTP\Header $Header
     * 
     * @access private
     * @return Events\Promise
     **/
    private function updateSessionCookies (Stream\HTTP\Request $Request, Stream\HTTP\Header $Header) : Events\Promise {
      // Make sure we store session-cookies at all
      if ($this->sessionCookies === null)
        return Events\Promise::resolve ();
      
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
      $cookiesChanged = false;
      $responseCookies = [ ];
      
      foreach ($Header->getField ('Set-Cookie', true) as $setCookie) {
        // Prepare cookie
        $Cookie = [
          'name' => null,
          'value' => null,
          'expires' => null,
          'domain' => $Origin,
          'origin' => true,
          'path' => $Path,
          'secure' => null,
        ];
        
        // Parse the cookie
        foreach (explode (';', $setCookie) as $i=>$cookieValue) {
          // Get name and value of current pair
          if (($p = strpos ($cookieValue, '=')) === false) {
            if ($i == 0)
              continue (2);
            
            $Name = trim ($cookieValue);
            $cookieValue = true;
          } else {
            $Name = ltrim (substr ($cookieValue, 0, $p));
            $cookieValue = substr ($cookieValue, $p + 1);
            
            if (
              (strlen ($cookieValue) > 0) &&
              ($cookieValue [0] == '"')
            )
              $cookieValue = substr ($cookieValue, 1, -1);
          }
          
          // First pair is name and value of the cookie itself
          if ($i == 0) {
            $Cookie ['name'] = $Name;
            $Cookie ['value'] = urldecode ($cookieValue);
            
            continue;
          }
          
          // We treat all attributes in lower-case
          $Name = strtolower ($Name);
          
          // Sanatize attributes
          if ($Name == 'max-age') {
            // Make sure the age is valid
            if ((strval ((int)$cookieValue) != $cookieValue) || ((int)$cookieValue == 0))
              continue;
            
            // Rewrite to expires-cookie
            $Name = 'expires';
            $cookieValue = time () + (int)$cookieValue;
          
          } elseif ($Name == 'domain') {
            // Trim leading empty label if neccessary
            if (substr ($cookieValue, 0, 1) == '.')
              $cookieValue = substr ($cookieValue, 1);
            
            // Make sure the value is on scope of origin
            $Domain = array_reverse (explode ('.', $cookieValue));
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
            if (($cookieValue = strtotime ($cookieValue)) === false)
              continue;
          
          } elseif ($Name == 'secure') {
            // Make sure the value is a boolean
            if (!is_bool ($cookieValue))
              continue;
          
          } elseif ($Name == 'path') {
            // BUGFIX: 1und1.de has urlencoded paths
            $cookieValue = urldecode ($cookieValue);
            
            if (substr ($cookieValue, -1, 1) != '/')
              $cookieValue .= '/';
            
          } else
            continue;
          
          $Cookie [$Name] = $cookieValue;
        }
        
        $responseCookies [] = $Cookie;
      }
      
      $this->sessionCookies = $this->mergeSessionCookies ($this->sessionCookies, $responseCookies, $cookiesChanged);
      
      // Check wheter to store changes
      if (!$this->sessionPath || !$cookiesChanged)
        return Events\Promise::resolve ();
      
      return Events\File::writeFileContents (
        $this->getEventBase (),
        $this->sessionPath . '.tmp',
        serialize ($this->sessionCookies)
      )->then (
        function () {
          if (rename ($this->sessionPath . '.tmp', $this->sessionPath))
            return;
          
          unlink ($this->sessionPath . '.tmp');
          
          throw new \Exception ('Failed to store session-cookies at destination');
        }
      )->catch (
        function () {
          $this->sessionPath = null;
          
          throw new Events\Promise\Solution (func_get_args ());
        }
      );
    }
    // }}}
    
    // {{{ mergeSessionCookies
    /**
     * Merge two sets of session-cookies
     * 
     * @param array $initialCookies
     * @param array $newCookies
     * @param bool $cookiesChanged
     * 
     * @access private
     * @return array
     **/
    private function mergeSessionCookies (array $initialCookies, array $newCookies, bool &$cookiesChanged = null) : array {
      $cookiesChanged = false;
      
      foreach ($newCookies as $newCookie) {
        // Inject into our collection
        foreach ($initialCookies as $cookieIndex=>$initialCookie) {
          // Compare the name
          if (strcmp ($initialCookie ['name'], $newCookie ['name']) != 0)
            continue;
          
          // Compare the path
          if (strcmp ($initialCookie ['path'], $newCookie ['path']) != 0)
            continue;
          
          // Compare domain
          if ((strcasecmp ($initialCookie ['domain'], $newCookie ['domain']) != 0) ||
              ($initialCookie ['origin'] != $newCookie ['origin']))
            continue;
          
          // Replace the cookie
          if (($initialCookie ['value'] != $newCookie ['value']) ||
              ($initialCookie ['secure'] != $newCookie ['secure']) ||
              ($initialCookie ['expires'] != $newCookie ['expires']))
            $cookiesChanged = true;
          
          $initialCookies [$cookieIndex] = $newCookie;
          
          continue (2);
        }
        
        // Push as new cookie to session
        $cookiesChanged = true;
        $initialCookies [] = $newCookie;
      }
      
      return $initialCookies;
    }
    // }}}
    
    
    // {{{ httpRequestStart
    /**
     * Callback: HTTP-Request is stated
     * 
     * @param Stream\HTTP\Request $Request The original HTTP-Request-Object
     * 
     * @access protected
     * @return void
     **/
    protected function httpRequestStart (Stream\HTTP\Request $Request) { }
    // }}}
    
    // {{{ httpRequestRediect
    /**
     * Callback: A HTTP-Request is being redirected
     * 
     * @param Stream\HTTP\Request $Request The original HTTP-Request-Object
     * @param string $Location The new location that is being redirected to
     * @param Stream\HTTP\Header $Header Repsonse-Headers
     * @param string $Body (optional) Contents Response-Body
     * 
     * @access protected
     * @return void
     **/
    protected function httpRequestRediect (Stream\HTTP\Request $Request, $Location, Stream\HTTP\Header $Header, $Body = null) { }
    // }}}
    
    // {{{ httpRequestResult
    /**
     * Callback: HTTP-Request is finished
     * 
     * @param Stream\HTTP\Request $Request The original HTTP-Request-Object
     * @param Stream\HTTP\Header $Header (optional) Contains Response-Headers, if a response was received. NULL on network-error
     * @param string $Body (optional) Contents Response-Body, if a response was received. NULL on network-error
     * 
     * @access protected
     * @return void
     **/
    protected function httpRequestResult (Stream\HTTP\Request $Request, Stream\HTTP\Header $Header = null, $Body = null) { }
    // }}}
  }
