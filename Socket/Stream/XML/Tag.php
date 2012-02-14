<?PHP

  /**
   * qcEvents - XML-Tag for/from XML-Streams
   * Copyright (C) 2012 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  /**
   * XML Tag
   * -------
   * Single XML-Object from XML-Streams
   *
   * @package qcEvents
   * @class qcEvents_Socket_Stream_XML_Tag
   * @revision 01
   **/
  class qcEvents_Socket_Stream_XML_Tag {
    private $_tagName = "";
    private $_tagNamespace = null;
    private $_tagNamespaces = array ();
    private $_tagLang = null;
    private $_tagValue = null;
    private $_tagParent = null;
    private $_tagReady = false;
    private $_tagOpen = false;
    private $_tagAttributes = array ();
    private $_tagForceOpen = false;
    
    public $Subtags = array ();
    
    // {{{ createXMLTag
    /**
     * Create a new XML-Tag
     * 
     * @param string $Name (optional)  
     * @param object $Parent (optional)
     * @param string $Value (optional)
     * 
     * @access friendly
     * @return void
     **/
    public static function createXMLTag ($Name, $Parent = null, $Value = null) {
      $Tag = new static ();
      
      $Tag->setName ($Name);
      $Tag->setValue ($Value);
      $Tag->setParent ($Parent);
      
      return $Tag;
    }
    // }}}
    
    // {{{ __construct
    /**
     * Create a new XML-Tag
     * 
     * @param string $Name (optional)
     * @param object $Parent (optional)
     * @param string $Value (optional)
     * 
     * @access friendly
     * @return void
     **/
    function __construct ($Name = null, $Parent = null, $Value = null) {
      if ($Name !== null)
        $this->setName ($Name);
      
      if ($Parent !== null)
        $this->setParent ($Parent);
      
      if ($Value !== null)
        $this->setValue ($Value);
    }
    // }}}
    
    // {{{ isReady
    /**
     * Determine or set if this tag has parsed all its attributes
     * 
     * @param bool $Set (optional) For internal use only
     * 
     * @access public
     * @return bool
     **/
    public final function isReady ($Set = null) {
      if ($Set === null)
        return $this->_tagReady;
      
      $rd = ($this->_tagReady != $Set) && !$this->isOpen ();
      $this->_tagReady = $Set;
      
      if ($rd)
        $this->parseCompleted ();
    }
    // }}}
    
    // {{{ isOpen
    /**
     * Determine or set wheter this tag has subtags that are being parsed
     * 
     * @param bool $Set (optional) For internal use only
     * 
     * @access public
     * @return bool
     **/
    public final function isOpen ($Set = null) {
      if ($Set === null)
        return $this->_tagOpen;
      
      $rd = ($this->_tagOpen != $Set) && $this->isReady ();
      $this->_tagOpen = $Set;
      
      if ($rd)
        $this->parseCompleted ();
    }
    // }}}
    
    // {{{ forceOpen
    /**
     * Force this tag to be left open
     * 
     * @access public
     * @return void
     **/
    public function forceOpen () {
      $this->_tagForceOpen = true;
    }
    // }}}
    
    // {{{ parseCompleted
    /**
     * This callback is invoked whenever a tag is completely parsed
     * 
     * @access protected
     * @return void
     **/
    protected function parseCompleted () { }
    // }}}
    
    // {{{ getAttribute
    /**
     * Retrive an attribute from this tag
     * 
     * @param string $Name
     * @param mixed $Default (optional)
     * 
     * @access public
     * @return mixed
     **/
    public function getAttribute ($Name, $Default = null) {
      if (isset ($this->_tagAttributes [$Name]))
        return $this->_tagAttributes [$Name];
      
      $Name = str_replace (':', '', $Name);
            
      if ((strlen ($Name) > 0) && isset ($this->$Name))
        return $this->$Name;
      
      return $Default;
    }
    // }}}
    
    // {{{ setAttribute
    /**
     * Set an attribute of this tag
     * 
     * @param string $Name
     * @param mixed $Value
     * 
     * @access public
     * @return void
     **/
    public function setAttribute ($Name, $Value) {
      if ($Name == 'xmlns')
        return $this->setNamespace ($Value);
      elseif (substr ($Name, 0, 6) == 'xmlns:')
        return $this->setNamespace ($Value, substr ($Name, 6));
      elseif ($Name == 'xml:lang')
        return $this->setLanguage ($Value);
      
      $this->_tagAttributes [$Name] = $Value;
      
      $Name = str_replace (':', '', $Name);
      
      if (strlen ($Name) > 0)
        $this->$Name = $Value;
      else
        trigger_error ('Could not set an empty attribute');
    }
    // }}}
    
    // {{{ unsetAttribute
    /**
     * Remove an attribute from this tag
     * 
     * @param string $Name
     * 
     * @access public
     * @return void
     **/
    public function unsetAttribute ($Name) {
      unset ($this->_tagAttributes [$Name]);
      
      $Name = str_replace (':', '', $Name);
      
      if (strlen ($Name) > 0)
        unset ($this->$Name);
    }
    // }}}
    
    // {{{ getName
    /**
     * Retrive the name of this tag
     * 
     * @access public
     * @return string
     **/
    public function getName () {
      return $this->_tagName;
    }
    // }}}
    
    // {{{ setName
    /**
     * Set Name of this tag
     * 
     * @param string $Name
     * 
     * @access protected
     * @return void
     **/
    protected function setName ($Name) {
      $this->_tagName = $Name;
    }
    // }}}
    
    // {{{ getLanguage
    /**
     * Retrive the XML-Language for this tag
     * 
     * @access public
     * @return string
     **/
    public function getLanguage () {
      return $this->_tagLang;
    }
    // }}}
    
    // {{{ setLanguage
    /**
     * Set the XML-Language for this tag
     * 
     * @param string $Lang
     * 
     * @access public
     * @return void
     **/
    public function setLanguage ($Lang) {
      $this->_tagLang = $Lang;
    }
    // }}}
    
    // {{{ getNamespace
    /**
     * Retrive our namespace
     * 
     * @param string $Subnamespace (optional)
     * 
     * @access public
     * @return string
     **/
    public function getNamespace ($Sub = null) {
      if ($Sub === null)
        return $this->_tagNamespace;
      
      return $this->_tagNamespaces [$Sub];
    }
    // }}}
    
    // {{{ setNamespace
    /**
     * Set the namespace for this tag
     * 
     * @param string $Namespace
     * @param string $Subnamespace (optional)
     * 
     * @access public
     * @return void
     **/
    public function setNamespace ($Namespace, $Sub = null) {
      if ($Sub === null)
        $this->_tagNamespace = $Namespace;
      else
        $this->_tagNamespaces [$Sub] = $Namespace;
    }
    // }}}
    
    // {{{ getValue
    /**
     * Retrive the currently set value
     * 
     * @access public
     * @return string
     **/
    public function getValue () {
      return $this->_tagValue;
    }
    // }}}
    
    // {{{ setValue
    /**
     * Set some opaque data as value for this tag
     * 
     * @param string $Value
     * 
     * @access public
     * @return void
     **/
    public function setValue ($Value) {
      if (is_object ($Value))
        return trigger_error ('Trying to set object as a value for ' . $this->getName ());
      
      $this->_tagValue = $Value;
    }
    // }}}
    
    // {{{ unsetValue
    /**
     * Remove any value from this tag
     * 
     * @access public
     * @return void
     **/
    public function unsetValue () {
      $this->_tagValue = null;
    }
    // }}}
    
    // {{{ __toString
    /**
     * Just an magic alias for toString ()
     * 
     * @access public
     * @return string
     * @see qcEvents_Socket_Stream_XML_Tag::toString ()
     **/
    #public function __toString () {
    #  return $this->toString ();
    #}
    // }}}
    
    // {{{ toString
    /**
     * Generate a string from this tag
     * 
     * @access public
     * @return string
     **/
    public function toString () {
      // Start the tag
      # TODO: Remove this ugly str_replace
      $buf = '<' . str_replace ('__', ':', ($tN = $this->getName ())) . ' ';
      
      // Append XML-Namespace
      if ($this->_tagNamespace != null)
        $buf .= "xmlns='" . $this->_tagNamespace . "' ";
      
      if (count ($this->_tagNamespaces) > 0)
        foreach ($this->_tagNamespaces as $Key=>$NS)
          $buf .= 'xmlns:' . $Key . "='" . $NS . "' ";
      
      // Append XML-Language
      if ($this->_tagLang != null)
        $buf .= "xml:lang='" . $this->_tagLang . "' ";
      
      // Append attributes
      if (is_array ($Attribs = $this->getAttributes ()))
        foreach ($Attribs as $Name=>$Value)
          if ($Value !== null)
            $buf .= str_replace ('__', ':', $Name) . "='" . addslashes ($Value) . "' ";
      
      // Handle any subsequent data
      if ($this->haveSubtags () || $this->haveValue ()) {
        $buf = rtrim ($buf) . '>';
        
        // Append a value
        if ($this->haveValue ()) {
          $xml = $this->getValue ();
          $xml = str_replace ('&', '&amp;', $xml);
          $xml = str_replace (array ('<', '>'), array ('&lt;', '&gt;'), $xml);
          $buf .= $xml;
        
        // Append a selection of subtags
        } elseif (is_array ($sT = $this->getSubtags ()))
          foreach ($sT as $Tag)
            if (is_object ($Tag))
              $buf .= $Tag->toString ();
            elseif (is_array ($Tag))
              foreach ($Tag as $t)
                $buf .= $t->toString ();
        
        if (!$this->_tagForceOpen)
          $buf .= '</' . $tN . '>';
      
      // Just close the tag
      } else
        $buf .= ($this->_tagForceOpen ? '' : '/') . '>';
      
      return $buf;
    }
    // }}}
    
    public function haveSubtags ($Name = null) {
      if ($Name === null)
        return (count ($this->Subtags) > 0);
      
      return (isset ($this->Subtags [$Name]) && (count ($this->Subtags [$Name]) > 0));
    }
    
    public function haveValue () {
      return ($this->_tagValue != "");
    }
    
    public function getParent () {
      return $this->_tagParent;
    }
    
    // {{{ setParent
    /**
     * Set our parent tag
     * 
     * @access public
     * @return bool
     **/
    public function setParent ($Handle) {
      // Check if new parent is of the right type
      if (!($Handle instanceof qcEvents_Socket_Stream_XML_Tag))
        return false;
      
      // Remove ourself from old parent
      if (is_object ($this->_tagParent))
        $this->_tagParent->removeSubtag ($this);
      
      // Append ourself to new parent
      $this->_tagParent = $Handle;
      $this->_tagParent->addSubtag ($this);
      
      return true;
    }
    // }}}
    
    // {{{ getAttributes
    /**
     * Retrive all attributes from this tag
     * 
     * @access protected
     * @return array
     **/
    protected function getAttributes () {
      return $this->_tagAttributes;
      
      // Retrive all variables from this object
      $vars = get_object_vars ($this);
      
      // Strip some default stuff
      unset ($vars ['_tagName']);
      unset ($vars ['_tagNamespace']);
      unset ($vars ['_tagNamespaces']);
      unset ($vars ['_tagLang']);
      unset ($vars ['_tagValue']);
      unset ($vars ['_tagParent']);
      unset ($vars ['_tagReady']);
      unset ($vars ['_tagOpen']);
      unset ($vars ['_tagAttributes']);
      unset ($vars ['_tagForceOpen']);
      unset ($vars ['Subtags']);
      
      return $vars;
    }
    // }}}
    
    // {{{ getSubtags
    /**
     * Retrive a list of all tags on our collection
     * 
     * @access public
     * @return array
     **/
    public function getSubtags () {
      if (!is_array ($this->Subtags))
        return array ();
      
      return $this->Subtags;
    }
    // }}}
    
    // {{{ getSubtagsByName
    /**
     * Retrive a list of tags by their name
     * 
     * @param string $Name
     * 
     * @access public
     * @return array
     **/
    public function getSubtagsByName ($Name) {
      if (!is_array ($this->Subtags) || !isset ($this->Subtags [$Name]))
        return array ();
      
      return $this->Subtags [$Name];
    }
    // }}}
    
    public function getSubtagByName ($Name) {
      return array_shift ($this->getSubtagsByName ($Name));
    }
    
    // {{{ createSubtag
    /**
     * Create a new XML-Tag
     * 
     * @param string $Name
     * @param string $Class (optional)
     * @param bool $Unique (optional)
     * 
     * @access public
     * @return object
     **/
    public function createSubtag ($Name, $Class = null, $Unique = false, $Value = null) {
      if ($Class === null) {
        $Class = get_class ($this);
        
        while (($Class !== false) && defined ($Class . '::TAG_NAME'))
          $Class = get_parent_class ($Class);
        
        if ($Class === false)
          $Class = __CLASS__;
          # $Class = (defined ('XML_STREAM_DEFAULT_CLASS') ? XML_STREAM_DEFAULT_CLASS : __CLASS__);
      }
      
      if ($Unique)
        $this->removeSubtagsByName ($Name);
      
      $Tag = $Class::createXMLTag ($Name, $this, $Value);
      
      return $Tag;
    }
    // }}}
    
    // {{{ addSubtag
    /**
     * Append a tag to our collection
     * 
     * @param object $Tag
     * 
     * @access public
     * @return void
     **/
    public function addSubtag ($Tag) {
      // Retrive name of new tag
      $Name = $Tag->getName ();
      
      // Validate our own data
      if (!is_array ($this->Subtags))
        $this->Subtags = array ();
      
      if (!isset ($this->Subtags [$Name]))
        $this->Subtags [$Name] = array ();
      
      // Append the tag
      $this->Subtags [$Name][] = $Tag;
    }
    // }}}
    
    // {{{ setSubtag
    /**
     * Add a tag to our collection and make sure that this is the only one of its type
     * 
     * @param object $Tag
     * 
     * @access public
     * @return void
     **/
    public function setSubtag ($Tag) {
      // Retrive name of new tag
      $Name = $Tag->getName ();
      
      // Remove all tags by this name
      $this->removeSubtagsByName ($Name);
      
      // Append this tag
      return $this->addSubtag ($Tag);
    }
    // }}}
    
    // {{{ removeSubtagsByName
    /**
     * Remove all tags by a given name from our collection
     * 
     * @param string $Name
     * 
     * @access public
     * @return void
     **/
    public function removeSubtagsByName ($Name) {
      unset ($this->Subtags [$Name]);
    }
    // }}}
    
    // {{{ removeSubtag
    /**
     * Remove a given tag from our collection
     * 
     * @param object $Tag
     * 
     * @access public
     * @return bool
     **/
    public function removeSubtag ($Tag) {
      // Check type of input
      if (!($Tag instanceof qcEvents_Socket_Stream_XML_Tag))
        return false;
      
      // Retrive name of tag
      $Name = $Tag->getName ();
      
      // Check if the tag might be in our collection
      if (!is_array ($this->Subtags) || !isset ($this->Subtags [$Name]))
        return false;
      
      // Try to find and remove the tag
      foreach ($this->Subtags [$Name] as $ID=>$sT)
        if ($sT == $Tag) {
          unset ($this->Subtags [$Name][$ID]);
          return true;
        }
      
      // Return failure
      return false;
    }
    // }}}
  }

?>