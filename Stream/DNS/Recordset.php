<?PHP

  /**
   * qcEvents - DNS Recordset
   * Copyright (C) 2015-2020 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  class qcEvents_Stream_DNS_Recordset implements IteratorAggregate, ArrayAccess, Countable {
    /* All records on this set */
    private $dnsRecords = array ();
    
    // {{{ __clone
    /**
     * Create a copy of this recordset
     * 
     * @access friendly
     * @return void
     **/
    function __clone () {
      foreach ($this->dnsRecords as $recordIndex=>$dnsRecord)
        $this->dnsRecords [$recordIndex] = clone $dnsRecord;
    }
    // }}}
    
    // {{{ getIterator
    /**
     * Retrive a new iterator for this recordset
     * 
     * @access public
     * @return ArrayIterator
     **/
    public function getIterator () {
      return new ArrayIterator ($this->dnsRecords);
    }
    // }}}
    
    // {{{ offsetExists
    /**
     * Check if a given index exists on our recordset
     * 
     * @param mixed $Index
     * 
     * @access public
     * @return bool
     **/
    public function offsetExists ($Index) {
      return isset ($this->dnsRecords [$Index]);
    }
    // }}}
    
    // {{{ offsetGet
    /**
     * Retrive a single record from this set
     * 
     * @param mixed $Index
     * 
     * @access public
     * @return qcEvents_Stream_DNS_Record
     **/
    public function offsetGet ($Index) {
      if (isset ($this->dnsRecords [$Index]))
        return $this->dnsRecords [$Index];
    }
    // }}}
    
    // {{{ offsetSet
    /**
     * Store a new record on this recrodset
     * 
     * @param mixed $Index
     * @param qcEvents_Stream_DNS_Record $Record
     * 
     * @access public
     * @return void
     **/
    public function offsetSet ($Index, $Record) {
      if (!($Record instanceof qcEvents_Stream_DNS_Record))
        return;
      
      if ($Index === null)
        $this->dnsRecords [] = $Record;
      else
        $this->dnsRecords [$Index] = $Record;
    }
    // }}}
    
    // {{{ offsetUnset
    /**
     * Remove a record from this set
     * 
     * @param mixed $Index
     * 
     * @access public
     * @return void
     **/
    public function offsetUnset ($Index) {
      unset ($this->dnsRecords [$Index]);
    }
    // }}}
    
    // {{{ count
    /**
     * Count all records on this set
     * 
     * @access public
     * @return int
     **/
    public function count () {
      return count ($this->dnsRecords);
    }
    // }}}
    
    // {{{ getRecords
    /**
     * Retrive all records (of a given type) from this set
     * 
     * @param int $Type (optional)
     * 
     * @access public
     * @return qcEvents_Stream_DNS_Recordset
     **/
    public function getRecords ($Type = null) {
      // Check if this is a senseless call
      if ($Type === null)
        return $this;
      
      // Create a cloned result
      $Result = new $this;
      
      // Filter the result
      foreach ($this->dnsRecords as $recordIndex=>$dnsRecord)
        if ($dnsRecord->getType () == $Type)
          $Result [$recordIndex] = $dnsRecord;
      
      return $Result;
    }
    // }}}
    
    // {{{ removeRecord
    /**
     * Remove a record from this recordset
     * 
     * @param qcEvents_Stream_DNS_Record $dnsRecord
     * 
     * @access public
     * @return void
     **/
    public function removeRecord (qcEvents_Stream_DNS_Record $dnsRecord) {
      foreach (array_keys ($this->dnsRecords, $dnsRecord, true) as $recordIndex)
        unset ($this->dnsRecords [$recordIndex]);
    }
    // }}}
    
    // {{{ validate
    /**
     * Try to validate the entire resultset
     * 
     * @param array $Keys Array with available DNS-Keys
     * 
     * @access public
     * @return bool
     **/
    public function validate (array $Keys) {
      // Inline data of IANA DNS Root Signing Public Key Certificate
      static $rootCertificate = <<<EOF
-----BEGIN CERTIFICATE-----
MIIDyjCCArKgAwIBAgIBBzANBgkqhkiG9w0BAQsFADBLMQ4wDAYDVQQKEwVJQ0FO
TjEYMBYGA1UEAxMPSUNBTk4gRE5TU0VDIENBMR8wHQYJKoZIhvcNAQkBExBkbnNz
ZWNAaWNhbm4ub3JnMB4XDTE0MDYxMTE4NDMyMFoXDTE3MDYxMDE4NDMyMFowgbMx
DjAMBgNVBAoTBUlDQU5OMQ0wCwYDVQQLEwRJQU5BMTAwLgYDVQQDEydSb290IFpv
bmUgS1NLIDIwMTAtMDYtMTZUMjE6MTk6MjQrMDA6MDAxYDBeBggrBgEEAYdoNRNS
LiBJTiBEUyAxOTAzNiA4IDIgNDlBQUMxMUQ3QjZGNjQ0NjcwMkU1NEExNjA3Mzcx
NjA3QTFBNDE4NTUyMDBGRDJDRTFDRERFMzJGMjRFOEZCNTCCASIwDQYJKoZIhvcN
AQEBBQADggEPADCCAQoCggEBAKgAIKlVZrpC6Ia7gEzahOR+9W29euxhJhVVLOyQ
bSEW0O8gcCjFFVQUTf6v58fLjwBd0YI0EzrAcQqBGCzh/RStIoO8g0NfnfL2MTJR
kxoXbfDaUeVPQuYEhg37NZWAJQ9VnMVDxP/VHL496M/QZxkjf5/Efucp2gaDX6RS
6CXpoY68LsvPVjR0ZSwzz1apAzvN9dlzEheX7ICJBBtuA6G3LQpzW5hOA2hzCTMj
JPJ8LbqF6dsV6DoBQzgul0sGIcGOYl7OyQdXfZ57relSQageu+ipAdTTJ25AsRTA
oub8ONGcLmqrAmRLKBP1dfwhYB4N7knNnulqQxA+Uk1ihz0CAwEAAaNQME4wDAYD
VR0TAQH/BAIwADAfBgNVHSMEGDAWgBSPskJpw53kPPoTuf/ywKTv2A/oIjAdBgNV
HQ4EFgQUQRqS+htWdh5iK3HNGv27Q5lfCckwDQYJKoZIhvcNAQELBQADggEBALsL
hwY+hXsV6i6UI1ijqrBDP200JYHYIIJn4tTN9kpG+q7feQ52XhzydV3iPRgwV14L
FqHc26ibnT3Afw+bHnvV7vrDOdvejfaw7cE7OGcJK3twQyvQ/XX2EWVr9qk7BE74
CeqVP0ghSY87juF5MGV2Zdz/+IhMPrjFY2kQea615Qkl/TD1x3PxJNoq3YaXRDZu
skVUjpepBhLLECq8Ewi2w03T8xxj4e6/1AAIBh8POpKVoBS1njSqlAz6zK2ZaWmS
OQQBuGpn7e28dDvCeuagqV3qdwdfkbDuLb5DYUobGu2UsnjJ8Vp6E+evCU95p7lJ
N0M89yQUKiyCrj9/lpg=
-----END CERTIFICATE-----
EOF;

      // Make sure DNSSEC-Support is available
      if (!class_exists ('qcEvents_Stream_DNS_Record_RRSIG')) {
        trigger_error ('DNSSEC-Support unavailable');
        
        return false;
      }
      
      // Isolate all signatures
      $Signatures = $this->getRecords (qcEvents_Stream_DNS_Record_RRSIG::DEFAULT_TYPE);
      
      if (count ($Signatures) == 0)
        return null;
      
      // Validate each signature
      foreach ($Signatures as $Signature) {
        // Retrive all Records for that type from this resultset
        $Records = $this->getRecords ($Type = $Signature->getCoveredType ());
        
        if (count ($Records) == 0) {
          trigger_error ('RRSIG present without records to validate');
          
          continue;
        }
        
        // Find a matching key for this
        $sigKeys = array ();
        
        foreach ($Keys as $Key)
          if (($Key instanceof qcEvents_Stream_DNS_Record_DNSKEY) && ($Key->getKeyTag () == $Signature->getKeyTag ()))
            $sigKeys [] = $Key;
        
        if (count ($sigKeys) == 0) {
          foreach ($Records as $Record)
            if (strlen ($Record->getLabel ()) == 1) {
              require_once ('X509/Certificate.php');
              
              $sigKeys [] = @x509_Certificate::fromPEM ($rootCertificate);
              
              break;
            }
          
          if (count ($sigKeys) == 0) {
            trigger_error ('No matching DNS-Key found');
            
            return false;
          }
        }
        
        // Make sure the records are ordered correctly
        $Records->orderCanonical ();
        
        // Build the verify-buffer
        $Labels = array ();
        $vBuffer =
          substr ($Signature->buildPayload (0, $Labels), 0, 18) .
          qcEvents_Stream_DNS_Message::setLabel ($Signature->getSigner ());
        
        $Class = $Signature->getClass ();
        $TTL = $Signature->getOriginalTTL ();
        $Fixed =
          chr (($Type & 0xFF00) >> 8) . chr ($Type & 0xFF) .
          chr (($Class & 0xFF00) >> 8) . chr ($Class & 0xFF) .
          chr (($TTL & 0xFF000000) >> 24) . chr (($TTL & 0x00FF0000) >> 16) .
          chr (($TTL & 0x0000FF00) >>  8) . chr  ($TTL & 0x000000FF);
        
        foreach ($Records as $Record) {
          $Labels = array ();
          $Length = strlen ($Payload = $Record->getPayload (0, $Labels));
          $vBuffer .=
            qcEvents_Stream_DNS_Message::setLabel ($Record->getLabel ()) .
            $Fixed .
            chr (($Length & 0xFF00) >> 8) . chr ($Length & 0xFF) .
            $Payload;
        }
        
        // Try to verify the buffer with all available keys
        foreach ($sigKeys as $Key)
          if ((($Key instanceof qcEvents_Stream_DNS_Record_DNSKEY) && $Key->verifySignature ($vBuffer, $Signature)) ||
              (($Key instanceof X509_Certificate) && $Key->verifySignature ($vBuffer, $Signature->getSignature (), $Signature->getAlgorithmObjectID ())))
            continue (2);
        
        return false;
      }
      
      return true;
    }
    // }}}
    
    // {{{ orderCanonical
    /**
     * Order records on this set in canonical order
     * 
     * @access public
     * @return void
     **/
    public function orderCanonical () {
      usort (
        $this->dnsRecords,
        function (qcEvents_Stream_DNS_Record $Left, qcEvents_Stream_DNS_Record $Right) {
          // Compare labels of records
          $lLen = count ($lLabel = $Left->getLabel ());
          $rLen = count ($rLabel = $Right->getLabel ());
          
          if (($lLen == 0) && ($rLen > 0))
            return -1;
          
          elseif (($rLen == 0) && ($lLen > 0))
            return 1;
          
          while (($lLen-- > 0) && ($rLen-- > 0)) {
            if (($r = strcasecmp ($lLabel [$lLen], $rLabel [$rLen])) != 0)
              return $r;
          }
          
          // Compare the type
          if (($d = ($Left->getType () - $Right->getType ())) != 0)
            return $d;
          
          // Compare the class
          if (($d = ($Left->getClass () - $Right->getClass ())) != 0)
            return $d;
          
          return strcmp ($Left->getPayload (), $Right->getPayload ());
        }
      );
    }
    // }}}
    
    // {{{ pop
    /**
     * Return and remove the last element from this recordset
     * 
     * @access public
     * @return qcEvents_Stream_DNS_Record
     **/
    public function pop () {
      return array_pop ($this->dnsRecords);
    }
    // }}}
    
    // {{{ unshift
    /**
     * Push records back to this recordset
     * 
     * @access public
     * @return void
     **/
    public function unshift () {
      $dnsRecords = array ($this->dnsRecords);
      
      foreach (func_get_args () as $dnsRecord)
        if ($dnsRecord instanceof qcEvents_Stream_DNS_Record)
          $dnsRecords [] = $dnsRecord;
      
      if (count ($dnsRecords) > 1)
        call_user_func_array ('unshift', $dnsRecords);
    }
    // }}}
    
    // {{{ clear
    /**
     * Remove all records from this recordset
     * 
     * @access public
     * @return void
     **/
    public function clear () {
      $this->dnsRecords = array ();
    }
    // }}}
  }

?>