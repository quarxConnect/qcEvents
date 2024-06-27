<?php

  /**
   * qcEvents - Representation of an ACME Authorization
   * Copyright (C) 2019-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  namespace quarxConnect\Events\Vendor\ACME;
  use \quarxConnect\Events;
  
  class Authorization {
    /* Instance of our ACME-Client */
    private $ACME = null;
    
    /* URI of this authorization-instance */
    private $URI = null;
    
    /* Identifier for this authorization */
    private $Identifier = null;
    
    /* Status of this authorization */
    public const STATUS_PENDING = 'pending';
    public const STATUS_VALID = 'valid';
    public const STATUS_INVALID = 'invalid';
    public const STATUS_DEACTIVATED = 'deactivated';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';
    
    private $Status = Authorization::STATUS_PENDING;
    
    /* Timestamp when the authorization expires */
    private $Expires = null;
    
    /* List of possible challenges */
    private $Challenges = [ ];
    
    /* Wildcard-Indicator */
    private $Wildcard = null;
    
    // {{{ fromJSON
    /**
     * Create/Restore an authorization-instance from JSON
     * 
     * @param Events\Vendor\ACME $ACME
     * @param string $URI
     * @param object $JSON
     * 
     * @access public
     * @return Authorization
     **/
    public static function fromJSON (Events\Vendor\ACME $ACME, string $URI, object $JSON): Authorization
    {
      $Instance = new Authorization ($ACME, $URI);
      $Instance->Identifier = $JSON->identifier;
      $Instance->Status = $JSON->status;
      
      $Instance->Challenges = [ ];
      
      foreach ($JSON->challenges as $Challenge)
        $Instance->Challenges [] = Authorization\Challenge::fromJSON ($ACME, $Challenge);
      
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
     * @param Events\Vendor\ACME $ACME
     * @param string $URI
     * 
     * @access friendly
     * @return void
     **/
    function __construct (Events\Vendor\ACME $ACME, $URI) {
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
    function __debugInfo () : array {
      return [
        'Identifier' => $this->Identifier,
        'Status' => $this->Status,
        'Challenges' => $this->Challenges,
        'Expires' => $this->Expires,
        'Wildcard' => $this->Wildcard,
      ];
    }
    // }}}
    
    // {{{ getChallenges
    /**
     * Retrive a list of all challenges for this authorization
     * 
     * @access public
     * @return array
     **/
    public function getChallenges () : array {
      return $this->Challenges;
    }
    // }}}
  }
