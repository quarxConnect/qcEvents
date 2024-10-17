<?php

  /**
   * quarxConnect Events - HTTP Header Object
   * Copyright (C) 2009-2022 Bernd Holzmueller <bernd@quarxconnect.de>
   * Copyright (C) 2023-2024 Bernd Holzmueller <bernd@innorize.gmbh>
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

  declare (strict_types=1);

  namespace quarxConnect\Events\Stream\HTTP;

  use InvalidArgumentException;
  use LogicException;
  use RuntimeException;

  use quarxConnect\Events;

  class Header {
    /**
     * Protocol-Name of this header-implementation.
     *
     * @var string
     */
    protected static string $protoName = 'HTTP';

    /* Valid Header-Types */
    public const TYPE_REQUEST  = 1;
    public const TYPE_RESPONSE = 2;

    /**
     * Type of this header
     *
     * @var int
     **/
    private int $headerType = Header::TYPE_REQUEST;

    /**
     * Version-String for this header
     *
     * @var string
     **/
    private string $headerVersion = 'HTTP/1.1';

    /**
     * List of valid request-methods
     *
     * @var string[]
     **/
    protected static array $requestMethods = [
      'GET',
      'POST',
      'PUT',
      'PATCH',
      'DELETE',
      'HEAD',
      'OPTIONS',
      
      // WebDAV-Stuff
      'REPORT',
      'PROPFIND',
    ];

    /**
     * Method for a request-header
     *
     * @var string
     **/
    private string $requestMethod = 'GET';

    /**
     * URI for a request-header
     * 
     * @var string
     **/
    private string $requestURI = '/';
    
    /**
     * Status-Code of a response-header
     *
     * @var integer
     */
    private int $responseStatus = 200;

    /**
     * Message of a response-header
     *
     * @var string
     */
    private string $responseMessage = '';

    /**
     * All header-values
     *
     * @var string[]
     **/
    private array $headerValues = [];

    // {{{ __construct
    /**
     * Create a new generic HTTP-Header
     *
     * @param array $headerData
     *
     * @throws InvalidArgumentException
     **/
    public function __construct (array $headerData)
    {
      // Check the type of this header
      if (count ($headerData) < 1)
        throw new InvalidArgumentException ('Header requires at least one line');

      $httpIdentifier = array_shift ($headerData);

      if (substr ($httpIdentifier, 0, strlen ($this::$protoName) + 1) == $this::$protoName . '/') {
        $this->headerType = self::TYPE_RESPONSE;
        $this->headerVersion = substr ($httpIdentifier, 0, ($p = strpos ($httpIdentifier, ' ')));

        $this->responseStatus = (int)(substr ($httpIdentifier, $p + 1, 3));
        $this->responseMessage = substr ($httpIdentifier, $p + 5);
      } else {
        // Validate request-line
        $methodDelimiter = strpos ($httpIdentifier, ' ');

        if ($methodDelimiter === false)
          throw new InvalidArgumentException ('Invalid request-line (missing separator for protocol and uri)');

        $versionDelimiter = strrpos ($httpIdentifier, ' ');

        if ($versionDelimiter == $methodDelimiter)
          throw new InvalidArgumentException ('Invalid request-line (missing separator for uri and version)');

        // Extract information from request-line
        $this->headerType = self::TYPE_REQUEST;
        $this->headerVersion = substr ($httpIdentifier, $versionDelimiter + 1);

        $this->requestMethod = substr ($httpIdentifier, 0, $methodDelimiter);
        $this->requestURI = substr ($httpIdentifier, $methodDelimiter + 1, $versionDelimiter - $methodDelimiter - 1);
      }

      // Parse all additional lines
      foreach ($headerData as $headerLine) {
        // Check for colon (this should always be present)
        $valueDelimiter = strpos ($headerLine, ':');

        if ($valueDelimiter === false)
          throw new InvalidArgumentException ('Invalid line on header');

        // Store the header
        $headerName = substr ($headerLine, 0, $valueDelimiter);
        $headerKey = strtolower ($headerName);
        
        if (isset ($this->headerValues [$headerKey]))
          $this->headerValues [$headerKey][] = [ $headerName, trim (substr ($headerLine, $valueDelimiter + 1)) ];
        else
          $this->headerValues [$headerKey] = [ [ $headerName, trim (substr ($headerLine, $valueDelimiter + 1)) ] ];
      }
    }
    // }}}

    // {{{ __toString
    /**
     * Convert the header into a string
     *
     * @return string
     **/
    public function __toString (): string
    {
      if ($this->headerType == self::TYPE_RESPONSE)
        $headerBuffer = $this->headerVersion . ' ' . $this->responseStatus . ' ' . $this->responseMessage . "\r\n";
      else
        $headerBuffer = $this->requestMethod . ' ' . $this->requestURI . ' ' . $this->headerVersion . "\r\n";

      foreach ($this->headerValues as $headerValues)
        foreach ($headerValues as $headerValue)
          if (is_array ($headerValue [1]))
            foreach ($headerValue [1] as $singleValue)
              $headerBuffer .= $headerValue [0] . ': ' . $singleValue . "\r\n";
          else
            $headerBuffer .= $headerValue [0] . ': ' . $headerValue [1] . "\r\n";

      return $headerBuffer . "\r\n";
    }
    // }}}

    // {{{ isRequest
    /**
     * Check if this header is a http-request
     *
     * @access public
     * @return bool
     **/
    public function isRequest (): bool
    {
      return ($this->headerType === self::TYPE_REQUEST);
    }
    // }}}

    // {{{ isResponse
    /**
     * Check if this header is a http-response
     *
     * @access public
     * @return bool
     **/
    public function isResponse (): bool
    {
      return ($this->headerType === self::TYPE_RESPONSE);
    }
    // }}}

    // {{{ isError
    /**
     * Check if this header indicates an error-status
     *
     * @access public
     * @return bool
     * 
     * @throws LogicException
     **/
    public function isError (): bool
    {
      if (!$this->isResponse ())
        throw new LogicException ('Not a response-header');

      return ($this->getStatus () >= 400);
    }
    // }}}

    // {{{ getType
    /**
     * Retrieve the type of this header
     *
     * @access public
     * @return int
     **/
    public function getType (): int
    {
      return $this->headerType;
    }
    // }}}

    // {{{ getVersion
    /**
     * Retrieve the version of this header
     *
     * @param bool $asString (optional)
     *
     * @access public
     * @return string|float
     **/
    public function getVersion (bool $asString = false): string|float
    {
      if ($asString)
        return substr ($this->headerVersion, strrpos ($this->headerVersion, '/') + 1);

      return (float)(substr ($this->headerVersion, strrpos ($this->headerVersion, '/') + 1));
    }
    // }}}

    // {{{ setVersion
    /**
     * Set the version of this header
     *
     * @param string|float $headerVersion
     *
     * @access public
     * @return void
     **/
    public function setVersion (string|float $headerVersion): void
    {
      // Always treat the version as a string here
      if (!is_string ($headerVersion))
        $headerVersion = number_format ($headerVersion, 1, '.', '');

      // Check if the protocol-name is included in the version
      $versionDelimiter = strrpos ($headerVersion, '/');

      if ($versionDelimiter === false) {
        $versionDelimiter = strrpos ($this->headerVersion, '/');

        if ($versionDelimiter !== false)
          $headerVersion = substr ($this->headerVersion, 0, $versionDelimiter) . '/' . $headerVersion;
        else
          $headerVersion = $this::$protoName . '/' . $headerVersion;
      }

      $this->headerVersion = $headerVersion;
    }
    // }}}

    // {{{ getMethod
    /**
     * Retrieve the HTTP-Method if this is a request-header
     *
     * @access public
     * @return string
     * 
     * @throws LogicException
     **/
    public function getMethod (): string
    {
      if (!$this->isRequest())
        throw new LogicException ('Not a request-header');

      return $this->requestMethod;
    }
    // }}}

    // {{{ setMethod
    /**
     * Set the method of a request-header
     *
     * @param string $requestMethod
     *
     * @access public
     * @return bool
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     **/
    public function setMethod (string $requestMethod): void
    {
      if (!$this->isRequest())
        throw new LogicException ('Not a request-header');

      if (!in_array ($requestMethod, $this::$requestMethods))
        throw new InvalidArgumentException ('Unsupported Method');

      $this->requestMethod = $requestMethod;
    }
    // }}}

    // {{{ getURI
    /**
     * Retrieve the request-uri
     *
     * @access public
     * @return string
     *
     * @throws LogicException
     **/
    public function getURI (): string
    {
      if (!$this->isRequest())
        throw new LogicException ('Not a request-header');

      return $this->requestURI;
    }
    // }}}

    // {{{ getURL
    /**
     * Retrieve the URL from this header (only if it is a request)
     *
     * @access public
     * @return string
     * 
     * @throws LogicException
     **/
    public function getURL (): string
    {
      if (!$this->isRequest())
        throw new LogicException ('Not a request-header');

      $versionDelimiter = strrpos ($this->headerVersion, '/');

      if ($versionDelimiter !== false)
        $protocolScheme = strtolower (substr ($this->headerVersion, 0, $versionDelimiter));
      else
        $protocolScheme = $this::$protoName;

      return $protocolScheme . '://' . $this->getField ('Host') . $this->requestURI;
    }
    // }}}

    // {{{ setURL
    /**
     * Setup this header by a given URL
     *
     * @param string|array $requestURI
     *
     * @access public
     * @return void
     *
     * @throws InvalidArgumentException
     * @throws LogicException
     **/
    public function setURL (string|array $requestURI): void
    {
      if (!$this->isRequest())
        throw new LogicException ('Not a request-header');

      // Make sure we have a parsed URL
      if (!is_array ($requestURI)) {
        $requestURI = parse_url ($requestURI);

        if ($requestURI === false)
          throw new InvalidArgumentException ('Failed to parse the URL');
      }

      // Store the URI
      $this->requestURI = ($requestURI ['path'] ?? '/') . (isset ($requestURI ['query']) && ($requestURI ['query'] !== null) ? '?' . $requestURI ['query'] : '');

      // Setup host-entry
      if (
        !isset ($requestURI ['host']) ||
        ($requestURI ['host'] === null)
      ) {
        $this->unsetField ('Host');
        $this->headerVersion = $this::$protoName . '/1.0'; # TODO: This is HTTP
      } else {
        $this->setField ('Host', $requestURI ['host'] . (isset ($requestURI ['port']) && ($requestURI ['port'] !== null) ? ':' . $requestURI ['port'] : ''));
        $this->headerVersion = $this::$protoName . '/1.1'; # TODO: This is HTTP
      }

      // Set credentials (if applicable)
      if (isset ($requestURI ['user']))
        $this->setCredentials (
          urldecode ($requestURI ['user']),
          (isset ($requestURI ['pass']) ? urldecode ($requestURI ['pass']) : '')
        );
    }
    // }}}

    // {{{ setCredentials
    /**
     * Store HTTP-Credentials
     *
     * @param string $authUsername
     * @param string $authPassword
     *
     * @access public
     * @return void
     *
     * @throws LogicException
     **/
    public function setCredentials (string $authUsername, string $authPassword): void
    {
      if (!$this->isRequest())
        throw new LogicException ('Not a request-header');

      $this->setField ('Authorization', 'Basic ' . base64_encode ($authUsername . ':' . $authPassword));
    }
    // }}}

    // {{{ getStatus
    /**
     * Retrieve the status-code from a http-response
     *
     * @access public
     * @return int
     * 
     * @throws LogicException
     **/
    public function getStatus (): int
    {
      if (!$this->isResponse ())
        throw new LogicException ('Not a response-header');

      return $this->responseStatus;
    }
    // }}}

    // {{{ setStatus
    /**
     * Set a new status code for a http-response
     *
     * @param int $responseStatus
     *
     * @access public
     * @return void
     * 
     * @throws LogicException
     **/
    public function setStatus (int $responseStatus): void
    {
      if (!$this->isResponse ())
        throw new LogicException ('Not a response-header');

      $this->responseStatus = $responseStatus;
    }
    // }}}

    // {{{ getMessage
    /**
     * Retrieve the message that was associated with the status-code
     *
     * @access public
     * @return string
     * 
     * @throws LogicException
     **/
    public function getMessage (): string
    {
      if (!$this->isResponse ())
        throw new LogicException ('Not a response-header');

      return $this->responseMessage;
    }
    // }}}

    // {{{ setMessage
    /**
     * Store a message associated with the status-code
     *
     * @param string $responseMessage
     *
     * @access public
     * @return void
     * 
     * @throws LogicException
     **/
    public function setMessage (string $responseMessage): void
    {
      if (!$this->isResponse ())
        throw new LogicException ('Not a response-header');

      $this->responseMessage = $responseMessage;
    }
    // }}}
    
    // {{{ hasField
    /**
     * Check if a field is present on this header
     * 
     * @param string $headerName
     * 
     * @access public
     * @return bool
     **/
    public function hasField (string $headerName): bool
    {
      return isset ($this->headerValues [strtolower ($headerName)]);
    }
    // }}}

    // {{{ getField
    /**
     * Retrieve a field from this header
     *
     * @param string $headerName
     * @param bool $allowMulti (optional)
     *
     * @access public
     * @return string|array|null
     **/
    public function getField (string $headerName, bool $allowMulti = false): string|array|null
    {
      // Retrieve the key for that field
      $headerKey = strtolower ($headerName);

      // Check if the field is present
      if (!isset ($this->headerValues [$headerKey]))
        return ($allowMulti ? [] : null);

      // Collect all values
      $headerValues = [];

      foreach ($this->headerValues [$headerKey] as $headerValue) {
        if (is_array ($headerValue [1]))
          $headerValues = array_merge ($headerValues, $headerValue [1]);
        else
          $headerValues [] = $headerValue [1];

        if (!$allowMulti)
          return array_shift ($headerValues);
      }

      if ($allowMulti)
        return $headerValues;

      return null;
    }
    // }}}

    // {{{ setField
    /**
     * Set the content of a field on this header
     *
     * @param string $headerName
     * @param string|int|float|string[]|int[]|float[] $headerValue
     * @param bool $replaceExisting (optional)
     *
     * @access public
     * @return void
     **/
    public function setField (string $headerName, string|int|float|array $headerValue, bool $replaceExisting = true): void
    {
      // Retrieve the key for that field
      $headerKey = strtolower ($headerName);

      // Store the value
      if ($replaceExisting || !isset ($this->headerValues [$headerKey]))
        $this->headerValues [$headerKey] = [ [ $headerName, $headerValue ] ];
      else
        $this->headerValues [$headerKey][] = [ $headerName, $headerValue ];
    }
    // }}}

    // {{{ unsetField
    /**
     * Remove a field from this header
     *
     * @param string $headerName
     *
     * @access public
     * @return void
     **/
    public function unsetField (string $headerName): void
    {
      unset ($this->headerValues [strtolower ($headerName)]);
    }
    // }}}

    // {{{ getFields
    /**
     * Retrieve all fields from this header
     * 
     * @access public
     * @return array
     **/
    public function getFields (): array
    {
      $headerValues = [];

      foreach ($this->headerValues as $headerLines)
        foreach ($headerLines as $headerValue) {
          if (!isset ($headerValues [$headerValue [0]]))
            $headerValues [$headerValue [0]] = [];

          if (is_array ($headerValue [1]))
            $headerValues [$headerValue [0]] = array_merge (
              $headerValues [$headerValue [0]],
              $headerValue [1]
            );
          else
            $headerValues [$headerValue [0]][] = $headerValue [1];
        }

      return $headerValues;
    }
    // }}}

    // {{{ getAuthenticationInfo
    /**
     * Retrieve parsed information about possible authentication-methods from this header
     *
     * @access public
     * @return array|null
     *
     * @throws LogicException
     **/
    public function getAuthenticationInfo (): ?array
    {
      if (!$this->isResponse ())
        throw new LogicException ('Not a response-header');

      // Check if we have the header available
      if (!$this->hasField ('WWW-Authenticate'))
        return null;

      // Parse all WWW-Authenticate-Headers
      $authenticationInfos = [];

      foreach ($this->getField ('WWW-Authenticate', true) as $wwwAuthenticate) {
        $wwwAuthenticate = trim ($wwwAuthenticate);
        $authScheme = null;
        $authParams = [ ];
        $authParamsLength = strlen ($wwwAuthenticate);

        $parserState = 0; // 0 white-space, 1 token, 2 quoted-string
        $authTokens = [];
        $lastToken = '';

        for ($i = 0; $i <= $authParamsLength; $i++) {
          // Check for end-of-token
          if (
            ($i == $authParamsLength) ||
            (($parserState < 2) && ($wwwAuthenticate [$i] == ','))
          ) {
            // Check whether to push the token
            if ($authTokens === null)
              $authTokens = [];

            if ($parserState != 0)
              $authTokens [] = $lastToken;

            // Make sure we have a scheme
            if ($authScheme === null) {
              if (count ($authTokens) < 1)
                continue;

              $authScheme = array_shift ($authTokens);
            }

            if (count ($authTokens) < 2) {
              // Process a single token
              if (
                (count ($authTokens) == 1) &&
                (strlen ($authTokens [0]) > 0)
               ) {
                if (count ($authParams) !== 0) {
                  if ($authScheme !== null)
                    $authenticationInfos [] = [
                      'scheme' => $authScheme,
                      'params' => $authParams,
                    ];

                  $authScheme = $authTokens [0];
                  $authParams = [];
                  $authTokens = null;
                } else
                  $authParams ['data'] = $authTokens [0];
              }  
            } else
              $authParams [array_shift ($authTokens)] = implode (' ', $authTokens);

            $authTokens = null;
            $lastToken = '';
            $parserState = 0;
          // Check for whitespace
          } elseif (
            ($wwwAuthenticate [$i] == ' ') ||
            ($wwwAuthenticate [$i] == "\t")
          ) {
            // Push white-spaces to quoted strings
            if ($parserState == 2)
              $lastToken .= $wwwAuthenticate [$i];

            // Skip white-space-processing if not parsing a token
            if ($parserState != 1)
              continue;

            // End-of-token
            if (
              ($authScheme === null) ||
              ($authTokens === null)
            ) {
              if ($authScheme !== null)
                $authenticationInfos [] = [
                  'scheme' => $authScheme,
                  'params' => $authParams,
                ];

              $authScheme = $lastToken;
              $authParams = [];
              $authTokens = [];
            } else
              $authTokens [] = $lastToken;

            $parserState = 0;
          // Start a new token
          } elseif ($parserState == 0) {
            if ($wwwAuthenticate [$i] == '"') {
              $lastToken = '';
              $parserState = 2;
            } else {
              $lastToken = $wwwAuthenticate [$i];
              $parserState = 1;
            }
          // Start/End of quoted string
          } elseif ($wwwAuthenticate [$i] == '"') {
            if ($parserState != 2) {
              $lastToken = '';
              $parserState = 2;
            } else {
              $authTokens [] = $lastToken;
              $parserState = 0;
            }
          // Push to token
          } elseif ($parserState == 1) {
            if ($wwwAuthenticate [$i] == '=') {
              // Look for end of token68
              $t68 = false;

              for ($j = $i + 1; $j <= $authParamsLength; $j++)
                if (($j == $authParamsLength) || ($wwwAuthenticate [$j] == ',')) {
                  $t68 = true;
                  break;
                } elseif ($wwwAuthenticate [$j] != '=')
                  break;

              if ($t68) {
                $lastToken .= substr ($wwwAuthenticate, $i, $j - $i);
                $i = $j - 1;
              }

              $authTokens [] = $lastToken;
              $parserState = 0;
            } else
              $lastToken .= $wwwAuthenticate [$i];
          } else
            $lastToken .= $wwwAuthenticate [$i];
        }

        if ($authScheme !== null)
          $authenticationInfos [] = [
            'scheme' => $authScheme,
            'params' => $authParams,
          ];
      }

      // Return the result
      return $authenticationInfos;
    }
    // }}}

    // {{{ hasBody
    /**
     * Check if a body is expected
     *
     * @access public
     * @return bool
     **/
    public function hasBody (): bool
    {
      // Check rules as of RFC 1945 7.2 / RFC 2616 4.3
      if ($this->headerType === self::TYPE_REQUEST)
        return ($this->hasField ('content-length') || $this->hasField ('transfer-encoding'));

      // Decide depending on Status-Code
      $responseStatus = $this->getStatus ();

      # TODO: This does not honor Responses to HEAD-Requests (as we do not have this information here)
      return (
        ($responseStatus > 199) &&
        ($responseStatus != 204) &&
        ($responseStatus != 304)
      );
    }
    // }}}
  }
