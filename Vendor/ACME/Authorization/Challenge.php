<?PHP

  /**
   * qcEvents - Single Challenge of an ACME Authorization
   * Copyright (C) 2019 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Promise.php');
  
  class qcEvents_Vendor_ACME_Authorization_Challenge {
    /* Instance of our ACME-Client */
    private $ACME = null;
    
    /* URI of this authorization-challenge */
    private $URI = null;
    
    /* Type of this challenge */
    const TYPE_TLS_ALPN = 'tls-alpn-01';
    const TYPE_HTTP = 'http-01';
    const TYPE_DNS = 'dns-01';
    
    private $Type = null;
    
    /* Status of this challenge */
    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_VALID = 'valid';
    const STATUS_INVALID = 'invalid';
    
    private $Status = qcEvents_Vendor_ACME_Authorization_Challenge::STATUS_PENDING;
    
    /* Challenge was validated */
    private $Validated = null;
    
    /* Error that occured while validating */
    private $Error = null;
    
    /* Token for HTTP- and DNS-Challenges */
    private $Token = null;
    
    // {{{ fromJSON
    /**
     * Create/Restore a challenge from JSON
     * 
     * @param qcEvents_Vendor_ACME $ACME
     * @param object $JSON
     * 
     * @access public
     * @return qcEvents_Vendor_ACME_Authorization_Challenge
     **/
    public static function fromJSON (qcEvents_Vendor_ACME $ACME, $JSON) {
      $Instance = new static ($ACME, $JSON->url);
      $Instance->Type = $JSON->type;
      $Instance->Status = $JSON->status;
      
      if (isset ($JSON->token))
        $Instance->Token = $JSON->token;
      
      if (isset ($JSON->validated))
        $Instnace->Validated = $JSON->validated;
      
      if (isset ($JSON->error))
        $Instance->Error = $JSON->error;
      
      return $Instance;
    }
    // }}}
    
    // {{{ __construct
    /**
     * Create a new instance of an authorization-challenge
     * 
     * @param qcEvents_Vendor_ACME $ACME
     * @param string $URI
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Vendor_ACME $ACME, $URI) {
      $this->ACME = $ACME;
      $this->URI = $URI;
    }
    // }}}
    
    // {{{ __debugInfo
    /**
     * Prepare output of this object for var_dump()
     * 
     * @access friendly
     * @return array
     **/
    function __debugInfo () {
      return array (
        'URI' => $this->URI,
        'Type' => $this->Type,
        'Status' => $this->Status,
        'Token' => $this->Token,
        'Validated' => $this->Validated,
        'Error' => $this->Error,
      );
    }
    // }}}
    
    // {{{ isType
    /**
     * Check if this challenge is of a given type
     * 
     * @param string $Type
     * 
     * @access public
     * @return bool
     **/
    public function isType ($Type) {
      return (strcasecmp ($this->Type, $Type) == 0);
    }
    // }}}
    
    // {{{ getToken
    /**
     * Retrive the token from this challenge
     * 
     * @access public
     * @return string
     **/
    public function getToken () {
      return $this->Token;
    }
    // }}}
    
    // {{{ getKeyAuthorization
    /**
     * Derive a key-authorization for this challenge
     * 
     * @access public
     * @return string
     **/
    public function getKeyAuthorization () {
      return $this->ACME->getKeyAuthorization ($this->Token);
    }
    // }}}
    
    // {{{ activate
    /**
     * Try to activate this challenge
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function activate () : qcEvents_Promise {
      return $this->ACME->request ($this->URI, true)->then (
        function () {
          // Response contains our JSON
          return true;
        }
      );
    }
    // }}}
  }

?>