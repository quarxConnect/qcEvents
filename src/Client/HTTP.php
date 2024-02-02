<?php

  /**
   * quarxConnect Events - HTTP Client Implementation
   * Copyright (C) 2014-2024 Bernd Holzmueller <bernd@quarxconnect.de>
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

  use quarxConnect\Events;
  use quarxConnect\Events\Promise;
  use quarxConnect\Events\Stream;

  class HTTP extends Events\Hookable {
    /**
     * All queued HTTP-Requests
     *
     * @var array
     **/
    private array $httpRequests = [];

    /**
     * Socket-Factory
     *
     * @var Events\ABI\Socket\Factory|null
     **/
    private Events\ABI\Socket\Factory|null $socketFactory = null;

    /**
     * Session-Cookies (if enabled)
     *
     * @var array|null
     **/
    private array|null $sessionCookies = null;

    /**
     * Path to save session-cookies at
     *
     * @var string|null
     **/
    private string|null $sessionPath = null;

    /**
     * Timeout for pending requests
     *
     * @var float|null
     **/
    private float|null $requestTimeout = null;

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
      $this->socketFactory = new Events\Socket\Factory\Limited (
        new Events\Socket\Factory ($eventBase)
      );
    }
    // }}}

    // {{{ getEventBase
    /**
     * Retrieve the instance of the current event-base
     *
     * @access public
     * @return Events\Base|null
     **/
    public function getEventBase (): ?Events\Base {
      return $this->socketFactory->getEventBase ();
    }
    // }}}

    // {{{ setEventBase
    /**
     * Change assigned event-base
     *
     * @access public
     * @return void
     **/
    public function setEventBase (Events\Base $eventBase): void {
      $this->socketFactory->setEventBase ($eventBase);
    }
    // }}}

    // {{{ getSocketFactory
    /**
     * Retrieve the socket-factory for this client
     *
     * @access public
     * @return Events\ABI\Socket\Factory
     **/
    public function getSocketFactory (): Events\ABI\Socket\Factory {
      return $this->socketFactory;
    }
    // }}}

    // {{{ setSocketFactory
    /**
     * Change the socket-factory for this HTTP-Client
     *
     * @param Events\ABI\Socket\Factory $socketFactory
     *
     * @access public
     * @return void
     **/
    public function setSocketFactory (Events\ABI\Socket\Factory $socketFactory): void {
      $this->socketFactory = $socketFactory;
    }
    // }}}

    // {{{ setRequestTimeout
    /**
     * Set a timeout for pending requests
     *
     * @param float $requestTimeout (optional)
     *
     * @access public
     * @return void
     **/
    public function setRequestTimeout (float $requestTimeout = null): void {
      $this->requestTimeout = $requestTimeout;
    }
    // }}}

    // {{{ useSessionCookies
    /**
     * Get/Set whether to use session-cookies
     *
     * @param bool $setState (optional)
     *
     * @access public
     * @return bool
     **/
    public function useSessionCookies (bool $setState = null): bool {
      // Check whether to return current state
      if ($setState === null)
        return ($this->sessionCookies !== null);

      // Check whether to remove all session-cookies
      if ($setState === false)
        $this->sessionCookies = null;
      elseif ($this->sessionCookies === null)
        $this->sessionCookies = [];

      return true;
    }
    // }}}

    // {{{ getSessionCookies
    /**
     * Retrieve all session-cookies from this client
     *
     * @access public
     * @return array
     **/
    public function getSessionCookies (): array {
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
    public function setSessionCookies (array $sessionCookies): void {
      if ($this->sessionCookies === null)
        throw new \Exception ('Session-Cookies were not enabled');

      # TODO: Sanitize cookies

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
     * @return Promise
     **/
    public function setSessionPath (string $sessionPath): Promise {
      if ($this->sessionCookies === null)
        return Promise::reject ('Session-Cookies were not enabled');

      if (is_dir ($sessionPath))
        return Promise::reject ('Destination is a directory');

      if (!is_file ($sessionPath) && !is_writable (dirname ($sessionPath)))
        return Promise::reject ('Destination is not writable');

      if (!is_file ($sessionPath)) {
        $this->sessionPath = $sessionPath;

        if (count ($this->sessionCookies) == 0)
          return Promise::resolve ();

        // Push new cookies to disk
        return Events\File::writeFileContents (
          $this->getEventBase (),
          $this->sessionPath . '.tmp',
          serialize ($this->sessionCookies)
        )->then (
          function (): void {
            if (rename ($this->sessionPath . '.tmp', $this->sessionPath))
              return;

            unlink ($this->sessionPath . '.tmp');

            throw new \Exception ('Failed to store session-cookies at destination');
          }
        )->catch (
          function (): void {
            $this->sessionPath = null;

            throw new Promise\Solution (func_get_args ());
          }
        );
      }

      return Events\File::readFileContents (
        $this->getEventBase (),
        $sessionPath
      )->then (
        function (string $fileContents) use ($sessionPath): ?Promise {
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
            return null;

          // Push new cookies to disk
          return Events\File::writeFileContents (
            $this->getEventBase (),
            $this->sessionPath . '.tmp',
            serialize ($this->sessionCookies)
          )->then (
            function (): void {
              if (rename ($this->sessionPath . '.tmp', $this->sessionPath))
                return;

              unlink ($this->sessionPath . '.tmp');

              throw new \Exception ('Failed to store session-cookies at destination');
            }
          )->catch (
            function (): void {
              $this->sessionPath = null;

              throw new Promise\Solution (func_get_args ());
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
     * @return Promise
     **/
    public function request (): Promise {
      // Process function-arguments
      $argv = func_get_args ();
      $argc = count ($argv);

      if ($argc < 1)
        return Promise::reject ('Missing Request-Argument');

      if ($argv [0] instanceof Stream\HTTP\Request) {
        $httpRequest = $argv [0];
        $authenticationPreflight = (($argc < 2) || ($argv [1] === true));
      } else {
        $httpRequest = new Stream\HTTP\Request ($argv [0]);

        // Set additional properties of the request
        if (($argc > 1) && ($argv [1] !== null))
          $httpRequest->setMethod ($argv [1]);

        if (($argc > 2) && is_array ($argv [2]))
          foreach ($argv [2] as $headerField=>$headerValue)
            $httpRequest->setField ($headerField, $headerValue);

        if (($argc > 3) && ($argv [3] !== null))
          $httpRequest->setBody ($argv [3]);

        $authenticationPreflight = (($argc < 5) || ($argv [4] === true));
      }

      // Create new socket-session
      $socketFactory = $this->getSocketFactory ();

      if ($socketFactory instanceof Events\Socket\Factory\Limited)
        $factorySession = $this->getSocketFactory ()->getSession ();
      else
        $factorySession = $socketFactory;

      return $this->requestInternal ($factorySession, $httpRequest, $authenticationPreflight);
    }
    // }}}

    // {{{ requestInternal
    /**
     * Enqueue a request on a socket-session
     *
     * @param Events\ABI\Socket\Factory $factorySession
     * @param Stream\HTTP\Request $Request
     * @param bool $authenticationPreflight
     * @param Events\Timer $requestTimer (optional)
     *
     * @access private
     * @return Promise
     **/
    private function requestInternal (Events\ABI\Socket\Factory $factorySession, Stream\HTTP\Request $httpRequest, bool $authenticationPreflight, Events\Timer $requestTimer = null): Promise {
      // Validate the request
      if ($httpRequest->getHostname () === null)
        return Promise::reject ('Invalid Url given');

      // Push to our request-queue
      $this->httpRequests [] = $httpRequest;

      // Remember some immutable parameters from request
      $requestIndex = array_search ($httpRequest, $this->httpRequests, true);
      $Method = $httpRequest->getMethod ();
      $Username = $httpRequest->getUsername ();
      $Password = $httpRequest->getPassword ();
      $sessionCookies = $this->getSessionCookiesForRequest ($httpRequest);

      if ($sessionCookies) {
        $originalCookies = $httpRequest->getField ('Cookie', true);
        $requestCookies = $originalCookies;

        $httpRequest->unsetField ('Cookie');

        if (count ($requestCookies) < 1)
          $requestCookies [] = '';

        foreach ($sessionCookies as $Cookie)
          $requestCookies [0] .= (strlen ($requestCookies [0]) > 0 ? ';' : '') . urlencode ($Cookie ['name']) . '=' . (!isset ($Cookie ['encode']) || $Cookie ['encode'] ? urlencode ($Cookie ['value']) : $Cookie ['value']);

        foreach ($requestCookies as $Cookie)
          $httpRequest->setField ('Cookie', $Cookie, false);
      } else
        $originalCookies = null;

      // Create deferred promise
      $deferredPromise = new Promise\Deferred ($factorySession->getEventBase ());

      // Setup timer for timeouts
      $requestTimeout = false;

      if (!$requestTimer && ($this->requestTimeout !== null))
        $requestTimer = $factorySession->getEventBase ()->addTimeout ($this->requestTimeout);

      if ($requestTimer) {
        $requestTimer->restart ();

        $requestTimer->then (
          function () use ($deferredPromise, &$requestTimeout): void {
            $requestTimeout = true;
            $deferredPromise->reject ('Timeout');
          }
        );
      }

      // Acquire a stream for this
      $factorySession->createConnection (
        $httpRequest->getHostname (),
        $httpRequest->getPort (),
        Events\Socket::TYPE_TCP,
        $httpRequest->useTLS ()
      )->then (
        function (Events\ABI\Stream $httpConnection)
        use ($httpRequest, $authenticationPreflight, $Username, $Password, $factorySession, $requestTimer, &$requestTimeout): Promise {
          // Check if we already reached a timeout
          if ($requestTimeout) {
            $httpConnection->close ();
            $factorySession->releaseConnection ($httpConnection);

            throw new \Exception ('Timeout');
          }

          // Handle authentication more special
          if (!$httpRequest->hasBody () && $authenticationPreflight && (($Username !== null) || ($Password !== null))) {
            $httpRequest->setMethod ('OPTIONS');
            $httpRequest->setCredentials (null, null);
          } elseif (!$authenticationPreflight && (($Username !== null) || ($Password !== null)))
            $httpRequest->addAuthenticationMethod ('Basic');

          // Pipe the socket to our request
          $httpConnection->pipeStream ($httpRequest);

          // Raise event
          $this->___callback ('httpRequestStart', $httpRequest);

          // Close the connection on timeout
          if ($requestTimer)
            $requestTimer->then (
              fn () => $httpConnection->close ()
            );

          // Watch events on the request
          return $httpRequest->once ('httpRequestResult');
        }
      )->finally (
        function () use ($requestIndex): void {
          // Remove from request-queue
          unset ($this->httpRequests [$requestIndex]);
        }
      )->then (
        function (Stream\HTTP\Header $responseHeader = null, $Body = null)
        use ($httpRequest, $authenticationPreflight, $Username, $Password, $Method, $originalCookies, $factorySession, $requestTimer, &$requestTimeout): Promise {
          // Restore cookies on request
          if ($originalCookies !== null) {
            $httpRequest->unsetField ('Cookie');

            foreach ($originalCookies as $Cookie)
              $httpRequest->setField ('Cookie', $Cookie, false);
          }

          // Retrieve the current socket for the request
          if ($httpConnection = $httpRequest->getPipeSource ()) {
            // Check if we may reuse the socket
            if (
              $requestTimeout ||
              !$responseHeader ||
              (($responseHeader->getVersion () < 1.1) && ($responseHeader->getField ('Connection') != 'keep-alive')) ||
              ($responseHeader->getField ('Connection') == 'close')
            )
              $httpConnection->close ();

            // Release the socket (allow to reuse it)
            $httpConnection->unpipe ($httpRequest);
            $factorySession->releaseConnection ($httpConnection);
          }

          // Check if we already reached a timeout
          if ($requestTimeout)
            throw new \Exception ('Timeout');

          // Abort here if no header was received
          if (!$responseHeader)
            throw new \exception ('No header was received');

          // Retrieve the status of the response
          $Status = $responseHeader->getStatus ();

          // Check whether to process cookies
          if (
            ($this->sessionCookies !== null) &&
            $responseHeader->hasField ('Set-Cookie')
          )
            $cookiePromise = $this->updateSessionCookies ($httpRequest, $responseHeader)->catch (function () { });
          else
            $cookiePromise = Promise::resolve ();

          // Check for authentication
          if (
            $authenticationPreflight &&
            (($Username !== null) || ($Password !== null)) &&
            is_array ($authenticationSchemes = $responseHeader->getAuthenticationInfo ())
          ) {
            // Push schemes to request
            foreach ($authenticationSchemes as $authenticationScheme)
              $httpRequest->addAuthenticationMethod ($authenticationScheme ['scheme'], $authenticationScheme ['params']);

            // Restore the request's state
            $httpRequest->setMethod ($Method);
            $httpRequest->setCredentials ($Username, $Password);

            // Re-enqueue the request
            return $cookiePromise->then (
              function () use ($factorySession, $httpRequest, $requestTimer) {
                if ($requestTimer)
                  $requestTimer->cancel ();

                return $this->requestInternal ($factorySession, $httpRequest, false);
              }
            );
          }

          // Check for redirects
          if (
            ($nextLocation = $responseHeader->getField ('Location')) &&
            (($Status >= 300) && ($Status < 400)) &&
            (($maxRedirects = $httpRequest->getMaxRedirects ()) > 0) &&
            is_array ($nextURI = parse_url ($nextLocation))
          ) {
            // Make sure the URL is fully qualified
            if ($rebuildLocation = !isset ($nextURI ['scheme']))
              $nextURI ['scheme'] = ($httpRequest->useTLS () ? 'https' : 'http');

            if (!isset ($nextURI ['host'])) {
              $nextURI ['host'] = $httpRequest->getHostname ();
              $rebuildLocation = true;

              if ($httpRequest->getPort () != ($httpRequest->useTLS () ? 443 : 80))
                $nextURI ['port'] = $httpRequest->getPort ();
              else
                unset ($nextURI ['port']);
            }

            if (isset ($nextURI ['path'])) {
              // Check for a relative redirect
              if (
                (substr ($nextURI ['path'], 0, 1) == '.') ||
                (strpos ($nextURI ['path'], '/../') !== false) ||
                (strpos ($nextURI ['path'], '/./') !== false)
              ) {
                $pathStackIn = explode ('/', dirname ($httpRequest->getURI ()) . '/' . $nextURI ['path']);
                $pathStack = [];
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

              if (substr ($nextURI ['path'], 0, 1) != '/')
                $nextURI ['path'] = '/' . $nextURI ['path'];
            }

            if ($rebuildLocation)
              $nextLocation = $nextURI ['scheme'] . '://' . $nextURI ['host'] . (isset ($nextURI ['port']) ? ':' . $nextURI ['port'] : '') . ($nextURI ['path'] ?? '/') . (isset ($nextURI ['query']) ? '?' . $nextURI ['query'] : '');

            // Fire a callback first
            $this->___callback ('httpRequestRedirect', $httpRequest, $nextLocation, $responseHeader, $Body);

            // Set the new location as destination
            $httpRequest->setURL ($nextLocation);

            // Lower the number of max requests
            $httpRequest->setMaxRedirects ($maxRedirects - 1);

            if ($Status < 307) {
              $httpRequest->setMethod ('GET');
              $httpRequest->setBody (null);
            }

            // Re-Enqueue the request
            return $cookiePromise->then (
              function () use ($factorySession, $httpRequest, $requestTimer): Promise {
                if ($requestTimer)
                  $requestTimer->cancel ();

                return $this->requestInternal ($factorySession, $httpRequest, true);
              }
            );
          }

          if ($requestTimer)
            $requestTimer->cancel ();

          // Fire the callbacks
          $this->___callback ('httpRequestResult', $httpRequest, $responseHeader, $Body);

          return $cookiePromise->then (
            function () use ($Body, $responseHeader, $httpRequest): Promise\Solution {
              return new Promise\Solution ([ $Body, $responseHeader, $httpRequest ]);
            }
          );
        }
      )->then (
        [ $deferredPromise, 'resolve' ],
        [ $deferredPromise, 'reject' ]
      );

      return $deferredPromise->getPromise ();
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
    public function addNewRequest ($URL, $Method = null, $Headers = null, $Body = null, callable $Callback = null, $Private = null): Stream\HTTP\Request {
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
    public function addRequest (Stream\HTTP\Request $Request, callable $Callback = null, $Private = null, $authenticationPreflight = true): Stream\HTTP\Request {
      // Check if the request is already being processed
      if (in_array ($Request, $this->httpRequests, true))
        return $Request;

      // Enqueue the request
      $this->request ($Request, $authenticationPreflight)->then (
        fn ($Body, Stream\HTTP\Header $Header = null) => $this->___raiseCallback ($Callback, $Request, $Header, $Body, $Private),
        fn () => $this->___raiseCallback ($Callback, $Request, null, null, $Private)
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
    public function addDownloadRequest ($URL, $Destination, callable $Callback = null, $Private = null): Stream\HTTP\Request {
      // Prepare Request-Headers
      $Headers = [];

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
        function (Stream\HTTP\Request $Request, Stream\HTTP\Header $Header) use ($Destination, &$File): void {
          // Check if we have to do something
          if ($Header->isError () || !$Header->hasBody ())
            return;

          // Create a new file-stream
          $File = new Events\File ($this->getEventBase (), $Destination, false, true, true);

          if (is_file ($Destination . '.etag'))
            unlink ($Destination . '.etag');   

          // Set modification-time
          if (
            (($mod = $Header->getField ('Last-Modified')) !== null) &&
            (($ts = strtotime ($mod)) !== false)
          )
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
     * @param int $maxRequests
     *
     * @access public
     * @return void
     **/
    public function setMaxRequests (int $maxRequests): void {
      if (!($this->getSocketFactory () instanceof Events\Socket\Factory\Limited))
        throw new \Exception ('Socket-Factory must implement Limited');

      $this->getSocketFactory ()->setMaxConnections ($maxRequests);
    }
    // }}}

    // {{{ getSessionCookiesForRequest
    /**
     * Retrieve session-cookies for a given request
     *
     * @param Stream\HTTP\Request $Request
     *
     * @access private
     * @return array
     **/
    private function getSessionCookiesForRequest (Stream\HTTP\Request $Request): array {
      // Check if we use/have session-cookies
      if ($this->sessionCookies === null)
        return [];

      // Prepare search-attributes
      $targetHostname = $Request->getHostname ();

      if ($targetHostname === null)
        return [];

      $Time = time ();
      $Path = $Request->getURI ();
      $Secure = $Request->useTLS ();

      if (substr ($Path, -1, 1) != '/') {
        $Path = dirname ($Path);

        if (strlen ($Path) > 1)
          $Path .= '/';
      }

      // Search matching cookies
      $Cookies = [];

      foreach ($this->sessionCookies as $Index=>$Cookie) {
        // Check expire-time
        if (
          ($Cookie ['expires'] !== null) &&
          ($Cookie ['expires'] < $Time)
        ) {
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
     * @return Promise
     **/
    private function updateSessionCookies (Stream\HTTP\Request $Request, Stream\HTTP\Header $Header): Promise {
      // Make sure we store session-cookies at all
      if ($this->sessionCookies === null)
        return Promise::resolve ();

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
      $responseCookies = [];

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
            $decodedValue = urldecode ($cookieValue);

            $Cookie ['name'] = $Name;
            $Cookie ['encode'] = (urlencode ($decodedValue) === $cookieValue);
            $Cookie ['value'] = ($Cookie ['encode'] ? $decodedValue : $cookieValue);

            continue;
          }

          // We treat all attributes in lower-case
          $Name = strtolower ($Name);

          // Sanitize attributes
          if ($Name == 'max-age') {
            // Make sure the age is valid
            if ((strval ((int)$cookieValue) != $cookieValue) || ((int)$cookieValue == 0))
              continue;

            // Rewrite to expires-cookie
            $Name = 'expires';
            $cookieValue = time () + (int)$cookieValue;
          } elseif ($Name == 'domain') {
            // Trim leading empty label if necessary
            if (substr ($cookieValue, 0, 1) === '.')
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

      // Check whether to store changes
      if (
        !$this->sessionPath ||
        !$cookiesChanged
      )
        return Promise::resolve ();

      return Events\File::writeFileContents (
        $this->getEventBase (),
        $this->sessionPath . '.tmp',
        serialize ($this->sessionCookies)
      )->then (
        function (): void {
          if (rename ($this->sessionPath . '.tmp', $this->sessionPath))
            return;

          unlink ($this->sessionPath . '.tmp');

          throw new \Exception ('Failed to store session-cookies at destination');
        }
      )->catch (
        function (): void {
          $this->sessionPath = null;

          throw new Promise\Solution (func_get_args ());
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
    private function mergeSessionCookies (array $initialCookies, array $newCookies, bool &$cookiesChanged = null): array {
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
          if (
            (strcasecmp ($initialCookie ['domain'], $newCookie ['domain']) != 0) ||
            ($initialCookie ['origin'] != $newCookie ['origin'])
          )
            continue;

          // Replace the cookie
          if (
            ($initialCookie ['value'] != $newCookie ['value']) ||
            ($initialCookie ['secure'] != $newCookie ['secure']) ||
            ($initialCookie ['expires'] != $newCookie ['expires'])
          )
            $cookiesChanged = true;

          if ($newCookie ['value'] !== 'deleted')
            $initialCookies [$cookieIndex] = $newCookie;
          else
            unset ($initialCookies [$cookieIndex]);

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
    protected function httpRequestStart (Stream\HTTP\Request $Request): void { }
    // }}}

    // {{{ httpRequestRedirect
    /**
     * Callback: A HTTP-Request is being redirected
     *
     * @param Stream\HTTP\Request $Request The original HTTP-Request-Object
     * @param string $Location The new location that is being redirected to
     * @param Stream\HTTP\Header $Header Response-Headers
     * @param string $Body (optional) Contents Response-Body
     *
     * @access protected
     * @return void
     **/
    protected function httpRequestRedirect (Stream\HTTP\Request $Request, $Location, Stream\HTTP\Header $Header, $Body = null): void { }
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
    protected function httpRequestResult (Stream\HTTP\Request $Request, Stream\HTTP\Header $Header = null, $Body = null): void { }
    // }}}
  }
