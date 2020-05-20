<?PHP

  /**
   * qcEvents - DNS Label
   * Copyright (C) 2015 Bernd Holzmueller <bernd@quarxconnect.de>
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
    private $Parts = array ();
    
    // {{{ __construct
    /**
     * Create a new DNS-Label
     * 
     * @access friendly
     * @return void
     **/
    function __construct (array $Parts = array ()) {
      $this->Parts = $Parts;
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
      if (isset ($this->Parts [$Index]))
        return $this->Parts [$Index];
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
        $this->Parts [(int)$Index] = $Value;
      else
        array_unshift ($this->Parts, $Value);
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
      return isset ($this->Parts [$Index]);
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
      unset ($this->Parts [$Index]);
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
      return implode ('.', $this->Parts) . '.';
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
      return count ($this->Parts);
    }
    // }}}
    
    // {{{ getParts
    /**
     * Retrive all parts of this label
     * 
     * @access public
     * @return array
     **/
    public function getParts () {
      return $this->Parts;
    }
    // }}}
    
    // {{{ getParentLabel
    /**
     * Retrive label of parented zone
     * 
     * @access public
     * @return qcEvents_Stream_DNS_Label
     **/
    public function getParentLabel () {
      return new $this (array_slice ($this->Parts, 1));
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
      return ($parentLabel->Parts === array_slice ($this->Parts, -count ($parentLabel->Parts)));
    }
    // }}}
  }

?>