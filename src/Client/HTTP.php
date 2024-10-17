<?php

  /**
   * quarxConnect Events - HTTP Client Implementation
   * Copyright (C) 2009-2022 Bernd Holzmueller <bernd@quarxconnect.de>
   * Copyright (C) 2023-2024 Bernd Holzmueller <bernd@innorize.gmbh>
   *+
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

  use Exception;
  use RuntimeException;

  use quarxConnect\Events;
  use quarxConnect\Events\ABI;
  use quarxConnect\Events\Base;
  use quarxConnect\Events\Emitter;
  use quarxConnect\Events\Feature;
  use quarxConnect\Events\Promise;
  use quarxConnect\Events\Stream;
  use quarxConnect\Events\Stream\HTTP\Cookie;
  use quarxConnect\Events\Stream\HTTP\Header as HttpHeader;
  use quarxConnect\Events\Stream\HTTP\Request as HttpRequest;

  class HTTP extends Emitter implements ABI\Hookable
  {
    use Feature\Hookable;

    /**
     * All queued HTTP-Requests
     *
     * @var array
     **/
    private array $httpRequests = [];

    /**
     * Socket-Factory
     *
     * @var ABI\Socket\Factory
     **/
    private ABI\Socket\Factory $socketFactory;

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
     * @param Base $eventBase
     *
     * @access friendly
     * @return void
     **/
    function __construct (Base $eventBase)
    {
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
     * @return Base|null
     **/
    public function getEventBase (): ?Base
    {
      return $this->socketFactory->getEventBase ();
    }
    // }}}

    // {{{ setEventBase
    /**
     * Change assigned event-base
     *
     * @param Base $eventBase
     *
     * @access public
     * @return void
     **/
    public function setEventBase (Base $eventBase): void
    {
      $this->socketFactory->setEventBase ($eventBase);
    }
    // }}}

    // {{{ getSocketFactory
    /**
     * Retrieve the socket-factory for this client
     *
     * @access public
     * @return ABI\Socket\Factory
     **/
    public function getSocketFactory (): ABI\Socket\Factory
    {
      return $this->socketFactory;
    }
    // }}}

    // {{{ setSocketFactory
    /**
     * Change the socket-factory for this HTTP-Client
     *
     * @param ABI\Socket\Factory $socketFactory
     *
     * @access public
     * @return void
     **/
    public function setSocketFactory (ABI\Socket\Factory $socketFactory): void
    {
      $this->socketFactory = $socketFactory;
    }
    // }}}

    // {{{ setRequestTimeout
    /**
     * Set a timeout for pending requests
     *
     * @param float|null $requestTimeout (optional)
     *
     * @access public
     * @return void
     **/
    public function setRequestTimeout (float $requestTimeout = null): void
    {
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
    public function useSessionCookies (bool $setState = null): bool
    {
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
     *
     * @throws RuntimeException
     **/
    public function getSessionCookies (): array {
      if ($this->sessionCookies === null)
        throw new RuntimeException('Session-Cookies were not enabled');

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
     *
     * @throws RuntimeException
     **/
    public function setSessionCookies (array $sessionCookies): void
    {
      if ($this->sessionCookies === null)
        throw new RuntimeException ('Session-Cookies were not enabled');

      $this->sessionCookies = array_filter (
        $sessionCookies,
        fn ($cookieValue) => $cookieValue instanceof Cookie
      );

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
        return Promise::reject (new RuntimeException ('Session-Cookies were not enabled'));

      if (is_dir ($sessionPath))
        return Promise::reject ('Destination is a directory');

      if (
        !is_file ($sessionPath) &&
        !is_writable (dirname ($sessionPath))
      )
        return Promise::reject ('Destination is not writable');

      if (!is_file ($sessionPath)) {
        $this->sessionPath = $sessionPath;

        if (count ($this->sessionCookies) == 0)
          return Promise::resolve ();

        // Push new cookies to disk
        return $this->saveSessionCookies ();
      }

      return Events\File::readFileContents (
        $this->getEventBase (),
        $sessionPath
      )->then (
        function (string $fileContents) use ($sessionPath): ?Promise {
          if (!is_array ($storedCookies = unserialize ($fileContents)))
            throw new RuntimeException ('Session-Path does not contain stored cookies');

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
          return $this->saveSessionCookies ();
        }
      );
    }
    // }}}

    // {{{ saveSessionCookies
    /**
     * Write session-cookies to disk
     *
     * @access private
     * @return Promise
     **/
    private function saveSessionCookies (): Promise
    {
      if ($this->sessionCookies === null)
        return Promise::reject (new RuntimeException ('Session-Cookies were not enabled'));

      return Events\File::writeFileContents (
        $this->getEventBase (),
        $this->sessionPath . '.tmp',
        serialize ($this->sessionCookies)
      )->then (
        function (): void {
          if (rename ($this->sessionPath . '.tmp', $this->sessionPath))
            return;

          unlink ($this->sessionPath . '.tmp');

          throw new RuntimeException('Failed to store session-cookies at destination');
        }
      )->catch (
        function (): void {
          $this->sessionPath = null;

          throw new Promise\Solution (func_get_args ());
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
     * @param string|null $Method (optional) Method to use on the request
     * @param array $Headers (optional) List of additional HTTP-Headers
     * @param string $requestBody (optional) Additional body for the request
     * @param bool $authenticationPreflight (optional) Try request without authentication-information first (default)
     *
     * @access public
     * @return Promise
     *
     * @noinspection PhpDocSignatureInspection
     */
    public function request (): Promise
    {
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
        /** @noinspection PhpPossiblePolymorphicInvocationInspection */
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
     * @param ABI\Socket\Factory $factorySession
     * @param Stream\HTTP\Request $httpRequest
     * @param bool $authenticationPreflight
     *
     * @access private
     * @return Promise
     **/
    private function requestInternal (ABI\Socket\Factory $factorySession, Stream\HTTP\Request $httpRequest, bool $authenticationPreflight): Promise
    {
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
        $originalCookies = $httpRequest->getCookies ();
      
        $httpRequest->setCookies (
          array_merge (
            $originalCookies,
            $sessionCookies
          )
        );
      } else
        $originalCookies = null;

      // Create deferred promise
      $deferredPromise = new Promise\Deferred ($factorySession->getEventBase ());

      // Setup timer for timeouts
      $requestTimeout = false;

      if ($this->requestTimeout !== null)
        $requestTimer = $factorySession->getEventBase ()->addTimeout ($this->requestTimeout);
      else
        $requestTimer = null;

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
        function (ABI\Stream $httpConnection)
        use ($httpRequest, $authenticationPreflight, $Username, $Password, $factorySession, $requestTimer, &$requestTimeout): Promise {
          // Check if we already reached a timeout
          if ($requestTimeout) {
            $httpConnection->close ();
            $factorySession->releaseConnection ($httpConnection);

            throw new Exception ('Timeout');
          }

          // Handle authentication more special
          if (!$httpRequest->hasBody () && $authenticationPreflight && (($Username !== null) || ($Password !== null))) {
            $httpRequest->setMethod ('OPTIONS');
            $httpRequest->setCredentials ();
          } elseif (!$authenticationPreflight && (($Username !== null) || ($Password !== null)))
            $httpRequest->addAuthenticationMethod ('Basic');

          // Pipe the socket to our request
          return $httpConnection->pipeStream ($httpRequest)->then (
            fn (): Promise => $this->dispatch(
              new HTTP\Event\Start ($this, $httpRequest)
            )->then (
              function () use ($requestTimer, $httpConnection, $httpRequest): Promise {
                // Close the connection on timeout
                $requestTimer?->then (
                  fn (): Promise => $httpConnection->close ()
                );

                // Watch events on the request
                return $httpRequest->once ('httpRequestResult');
              }
            )
          );
        }
      )->finally (
        function () use ($requestIndex): void {
          // Remove from request-queue
          unset ($this->httpRequests [$requestIndex]);
        }
      )->then (
        function (Stream\HTTP\Header $responseHeader = null, $responseBody = null)
        use ($httpRequest, $authenticationPreflight, $Username, $Password, $Method, $originalCookies, $factorySession, $requestTimer, &$requestTimeout): Promise {
          // Restore cookies on request
          if ($originalCookies !== null)
            $httpRequest->setCookies ($originalCookies);

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
            /** @noinspection PhpParamsInspection */
            $factorySession->releaseConnection ($httpConnection);
          }

          // Check if we already reached a timeout
          if ($requestTimeout)
            throw new Exception ('Timeout');

          // Abort here if no header was received
          if (!$responseHeader)
            throw new exception ('No header was received');

          // Retrieve the status of the response
          $responseStatus = $responseHeader->getStatus ();

          // Check whether to process cookies
          if (
            ($this->sessionCookies !== null) &&
            $responseHeader->hasCookies ()
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
                $requestTimer?->cancel ();

                return $this->requestInternal ($factorySession, $httpRequest, false);
              }
            );
          }

          // Stop the request-timer (if any)
          $requestTimer?->cancel ();

          // Check for redirects
          $nextLocation = $responseHeader->getField ('Location');
          $maxRedirects = $httpRequest->getMaxRedirects ();

          if (
            $nextLocation &&
            ($maxRedirects > 0)
          )
            $nextURI = parse_url ($nextLocation);
          else
            $nextURI = null;

          if (
            $nextLocation &&
            ($responseStatus >= 300) &&
            ($responseStatus < 400) &&
            ($maxRedirects > 0) &&
            is_array ($nextURI)
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
                (str_starts_with ($nextURI [ 'path' ], '.')) ||
                (str_contains ($nextURI [ 'path' ], '/../')) ||
                (str_contains ($nextURI [ 'path' ], '/./'))
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

              if (!str_starts_with ($nextURI [ 'path' ], '/'))
                $nextURI ['path'] = '/' . $nextURI ['path'];
            }

            if ($rebuildLocation)
              $nextLocation = $nextURI ['scheme'] . '://' . $nextURI ['host'] . (isset ($nextURI ['port']) ? ':' . $nextURI ['port'] : '') . ($nextURI ['path'] ?? '/') . (isset ($nextURI ['query']) ? '?' . $nextURI ['query'] : '');

            // Dispatch an event first
            return $this->dispatch (
              new HTTP\Event\Redirect ($this, $httpRequest, $nextLocation, $responseHeader, $responseBody)
            )->then (
              function () use ($httpRequest, $responseStatus, $nextLocation, $maxRedirects, $cookiePromise): Promise {
                // Set the new location as destination
                $httpRequest->setURL ($nextLocation);

                // Lower the number of max requests
                $httpRequest->setMaxRedirects ($maxRedirects - 1);

                // Convert to GET-Request
                if ($responseStatus < 307) {
                  $httpRequest->setMethod ('GET');
                  $httpRequest->setBody ();
                }

                return $cookiePromise;
              }
            // Re-Enqueue the request
            )->then (
              fn (): Promise => $this->requestInternal ($factorySession, $httpRequest, true)
            );
          }

          // Dispatch a result-event
          return $this->dispatch (
            new HTTP\Event\Result ($this, $httpRequest, $responseHeader, $responseBody)
          )->finally (
            fn () => $cookiePromise
          )->then (
            fn (): Promise\Solution => new Promise\Solution ([ $responseBody, $responseHeader, $httpRequest ])
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
     * @param string|null $Method (optional) Method to use on the request
     * @param array|null $Headers (optional) List of additional HTTP-Headers
     * @param string|null $requestBody (optional) Additional body for the request
     * @param callable|null $Callback (optional) A callback to raise once the request was finished
     * @param mixed $Private (optional) Private data to pass to the callback
     *
     * @remark See addRequest() below for callback-definition
     *
     * @access public
     * @return Stream\HTTP\Request
     **/
    public function addNewRequest (string $URL, string $Method = null, array $Headers = null, string $requestBody = null, callable $Callback = null, mixed $Private = null): Stream\HTTP\Request
    {
      // Make sure we have a request-object
      $Request = new Stream\HTTP\Request ($URL);

      // Set additional properties of the request
      if ($Method !== null)
        $Request->setMethod ($Method);

      if (is_array ($Headers))
        foreach ($Headers as $Key=>$Value)
          $Request->setField ($Key, $Value);

      if ($requestBody !== null)
        $Request->setBody ($requestBody);

      return $this->addRequest ($Request, $Callback, $Private);
    }
    // }}}

    // {{{ addRequest
    /**
     * Enqueue a prepared HTTP-Request
     *
     * @param Stream\HTTP\Request $Request The HTTP-Request to enqueue
     * @param callable|null $Callback (optional) A callback to raise once the request was finished
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
    public function addRequest (Stream\HTTP\Request $Request, callable $Callback = null, mixed $Private = null, bool $authenticationPreflight = true): Stream\HTTP\Request
    {
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
     * @param callable|null $Callback (optional) Callback to raise once the download was finished
     * @param mixed $Private (optional) A private parameter to pass to the callback
     *
     * The callback will be raised in the form of
     *
     *   function (HTTP $Client, string $URL, string $Destination, bool $Status, mixed $Private = null) { }
     *
     * @access public
     * @return Stream\HTTP\Request
     **/
    public function addDownloadRequest (string $URL, string $Destination, callable $Callback = null, mixed $Private = null): Stream\HTTP\Request
    {
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

      // Set up hooks
      /** @noinspection PhpUnhandledExceptionInspection */
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

      /** @noinspection PhpUnhandledExceptionInspection */
      $Request->addHook (
        'httpRequestResult',
        function (Stream\HTTP\Request $Request, Stream\HTTP\Header $Header = null) use ($Callback, $Private, $URL, $Destination, &$File): void {
        // Process etag
        if ($Header && $Header->hasBody () && !$Header->isError () && (($etag = $Header->getField ('ETag')) !== null))
          # TODO: This is not async!
          file_put_contents ($Destination . '.etag', $etag);

        // Run the final callback
        if ($File) {
          $File->close ()->finally (
            function () use ($Callback, $Private, $URL, $Destination, $Header) {
              $this->___raiseCallback ($Callback, $URL, $Destination, ($Header && !$Header->isError () ? ($Header->hasBody () ? true : null) : false), $Private);
            }
          );

          return;
        }

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
     *
     * @throws RuntimeException
     **/
    public function setMaxRequests (int $maxRequests): void
    {
      if (!($this->getSocketFactory () instanceof Events\Socket\Factory\Limited))
        throw new RuntimeException ('Socket-Factory must implement Limited');

      /** @noinspection PhpPossiblePolymorphicInvocationInspection */
      $this->getSocketFactory ()->setMaxConnections ($maxRequests);
    }
    // }}}

    // {{{ getSessionCookiesForRequest
    /**
     * Retrieve session-cookies for a given request
     *
     * @param Stream\HTTP\Request $forRequest
     *
     * @access private
     * @return array
     **/
    private function getSessionCookiesForRequest (Stream\HTTP\Request $forRequest): array
    {
      // Check if we use/have session-cookies
      if ($this->sessionCookies === null)
        return [];

      // Prepare search-attributes
      $targetHostname = $forRequest->getHostname ();

      if ($targetHostname === null)
        return [];

      $requestPath = $forRequest->getURI ();
      $isSecureRequest = $forRequest->useTLS ();

      if (!str_ends_with ($requestPath, '/')) {
        $requestPath = dirname ($requestPath);

        if (strlen ($requestPath) > 1)
          $requestPath .= '/';
      }

      // Search matching cookies
      $requestCookies = [];

      foreach ($this->sessionCookies as $cookieIndex=>$sessionCookie) {
        // Check expire-time
        if ($sessionCookie->isExpired ()) {
          unset ($this->sessionCookies [$cookieIndex]);

          continue;
        }

        // Compare domain
        if (!$sessionCookie->matchDomain ($targetHostname))
          continue;

        // Compare path
        if (!$sessionCookie->matchPath ($requestPath))
          continue;

        // Check secure-attribute
        if ($sessionCookie->cookieSecure && !$isSecureRequest)
          continue;

        // Push to cookies
        if (isset ($requestCookies [$sessionCookie->cookieName]))
          # TODO
          trigger_error ('Duplicate cookie, please fix');

        $requestCookies [$sessionCookie->cookieName] = $sessionCookie;
      }

      return $requestCookies;
    }
    // }}}

    // {{{ updateSessionCookies
    /**
     * Inject cookies from a request-result into our session
     *
     * @param HttpRequest $httpRequest
     * @param HttpHeader $httpHeader
     *
     * @access private
     * @return Promise
     **/
    private function updateSessionCookies (HttpRequest $httpRequest, HttpHeader $httpHeader): Promise
    {
      // Make sure we store session-cookies at all
      if ($this->sessionCookies === null)
        return Promise::resolve ();

      // Process all newly set cookies
      $cookiesChanged = false;
      $responseCookies = $httpHeader->getCookies ();
      $addedCookies = [];
      $updatedCookies = [];
      $removedCookies = [];

      $this->sessionCookies = $this->mergeSessionCookies (
        $this->sessionCookies,
        $responseCookies,
        $cookiesChanged,
        $addedCookies,
        $updatedCookies,
        $removedCookies
      );

      $dispatchPromise = Promise::all (
        array_merge (
          array_map (
            fn (Cookie $responseCookie): Promise => $this->dispatch (
              new HTTP\Event\Cookie\Added ($this, $httpRequest, $httpHeader, $responseCookie)
            ),
            $addedCookies
          ),
          array_map (
            fn (Cookie $responseCookie): Promise => $this->dispatch (
              new HTTP\Event\Cookie\Updated ($this, $httpRequest, $httpHeader, $responseCookie)
            ),
            $updatedCookies
          ),
          array_map (
            fn (Cookie $responseCookie): Promise => $this->dispatch (
              new HTTP\Event\Cookie\Removed ($this, $httpRequest, $httpHeader, $responseCookie)
            ),
            $removedCookies
          ),
        )
      );

      // Check whether to store changes
      if (
        !$this->sessionPath ||
        !$cookiesChanged
      )
        return $dispatchPromise;

      return $dispatchPromise->then (
        fn () => Events\File::writeFileContents (
          $this->getEventBase (),
          $this->sessionPath . '.tmp',
          serialize ($this->sessionCookies)
        )
      )->then (
        function (): void {
          if (rename ($this->sessionPath . '.tmp', $this->sessionPath))
            return;

          unlink ($this->sessionPath . '.tmp');

          throw new Exception ('Failed to store session-cookies at destination');
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
     * @param Cookie[] $initialCookies
     * @param Cookie[] $newCookies
     * @param bool $cookiesChanged (optional)
     * @param array|null $addedCookies (optional)
     * @param array|null $updatedCookies (optional)
     * @param array|null $removedCookies (optional)
     *
     * @access private
     * @return array
     **/
    private function mergeSessionCookies (array $initialCookies, array $newCookies, bool &$cookiesChanged = false, array &$addedCookies = null, array &$updatedCookies = null, array &$removedCookies = null): array {
      $cookiesChanged = false;

      $addedCookies = [];
      $updatedCookies = [];
      $removedCookies = [];

      foreach ($newCookies as $newCookie) {
        // Inject into our collection
        foreach ($initialCookies as $cookieIndex=>$initialCookie) {
          // Compare the cookies
          if (!$newCookie->compareId ($initialCookie))
            continue;

          // Update the cookie
          if ($newCookie->isDeleted ()) {
            $cookiesChanged = true;
            $removedCookies [] = $initialCookie;

            unset ($initialCookies [$cookieIndex]);
          } elseif (!$newCookie->compareValue ($initialCookie)) {
            $cookiesChanged = true;
            $initialCookies [$cookieIndex] = $newCookie;
            $updatedCookies [] = $newCookie;
          }

          continue (2);
        }

        // Push as new cookie to session
        $cookiesChanged = true;
        $initialCookies [] = $newCookie;
        $addedCookies [] = $newCookie;
      }

      return $initialCookies;
    }
    // }}}
  }
