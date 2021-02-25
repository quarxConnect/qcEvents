<?php

  /**
   * quarxConnect Events - DNS Resource Record
   * Copyright (C) 2014-2021 Bernd Holzmueller <bernd@quarxconnect.de>
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

  namespace quarxConnect\Events\Stream\DNS\Record;
  use quarxConnect\Events\Stream\DNS;
  
  class TXT extends DNS\Record {
    protected const DEFAULT_TYPE = 0x10;
    
    // {{{ __toString
    /**
     * Create a human-readable representation from this
     * 
     * @access friendly
     * @return string  
     **/
    function __toString () {
      return $this->getLabel () . ' ' . $this->getTTL () . ' ' . $this->getClassName () . ' TXT "' . implode ('" "', $this->getTexts ()) . '"';
    }
    // }}}
    
    // {{{ getTexts
    /**
     * Retrive all text-blocks from this record
     * 
     * @access public
     * @return array
     **/
    public function getTexts () {
      $out = array ();
      $p = 0;
      $l = strlen ($this->Payload);
      
      while ($p < $l) {
        $L = ord ($this->Payload [$p++]);
        $out [] = substr ($this->Payload, $p, $L);
        $p += $L;
      }
      
      return $out;
    }
    // }}}
    
    // {{{ getText
    /**
     * Retrive the text from this record
     * 
     * @access public
     * @return string
     **/
    public function getText () {
      return implode (' ', $this->getTexts ());
    }
    // }}}
    
    // {{{ setText
    /**
     * Store a new text on this record
     * 
     * @param string $Text
     * 
     * @access public
     * @return bool
     **/
    public function setText ($Text) {
      // Retrive the length of the text
      if (($l = strlen ($Text)) > 0xFF)
        return false;
      
      // Set the payload
      $this->Payload = chr ($l) . $Text;
      
      return true;
    }
    // }}}
    
    // {{{ setTexts
    /**
     * Store a set of character-strings on this recrod
     * 
     * @param array $Texts
     * 
     * @access public
     * @return bool
     **/
    public function setTexts (array $Texts) {
      $buf = '';
      $L = 0;
      
      foreach ($Texts as $Text)
        if ((($l = strlen ($Text)) <= 0xFF) && (($L += $l + 1) <= 0xFFFF))
          $buf .= chr ($l) . $Text;
        else
          return false;
      
      return true;
    }
    // }}}
  }
