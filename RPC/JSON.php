<?PHP

  /**
   * qcEvents - Client for JSON-RPC
   * Copyright (C) 2018 Bernd Holzmueller <bernd@quarxconnect.de>
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
  require_once ('qcEvents/Promise.php');
  
  class qcEvents_RPC_JSON extends qcEvents_Hookable {
    /* Version of this RPC-Client */
    const VERSION_1000 = 1000;
    const VERSION_2000 = 2000;
    
    private $Version = qcEvents_RPC_JSON::VERSION_2000;
    
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
     * @param qcEvents_Client_HTTP $Pool
     * @param string $EndpointURL
     * @param int $Version (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Client_HTTP $Pool, $EndpointURL, $Version = qcEvents_RPC_JSON::VERSION_2000) {
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
     * @return qcEvents_Promise
     **/
    public function request ($Method) : qcEvents_Promise {
      // Process arguements
      $Args = func_get_args ();
      $Method = array_shift ($Args);
      
      // Prepare the request
      $Request = array (
        'jsonrpc' => ($this->Version == self::VERSION_2000 ? '2.0' : '1.0'),
        'id' => strval (microtime (true)),
        'method' => $Method,
        'params' => $Args,
      );
      
      if ($this->Version < self::VERSION_2000)
        unset ($Request ['jsonrpc']);
      elseif (count ($Args) < 1)
        unset ($Request ['params']);
      
      // Try to run the request
      return $this->Pool->request (
        $this->EndpointURL,
        'POST',
        array (
          'Content-Type' => 'application/json',
          'Connection' => 'close',
        ),
        json_encode ($Request),
        !$this->forceOpportunisticAuthentication
      )->then (
        function ($responseBody, qcEvents_Stream_HTTP_Header $responseHeader, qcEvents_Stream_HTTP_Request $lastRequest) {
          // Check for server-error
          static $statusCodeMap = array (
            401 => qcEvents_RPC_JSON_Error::CODE_RESPONSE_INVALID_AUTH,
            404 => qcEvents_RPC_JSON_Error::CODE_RESPONSE_METHOD_NOT_FOUND,
          );
          
          if ($responseHeader->isError ())
            throw new qcEvents_RPC_JSON_Error ($statusCodeMap [$responseHeader->getStatus ()] ?? qcEvents_RPC_JSON_Error::CODE_RESPONSE_SERVER_ERROR);
          
          // Make sure the response is JSON
          if ($responseHeader->getField ('Content-Type') !== 'application/json')
            throw new qcEvents_RPC_JSON_Error (qcEvents_RPC_JSON_Error::CODE_RESPONSE_INVALID_CONTENT);
          
          // Try to decode the response
          $responseJSON = json_decode ($responseBody);
          
          if (($responseJSON === null) && ($responseBody != 'null'))
            throw new qcEvents_RPC_JSON_Error (qcEvents_RPC_JSON_Error::CODE_PARSE_ERROR);
          
          // Sanatize the result
          if (count ($responseAttributes = get_object_vars ($responseJSON)) != 3)
            throw new qcEvents_RPC_JSON_Error (qcEvents_RPC_JSON_Error::CODE_RESPONSE_INVALID_PARAMS);
          
          if ($this->Version < self::VERSION_2000) {
            // Check for missing key
            if (!array_key_exists ('id', $responseAttributes) || !array_key_exists ('error', $responseAttributes) || !array_key_exists ('result', $responseAttributes))
              throw new qcEvents_RPC_JSON_Error (qcEvents_RPC_JSON_Error::CODE_RESPONSE_MISSING_PARAMS);
            
            // Check for result and error
            if (($responseJSON->error !== null) && ($responseJSON->result !== null))
              throw new qcEvents_RPC_JSON_Error (qcEvents_RPC_JSON_Error::CODE_RESPONSE_INVALID_PARAMS);
          } else {
            // Check for missing key
            if (!isset ($responseJSON->jsonrpc) || !isset ($responseJSON->id) || !(isset ($responseJSON->error) || isset ($responseJSON->result)))
              throw new qcEvents_RPC_JSON_Error (qcEvents_RPC_JSON_Error::CODE_RESPONSE_MISSING_PARAMS);
            
            if ($responseJSON->jsonrpc != '2.0')
              throw new qcEvents_RPC_JSON_Error (qcEvents_RPC_JSON_Error::CODE_RESPONSE_INVALID_PARAMS);
          }
          
          // Check for an error on the result
          if (isset ($responseJSON->error) && ($responseJSON->error !== null))
            throw new qcEvents_RPC_JSON_Error ($responseJSON->error->code, $responseJSON->error->message, (isset ($responseJSON->error->data) ? $responseJSON->error->data : null));
          
          // Forward the result
          return $responseJSON->result;
        },
        function () {
          throw new qcEvents_RPC_JSON_Error (qcEvents_RPC_JSON_Error::CODE_RESPONSE_SERVER_ERROR);
        }
      );
    }
    // }}}
  }
  
  class qcEvents_RPC_JSON_Error extends Exception {
    const CODE_PARSE_ERROR = -32700;
    
    const CODE_RESPONSE_SERVER_ERROR = -32000;
    const CODE_RESPONSE_INVALID_CONTENT = -32001;
    const CODE_RESPONSE_INVALID_AUTH = -32099;
    
    const CODE_RESPONSE_INVALID_REQUEST = -32600;
    const CODE_RESPONSE_METHOD_NOT_FOUND = -32601;
    const CODE_RESPONSE_INVALID_PARAMS = -32602;
    const CODE_RESPONSE_INTERNAL_ERROR = -32603;
    const CODE_RESPONSE_MISSING_PARAMS = -33601;
    
    private $data = null;
    
    function __construct ($code, $message = null, $data = null) {
      $this->data = $data;
      
      return parent::__construct ($message, $code);
    }
  }

?>