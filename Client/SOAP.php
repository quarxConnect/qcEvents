<?PHP

  /**
   * qcEvents - Dumb SOAP Client Implementation
   * Copyright (C) 2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  namespace quarxConnect\Events\Client;
  
  if (!extension_loaded ('soap') && (!function_exists ('dl') || !dl ('soap.so'))) {
    trigger_error ('SOAP-Extension not present', E_USER_ERROR);
    
    return;
  }
  
  require_once ('qcEvents/Client/HTTP.php');
  require_once ('qcEvents/File.php');
  require_once ('qcEvents/Synchronizer.php');
  
  class SOAP {
    /* Instance of our http-client */
    private $httpClient = null;
    
    /* Instance of our soap-client */
    private $soapClient = null;
    
    private $lastSoapCall = null;
    
    // {{{ __construct
    /**
     * Create a new half-asynchronour SOAP-Client
     * 
     * @param \qcEvents_Base $eventBase
     * 
     * @access friendly
     * @return void
     **/
    function __construct (\qcEvents_Base $eventBase) {
      // Create a new http-client
      $this->httpClient = new \qcEvents_Client_HTTP ($eventBase);
      
      // Create a dummy-promise
      $this->lastSoapCall = \qcEvents_Promise::resolve ();
    }
    // }}}
    
    // {{{ __call
    /**
     * Invoke a SOAP-Function
     * 
     * @param string $functionName
     * @param array $functionArguments
     * 
     * @access friendly
     * @return \qcEvents_Promise
     **/
    function __call ($functionName, array $functionArguments) : \qcEvents_Promise {
      $this->lastSoapCall = $this->lastSoapCall->catch (
        function () { }
      )->then (
        function () use ($functionName, $functionArguments) {
          return new \qcEvents_Promise (
            function (callable $resolveFunction, callable $rejectFunction)
            use ($functionName, $functionArguments) {
              try {
                $result = call_user_func_array ([ $this->soapClient, $functionName], $functionArguments);
                
                $resolveFunction ($result);
              } catch (Throwable $error) {
                $rejectFunction ($error);
              }
            },
            $this->httpClient->getEventBase ()
          );
        }
      );
      
      return $this->lastSoapCall;
    }
    // }}}
    
    // {{{ loadWSDL
    /**
     * Load a WSDL for this client from a remote source
     * 
     * @param string $wsdlURL
     * @param array $soapOptions (otpional)
     * 
     * @access public
     * @return \qcEvents_Promise
     **/
    public function loadWSDL ($wsdlURL, array $soapOptions = null) : \qcEvents_Promise {
      $wsdlFile = sys_get_temp_dir () . '/qcevents-soap-' . sha1 ($wsdlURL) . '.xml';
      
      if (is_file ($wsdlFile))
        return $this->loadWSDLFile ($wsdlFile);
      
      return $this->httpClient->request ($wsdlURL)->then (
        function ($responseBody) use ($wsdlFile) {
          return \qcEvents_File::writeFileContents (
            $this->httpClient->getEventBase (), 
            $wsdlFile,
            $responseBody
          );
        }
      )->then (
        function () use ($wsdlFile, $soapOptions) {
          return $this->loadWSDLFile ($wsdlFile, $soapOptions);
        }
      );
    }
    // }}}
    
    // {{{ loadWSDLFile
    /**
     * Load a WSDL from a local file
     * 
     * @param string $wsdlFile
     * 
     * @access public
     * @return \qcEvents_Promise
     **/
    public function loadWSDLFile ($wsdlFile, array $soapOptions = null) : \qcEvents_Promise {
      // Make sure the file is there
      if (!is_file ($wsdlFile))
        return \qcEvents_Promise::reject ('File not found');
      
      // Create a new SOAP-Client
      $this->soapClient = new __SOAP ($this->httpClient, $wsdlFile, $soapOptions);
      
      return \qcEvents_Promise::resolve ();
    }
    // }}}
    
    
  }
  
  // Don't use this!
  class __SOAP extends \SoapClient {
    /* Set of last request-headers */
    private $lastRequestHeaders = array ();
    
    /* Instance of our http-client */
    private $httpClient = null;
    
    /* SOAP-Options */
    private $soapOptions = null;
    
    // {{{ __construct
    /**
     * Create new SOAP-Client
     * 
     * @param \qcEvents_Client_HTTP $httpClient
     * @param string $wsdlFile
     * 
     * @access friendly
     * @return void
     **/
    function __construct (\qcEvents_Client_HTTP $httpClient, $wsdlFile, array $soapOptions = null) {
      $this->httpClient = $httpClient;
      $this->soapOptions = $soapOptions ?? array ();
      
      parent::__construct ($wsdlFile, $this->soapOptions);
    }
    // }}}
    
    // {{{ __doRequest
    /**
     * Issue the SOAP-Request to the remote party
     * 
     * @param string $soapRequest
     * @param string $soapLocation
     * @param string $soapAction
     * 
     * @access public
     * @return string
     **/
    public function __doRequest ($soapRequest, $soapLocation, $soapAction, $soapVersion, $soapOneWay = false) {
      // Store last used headers
      $this->lastRequestHeaders = array (
        'Content-Type: text/xml; charset=utf-8',
        'SOAPAction: "' . $soapAction . '"',
      );
      
      // Check wheter to modify the URL
      if (isset ($this->soapOptions ['login'])) {
        // Parse the URL
        $soapURL = parse_url ($soapLocation);
        
        // Rewrite required parts
        $soapURL ['user'] = $this->soapOptions ['login'];
        
        if (isset ($this->soapOptions ['password']))
          $soapURL ['pass'] = $this->soapOptions ['password'];
        elseif (isset ($soapURL ['pass']))
          $soapURL ['pass'] = rawurldecode ($soapURL ['pass']);
        
        // Rebuild the URL
        $soapLocation =
          ($soapURL ['scheme'] ?? 'http') . '://' .
          (isset ($soapURL ['user']) ? rawurlencode ($soapURL ['user']) . (isset ($soapURL ['pass']) ? ':' . rawurlencode ($soapURL ['pass']) . '@' : '') : '') .
          $soapURL ['host'] . (isset ($soapURL ['port']) ? ':' . $soapURL ['port'] : '') .
          ($soapURL ['path'] ?? '/') .
          (isset ($soapURL ['query']) ? '?' . $soapURL ['query'] : '');
      }
      
      // Do the http-request in some synchronized style
      $responseBody = qcEvents_Synchronizer (
        $this->httpClient,
        'request',
        $soapLocation,
        'POST',
        array (
          'Content-Type' => 'text/xml; charset=utf-8',
          'SOAPAction' => '"' . $soapAction . '"',
        ),
        $soapRequest
      );
      
      // Forward the result
      return $responseBody;
    }
    // }}}
    
    // {{{ __getLastRequestHeaders
    /**
     * Retrive last used request-headers
     * 
     * @access public
     * @return string
     **/
    public function __getLastRequestHeaders () {
      return implode ("\n", $this->lastRequestHeaders) . "\n";
    }
    // }}}
  }

?>