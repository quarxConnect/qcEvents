<?php

  /**
   * quarxConnect Events - Client for JSON-RPC
   * Copyright (C) 2018-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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

  namespace quarxConnect\Events\Client;
  use quarxConnect\Events;
  
  class JSONRPC extends Events\Hookable {
    /* Version of this RPC-Client */
    public const VERSION_1000 = 1000;
    public const VERSION_2000 = 2000;
    
    private $Version = JSONRPC::VERSION_2000;
    
    /* Assigned HTTP-Pool */
    private $Pool = null;
    
    /* URL of our endpoint */
    private $EndpointURL = null;
    
    /* Use opportunistic authentication */
    private $forceOpportunisticAuthentication = false;
    
    // {{{ __construct
    /**
     * Create a new JSON-RPC-Client
     * 
     * @param HTTP $Pool
     * @param string $EndpointURL
     * @param int $Version (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (HTTP $Pool, $EndpointURL, $Version = JSONRPC::VERSION_2000) {
      $this->Version = $Version;
      $this->Pool = $Pool;
      $this->EndpointURL = $EndpointURL;
    }
    // }}}
    
    // {{{ useOpportunisticAuthentication
    /**
     * Control wheter to use opportunistic authentication
     * 
     * @param bool $Toggle (optional)
     * 
     * @access public
     * @return void
     **/
    public function useOpportunisticAuthentication ($Toggle = true) {
      $this->forceOpportunisticAuthentication = !!$Toggle;
    }
    // }}}
    
    // {{{ request
    /**
     * Issue a JSON-RPC-Request
     * 
     * @param string $Method
     * @param ...
     * 
     * @access public
     * @return Events\Promise
     **/
    public function request ($Method) : Events\Promise {
      // Process arguements
      $Args = func_get_args ();
      $Method = array_shift ($Args);
      
      // Prepare the request
      $Request = [
        'jsonrpc' => ($this->Version == self::VERSION_2000 ? '2.0' : '1.0'),
        'id' => strval (microtime (true)),
        'method' => $Method,
        'params' => $Args,
      ];
      
      if ($this->Version < self::VERSION_2000)
        unset ($Request ['jsonrpc']);
      elseif (count ($Args) < 1)
        unset ($Request ['params']);
      
      // Try to run the request
      return $this->Pool->request (
        $this->EndpointURL,
        'POST',
        [
          'Content-Type' => 'application/json',
          'Connection' => 'close',
        ],
        json_encode ($Request),
        !$this->forceOpportunisticAuthentication
      )->then (
        function ($responseBody, Events\Stream\HTTP\Header $responseHeader, Events\Stream\HTTP\Request $lastRequest) {
          // Check for server-error
          static $statusCodeMap = [
            401 => JSONRPC\Error::CODE_RESPONSE_INVALID_AUTH,
            404 => JSONRPC\Error::CODE_RESPONSE_METHOD_NOT_FOUND,
          ];
          
          if ($responseHeader->isError ())
            throw new JSONRPC\Error ($statusCodeMap [$responseHeader->getStatus ()] ?? JSONRPC\Error::CODE_RESPONSE_SERVER_ERROR);
          
          // Make sure the response is JSON
          if ($responseHeader->getField ('Content-Type') !== 'application/json')
            throw new JSONRPC\Error (JSONRPC\Error::CODE_RESPONSE_INVALID_CONTENT);
          
          // Try to decode the response
          $responseJSON = json_decode ($responseBody);
          
          if (($responseJSON === null) && ($responseBody != 'null'))
            throw new JSONRPC\Error (JSONRPC\Error::CODE_PARSE_ERROR);
          
          // Sanatize the result
          if (count ($responseAttributes = get_object_vars ($responseJSON)) != 3)
            throw new JSONRPC\Error (JSONRPC\Error::CODE_RESPONSE_INVALID_PARAMS);
          
          if ($this->Version < self::VERSION_2000) {
            // Check for missing key
            if (!array_key_exists ('id', $responseAttributes) || !array_key_exists ('error', $responseAttributes) || !array_key_exists ('result', $responseAttributes))
              throw new JSONRPC\Error (JSONRPC\Error::CODE_RESPONSE_MISSING_PARAMS);
            
            // Check for result and error
            if (($responseJSON->error !== null) && ($responseJSON->result !== null))
              throw new JSONRPC\Error (JSONRPC\Error::CODE_RESPONSE_INVALID_PARAMS);
          } else {
            // Check for missing key
            if (!isset ($responseJSON->jsonrpc) || !isset ($responseJSON->id) || !(isset ($responseJSON->error) || isset ($responseJSON->result)))
              throw new JSONRPC\Error (JSONRPC\Error::CODE_RESPONSE_MISSING_PARAMS);
            
            if ($responseJSON->jsonrpc != '2.0')
              throw new JSONRPC\Error (JSONRPC_Error::CODE_RESPONSE_INVALID_PARAMS);
          }
          
          // Check for an error on the result
          if (isset ($responseJSON->error) && ($responseJSON->error !== null))
            throw new JSONRPC\Error ($responseJSON->error->code, $responseJSON->error->message, (isset ($responseJSON->error->data) ? $responseJSON->error->data : null));
          
          // Forward the result
          return $responseJSON->result;
        },
        function () {
          throw new JSONRPC\Error (JSONRPC\Error::CODE_RESPONSE_SERVER_ERROR);
        }
      );
    }
    // }}}
  }
