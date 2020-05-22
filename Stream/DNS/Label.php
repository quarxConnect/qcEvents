<?PHP

  /**
   * qcEvents - DNS Label
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
  
  class qcEvents_Stream_DNS_Label implements Countable, ArrayAccess {
    /* All parts of this label */
    private $labelParts = array ();
    
    // {{{ __construct
    /**
     * Create a new DNS-Label
     * 
     * @access friendly
     * @return void
     **/
    function __construct (array $labelParts = array ()) {
      $this->labelParts = $labelParts;
    }
    // }}}
    
    // {{{ offsetGet
    /**
     * Retrive a single label from a given position
     * 
     * @param int $Index
     * 
     * @access public
     * @return string NULL if Index is out of bounds
     **/
    public function offsetGet ($Index) {
      if (isset ($this->labelParts [$Index]))
        return $this->labelParts [$Index];
    }
    // }}}
    
    // {{{ offsetSet
    /**
     * Set a DNS-Label at a given position
     * 
     * @param int $Index
     * @param string $Value
     * 
     * @access public
     * @return void
     **/
    public function offsetSet ($Index, $Value) {
      # TODO: Validate the label
      
      if ($Index !== null)
        $this->labelParts [(int)$Index] = $Value;
      else
        array_unshift ($this->labelParts, $Value);
    }
    // }}}
    
    // {{{ offsetExists
    /**
     * Check if an indexed DNS-Label exists
     * 
     * @param int $Index
     * 
     * @access public
     * @return int
     **/
    public function offsetExists ($Index) {
      return isset ($this->labelParts [$Index]);
    }
    // }}}
    
    // {{{ offsetUnset
    /**
     * Remove a DNS-Label
     * 
     * @param int $Index
     * 
     * @access public
     * @return void
     **/
    public function offsetUnset ($Index) {
      unset ($this->labelParts [$Index]);
    }
    // }}}
    
    // {{{ __toString
    /**
     * Create a string from this DNS-Label
     * 
     * @access friendly
     * @return string
     **/
    function __toString () {
      return implode ('.', $this->labelParts) . '.';
    }
    // }}}
    
    // {{{ count
    /**
     * Retrive the number of labels
     * 
     * @access public
     * @return int
     **/
    public function count () {
      return count ($this->labelParts);
    }
    // }}}
    
    // {{{ getParts
    /**
     * Retrive all parts of this label
     * 
     * @access public
     * @return array
     **/
    public function getParts () : array {
      return $this->labelParts;
    }
    // }}}
    
    // {{{ getParentLabel
    /**
     * Retrive label of parented zone
     * 
     * @access public
     * @return qcEvents_Stream_DNS_Label
     **/
    public function getParentLabel () : qcEvents_Stream_DNS_Label {
      return new $this (array_slice ($this->labelParts, 1));
    }
    // }}}
    
    // {{{ isSublabelOf
    /**
     * Check if this label is a child of another label
     * 
     * @param qcEvents_Stream_DNS_Label $parentLabel
     * 
     * @access public
     * @return bool
     **/
    public function isSublabelOf (qcEvents_Stream_DNS_Label $parentLabel) {
      return ($parentLabel->labelParts === array_slice ($this->labelParts, -count ($parentLabel->labelParts)));
    }
    // }}}
    
    // {{{ subLabel
    /**
     * Truncate another label from this one and return a new instance
     * 
     * @param qcEvents_Stream_DNS_Label $parentLabel
     * 
     * @access public
     * @return qcEvents_Stream_DNS_Label
     **/
    public function subLabel (qcEvents_Stream_DNS_Label $parentLabel) : qcEvents_Stream_DNS_Label {
      if (!$this->isSublabelOf ($parentLabel))
        return clone $this;
      
      return new $this (array_slice ($this->labelParts, 0, count ($this->labelParts) - count ($parentLabel->labelParts)));
    }
    // }}}
  }

?>