<?PHP

  /**
   * qcEvents - DNS Answer Record
   * Copyright (C) 2013 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Stream/DNS/Message.php');
  
  class qcEvents_Stream_DNS_Record {
    /**
     * [QName] The Label that is asked for
     **/
    private $Label = '';
    
    /**
     * [QType] Type of DNS-RRs that is asked for
     **/
    private $Type = qcEvents_Stream_DNS_Message::TYPE_ANY;
    
    /**
     * [QClass] Class of DNS-RRs that is asked for
     **/
    private $Class = qcEvents_Stream_DNS_Message::CLASS_INTERNET;
    
    /**
     * [TTL] Time-to-live of this DNS-RR
     **/
    private $TTL = 0;
    
    /**
     * [RData] Payload of this DNS-RR
     **/
    public $Payload = '';
    
    /* Any address assigned to this record (IPv4/IPv6) */
    private $Address = null;
    
    /* Any hostname assigned to this record */
    private $Hostname = null;
    
    /* Priority of this record */
    private $Priority = null;
    
    /* Weight of this record */
    private $Weight = null;
    
    /* Any Port assigned to this record */
    private $Port = null;
    
    // {{{ getLabel
    /**
     * Retrive the label of this record
     * 
     * @access public
     * @return string
     **/
    public function getLabel () {
      return $this->Label;
    }
    // }}}
    
    // {{{ getType
    /**
     * Retrive the type of this record
     * 
     * @access public
     * @return enum
     **/
    public function getType () {
      return $this->Type;
    }
    // }}}
    
    // {{{ getClass
    /**
     * Retrive the class of this record
     * 
     * @access public
     * @return enum
     **/
    public function getClass () {
      return $this->Class;
    }
    // }}}
    
    // {{{ getTTL
    /**
     * Retrive the time-to-live of this record
     * 
     * @access public
     * @return int
     **/
    public function getTTL () {
      return $this->TTL;
    }
    // }}}
    
    // {{{ getPayload
    /**
     * Retrive the entire payload-blob of this record
     * 
     * @access public
     * @return string
     **/
    public function getPayload () {
      return $this->Payload;
    }
    // }}}
    
    // {{{ getAddress
    /**
     * Retrive any address assigned to this record
     * 
     * @access public
     * @return string
     **/
    public function getAddress () {
      return $this->Address;
    }
    // }}}
    
    // {{{ getHostname
    /**
     * Retrive any hostname assigned to this record
     * 
     * @access public
     * @return string
     **/
    public function getHostname () {
      return $this->Hostname;
    }
    // }}}
    
    // {{{ getPriority
    /**
     * Retrive a priority assigned to this record
     * 
     * @access public
     * @return int
     **/
    public function getPriority () {
      return $this->Priority;
    }
    // }}}
    
    // {{{ getWeight
    /**
     * Retrive the weight of this record
     * 
     * @access public
     * @return int
     **/
    public function getWeight () {
      return $this->Weight;
    }
    // }}}
    
    // {{{ getPort
    /**
     * Retrive a port assigned to this record
     * 
     * @access public
     * @return int
     **/
    public function getPort () {
      return $this->Port;
    }
    // }}}
    
    
    // {{{ parse
    /**
     * Parse binary data into this object
     * 
     * @param string $Data
     * @param int $Offset
     * 
     * @access public
     * @return bool
     **/
    public function parse ($Data, &$Offset) {
      // Retrive the label of this record
      $this->Label = qcEvents_Stream_DNS_Message::getLabel ($Data, $Offset);
      
      // Retrive type, class and TTL
      $this->Type = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      $this->Class = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      $this->TTL = (ord ($Data [$Offset++]) << 24) + (ord ($Data [$Offset++]) << 16) +
                   (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      
      // Retrive the payload
      $rdLength = (ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]);
      $this->Payload = substr ($Data, $Offset, $rdLength);
      $pOffset = $Offset;
      $Offset += $rdLength;
      
      // Handle the payload
      switch ($this->Type) {
        // IPv4-Addresses
        case qcEvents_Stream_DNS_Message::TYPE_WKS:
          # TODO: Address <8-bit protocol> <Bitmask>
        case qcEvents_Stream_DNS_Message::TYPE_A:
          $this->Address = ord ($this->Payload [0]) . '.' . ord ($this->Payload [1]) . '.' . ord ($this->Payload [2]) . '.' . ord ($this->Payload [3]);
          break;
        
        // IPv6-Addresses
        case qcEvents_Stream_DNS_Message::TYPE_AAAA:
          $this->Address = '[' . bin2hex (substr ($this->Payload,  0, 2)) . ':' .
                                 bin2hex (substr ($this->Payload,  2, 2)) . ':' .
                                 bin2hex (substr ($this->Payload,  4, 2)) . ':' .
                                 bin2hex (substr ($this->Payload,  6, 2)) . ':' .
                                 bin2hex (substr ($this->Payload,  8, 2)) . ':' .
                                 bin2hex (substr ($this->Payload, 10, 2)) . ':' .
                                 bin2hex (substr ($this->Payload, 12, 2)) . ':' .
                                 bin2hex (substr ($this->Payload, 14, 2)) . ']';
          break;
        
        // Hostnames
        case qcEvents_Stream_DNS_Message::TYPE_CNAME:
        case qcEvents_Stream_DNS_Message::TYPE_MB:
        case qcEvents_Stream_DNS_Message::TYPE_MD:
        case qcEvents_Stream_DNS_Message::TYPE_MF:
        case qcEvents_Stream_DNS_Message::TYPE_MG:
        case qcEvents_Stream_DNS_Message::TYPE_MR:
        case qcEvents_Stream_DNS_Message::TYPE_NS:
        case qcEvents_Stream_DNS_Message::TYPE_PTR:
          $this->Hostname = qcEvents_Stream_DNS_Message::getLabel ($Data, $pOffset);
          break;
        
        // Hostname with preference prefixed
        case qcEvents_Stream_DNS_Message::TYPE_MX:
          $pOffset += 2;
          
          $this->Priority = (ord ($this->Payload [0]) << 8) + ord ($this->Payload [1]);
          $this->Hostname = qcEvents_Stream_DNS_Message::getLabel ($Data, $pOffset);
          
          break;
        case qcEvents_Stream_DNS_Message::TYPE_SRV:
          $pOffset += 6;
          
          $this->Priority = (ord ($this->Payload [0]) << 8) + ord ($this->Payload [1]);
          $this->Weight   = (ord ($this->Payload [2]) << 8) + ord ($this->Payload [3]);
          $this->Port     = (ord ($this->Payload [4]) << 8) + ord ($this->Payload [5]);
          
          $this->Hostname = qcEvents_Stream_DNS_Message::getLabel ($Data, $pOffset);
          
          break;
        
        // Start-of-Authority
        case qcEvents_Stream_DNS_Message::TYPE_SOA:
          #$PrimaryNS = qcEvents_Stream_DNS_Message::getLabel ($Data, $pOffset);
          #$Mailbox = qcEvents_Stream_DNS_Message::getLabel ($Data, $pOffset);
          #$Serial = (ord ($Data [$pOffset]) << 24) + (ord ($Data [$pOffset + 1]) << 16) + (ord ($Data [$pOffset + 2]) << 8) + ord ($Data [$pOffset + 3]);
          #$Refresh = (ord ($Data [$pOffset + 4]) << 24) + (ord ($Data [$pOffset + 5]) << 16) + (ord ($Data [$pOffset + 6]) << 8) + ord ($Data [$pOffset + 7]);
          #$Retry = (ord ($Data [$pOffset + 8]) << 24) + (ord ($Data [$pOffset + 9]) << 16) + (ord ($Data [$pOffset + 10]) << 8) + ord ($Data [$pOffset + 11]);
          #$Expire = (ord ($Data [$pOffset + 12]) << 24) + (ord ($Data [$pOffset + 13]) << 16) + (ord ($Data [$pOffset + 14]) << 8) + ord ($Data [$pOffset + 15]);
          #$Minimum = (ord ($Data [$pOffset + 16]) << 24) + (ord ($Data [$pOffset + 17]) << 16) + (ord ($Data [$pOffset + 18]) << 8) + ord ($Data [$pOffset + 19]);
          
          #break;
        
        // Two hostnames
        case qcEvents_Stream_DNS_Message::TYPE_MINFO:
          #$this->Mailbox = qcEvents_Stream_DNS_Message::getLabel ($Data, $pOffset);
          #$this->errorMailbox = qcEvents_Stream_DNS_Message::getLabel ($Data, $pOffset);
          
          #break;
        
        // Specials
        case qcEvents_Stream_DNS_Message::TYPE_HINFO:
          // CPU / OS Character-Strings
        
        case qcEvents_Stream_DNS_Message::TYPE_TXT:
        case qcEvents_Stream_DNS_Message::TYPE_NULL:
          break;
      }
      
      return true;
    }
    // }}}
    
    // {{{ toString
    /**
     * Convert this question into a string
     * 
     * @param int $Offset
     * @param array &$Labels
     * 
     * @access public
     * @return string
     **/
    public function toString ($Offset, &$Labels) {
      // Create the record-header
      $Data =
        qcEvents_Stream_DNS_Message::setLabel ($this->Label, $Offset, $Labels) .
        chr (($this->Type & 0xFF00) >> 8) . chr ($this->Type & 0xFF) .
        chr (($this->Class & 0xFF00) >> 8) . chr ($this->Class & 0xFF) .
        chr (($this->TTL & 0xFF000000) >> 24) . chr (($this->TTL & 0xFF0000) >> 16) .
        chr (($this->TTL & 0xFF00) >> 8) . chr ($this->TTL & 0xFF);
      
      // Retrive the payload
      $Payload = $this->getData ($Offset + strlen ($Data) + 2, $Labels);
      
      // Append the payload
      $Data .= chr ((strlen ($Payload) & 0xFF00) >> 8) . chr (strlen ($Payload) & 0xFF) . $Payload;
      
      return $Data;
    }
    // }}}
    
    // {{{ getData
    /**
     * Retrive the payload of this record
     * 
     * @access public
     * @return string
     **/
    public function getData () {
      return '';
    }
    // }}}
  }

?>