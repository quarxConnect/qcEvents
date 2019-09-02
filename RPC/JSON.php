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
      return new qcEvents_Promise (function ($resolve, $reject) use ($Request) {
        $httpRequest = $this->Pool->addNewRequest (
          $this->EndpointURL,
          'POST',
          array (
            'Content-Type' => 'application/json',
            'Connection' => 'close',
          ),
          json_encode ($Request),
          function (qcEvents_Client_HTTP $Pool, qcEvents_Stream_HTTP_Request $Request, qcEvents_Stream_HTTP_Header $Header = null, $Body = null)
          use ($resolve, $reject) {
            // Check for a general error
            if (!$Header || !$Body)
              return $reject (new qcEvents_RPC_JSON_Error (qcEvents_RPC_JSON_Error::CODE_RESPONSE_SERVER_ERROR));
            
            // Make sure the response is JSON
            if ($Header->getField ('Content-Type') !== 'application/json')
              return $reject (new qcEvents_RPC_JSON_Error (qcEvents_RPC_JSON_Error::CODE_RESPONSE_INVALID_CONTENT));
            
            $JSON = json_decode ($Body);
            
            if (($JSON === null) && ($Body != 'null'))
              return $reject (new qcEvents_RPC_JSON_Error (qcEvents_RPC_JSON_Error::CODE_RESPONSE_PARSE_ERROR));
            
            // Sanatize the result
            if (count ($Vars = get_object_vars ($JSON)) != 3)
              return $reject (new qcEvents_RPC_JSON_Error (qcEvents_RPC_JSON_Error::CODE_RESPONSE_INVALID_PARAMS));
            
            if ($this->Version < self::VERSION_2000) {
              // Check for missing key
              if (!array_key_exists ('id', $Vars) || !array_key_exists ('error', $Vars) || !array_key_exists ('result', $Vars))
                return $reject (new qcEvents_RPC_JSON_Error (qcEvents_RPC_JSON_Error::CODE_RESPONSE_MISSING_PARAMS));
              
              // Check for result and error
              if (($JSON->error !== null) && ($JSON->result !== null))
                return $reject (new qcEvents_RPC_JSON_Error (qcEvents_RPC_JSON_Error::CODE_RESPONSE_INVALID_PARAMS));
            } else {
              // Check for missing key
              if (!isset ($JSON->jsonrpc) || !isset ($JSON->id) || !(isset ($JSON->error) || isset ($JSON->result)))
                return $reject (new qcEvents_RPC_JSON_Error (qcEvents_RPC_JSON_Error::CODE_RESPONSE_MISSING_PARAMS));
              
              if ($JSON->jsonrpc != '2.0')
                return $reject (new qcEvents_RPC_JSON_Error (qcEvents_RPC_JSON_Error::CODE_RESPONSE_INVALID_PARAMS));
            }
            
            // Check for an error on the result
            if (isset ($JSON->error) && ($JSON->error !== null))
              return $reject (new qcEvents_RPC_JSON_Error ($JSON->error->code, $JSON->error->message, (isset ($JSON->error->data) ? $JSON->error->data : null)));
            
            // Forward the result
            $resolve ($JSON->result);
          }
        );
        
        // Check wheter to enable opportunistic 
        if ($this->forceOpportunisticAuthentication)
          $httpRequest->addAuthenticationMethod ('Basic', array ());
      });
    }
    // }}}
  }
  
  class qcEvents_RPC_JSON_Error {
    const CODE_PARSE_ERROR = -32700;
    
    const CODE_RESPONSE_SERVER_ERROR = -33000;
    const CODE_RESPONSE_INVALID_CONTENT = -33001;
    const CODE_RESPONSE_PARSE_ERROR = -33700;
    const CODE_RESPONSE_MISSING_PARAMS = -33601;
    const CODE_RESPONSE_INVALID_PARAMS = -33602;
    
    private $code = null;
    private $message = null;
    private $data = null;
    
    function __construct ($code, $message = null, $data = null) {
      $this->code = $code;
      $this->message = $message;
      $this->data = $data;
    }
  }

?>