<?PHP

  /**
   * qcEvents - Representation of an ACME Authorization
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
  require_once ('qcEvents/Vendor/ACME/Authorization/Challenge.php');
  
  class qcEvents_Vendor_ACME_Authorization {
    /* Instance of our ACME-Client */
    private $ACME = null;
    
    /* URI of this authorization-instance */
    private $URI = null;
    
    /* Identifier for this authorization */
    private $Identifier = null;
    
    /* Status of this authorization */
    const STATUS_PENDING = 'pending';
    const STATUS_VALID = 'valid';
    const STATUS_INVALID = 'invalid';
    const STATUS_DEACTIVATED = 'deactivated';
    const STATUS_EXPIRED = 'expired';
    const STATUS_REVOKED = 'revoked';
    
    private $Status = qcEvents_Vendor_ACME_Authorization::STATUS_PENDING;
    
    /* Timestamp when the authorization expires */
    private $Expires = null;
    
    /* List of possible challenges */
    private $Challenges = array ();
    
    /* Wildcard-Indicator */
    private $Wildcard = null;
    
    // {{{ fromJSON
    /**
     * Create/Restore an authorization-instance from JSON
     * 
     * @param qcEvents_Vendor_ACME $ACME
     * @param string $URI
     * @param object $JSON
     * 
     * @access public
     * @return qcEvents_Vendor_ACME_Authorization
     **/
    public static function fromJSON (qcEvents_Vendor_ACME $ACME, $URI, $JSON) {
      $Instance = new static ($ACME, $URI);
      $Instance->Identifier = $JSON->identifier;
      $Instance->Status = $JSON->status;
      
      $Instance->Challenges = array ();
      
      foreach ($JSON->challenges as $Challenge)
        $Instance->Challenges [] = qcEvents_Vendor_ACME_Authorization_Challenge::fromJSON ($ACME, $Challenge);
      
      if (isset ($JSON->expires))
        $Instance->Expires = strtotime ($JSON->expires);
      
      if (isset ($JSON->wildcard))
        $Instance->Wildcard = $JSON->wildcard;
      
      return $Instance;
    }
    // }}}
    
    // {{{ __construct
    /**
     * Create a new ACME-Authorization-Instance
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
        'Identifier' => $this->Identifier,
        'Status' => $this->Status,
        'Challenges' => $this->Challenges,
        'Expires' => $this->Expires,
        'Wildcard' => $this->Wildcard,
      );
    }
    // }}}
    
    // {{{ getChallenges
    /**
     * Retrive a list of all challenges for this authorization
     * 
     * @access public
     * @return array
     **/
    public function getChallenges () {
      return $this->Challenges;
    }
    // }}}
  }

?>