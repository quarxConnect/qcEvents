<?PHP

  /**
   * qcEvents - DNS Question
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
  
  class qcEvents_Stream_DNS_Question {
    /**
     * [QName] The Label that is asked for
     **/
    public $Label = '';
    
    /**
     * [QType] Type of DNS-RRs that is asked for
     **/
    public $Type = qcEvents_Stream_DNS_Message::TYPE_ANY;
    
    /**
     * [QClass] Class of DNS-RRs that is asked for
     **/
    public $Class = qcEvents_Stream_DNS_Message::CLASS_INTERNET;
    
    // {{{ __construct
    /**
     * Create a new DNS-Question
     * 
     * @param string $Label (optional)
     * @param enum $Type (optional)
     * @param enum $Class (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($Label = null, $Type = null, $Class = null) {
      if ($Label !== null)
        $this->setLabel ($Label);
      
      if ($Type !== null)
        $this->setType ($Type);
      
      if ($Class !== null)
        $this->setClass ($Class);
    }
    // }}}
    
    // {{{ setLabel
    /**
     * Set the label for this question
     * 
     * @param string $Label
     * 
     * @access public
     * @return bool
     **/
    public function setLabel ($Label) {
      $this->Label = $Label;
      
      return true;
    }
    // }}}
    
    // {{{ setType
    /**
     * Set the type of this question
     * 
     * @param enum $Type
     * 
     * @access public
     * @return bool
     **/
    public function setType ($Type) {
      $this->Type = $Type;
      
      return true;
    }
    // }}}
    
    // {{{ setClass
    /**
     * Set the class of this question
     * 
     * @param enum $Class
     * 
     * @access public
     * @return bool
     **/
    public function setClass ($Class) {
      if (($Class < 1) || ($Class > 4))
        return false;
      
      $this->Class = $Class;
      
      return true;
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
      // Retrive the label
      $this->setLabel (qcEvents_Stream_DNS_Message::getLabel ($Data, $Offset));
      
      // Retrive type and class
      $this->setType ((ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]));
      $this->setClass ((ord ($Data [$Offset++]) << 8) + ord ($Data [$Offset++]));
      
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
      return
        qcEvents_Stream_DNS_Message::setLabel ($this->Label, $Offset, $Labels) .
        chr (($this->Type & 0xFF00) >> 8) . chr ($this->Type & 0xFF) .
        chr (($this->Class & 0xFF00) >> 8) . chr ($this->Class & 0xFF);
    }
    // }}}
  }

?>