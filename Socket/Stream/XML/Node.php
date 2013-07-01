<?PHP

  /**
   * qcEvents - XML-Node for/from XML-Streams
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
  
  /**
   * XML Node
   * --------
   * Single XML-Object from XML-Streams
   *
   * @package qcEvents
   * @class qcEvents_Socket_Stream_XML_Node
   * @revision 03
   **/
  class qcEvents_Socket_Stream_XML_Node {
    /* Name of this node */
    private $Name = '';
    private $Prefix = null;
    private $localName = '';
    
    /* Namepsaces of this node */
    private $Namespace = null;
    private $Namespaces = array ();
    
    /* All Attributes of this node */
    private $Attributes = array ();
    
    /* Language of this node */
    private $Language = null;
    
    /* Value of this node */
    private $Value = null;
    
    /* Our parented node */
    private $parentNode = null;
    
    /* All Child-Nodes of this one */
    private $childNodes = array ();
    private $nextChild = 0;
    
    /* Index for children */
    private $Index = array ();
    
    /* Parsing of this node was completed */
    private $_nodeReady = true;
    private $_nodeOpen = false;
    private $_nodeForceOpen = false;
    
    // {{{ createXMLNode
    /**
     * Create a new XML-Node
     * 
     * @param string $Name
     * @param array $Attributes (optional)
     * @param string $Value (optional)
     * @param qcEvents_Socket_Stream_XML_Node $Parent (optional)
     * 
     * @access public
     * @return qcEvents_Socket_Stream_XML_Node
     **/
    public static function createXMLNode ($Name, $Attributes = null, $Value = null, qcEvents_Socket_Stream_XML_Node $Parent = null) {
      // Create a new object
      $Node = new static ();
      
      // Setup presets
      $Node->setName ($Name);
      $Node->setValue ($Value);
      
      if ($Parent !== null)
        $Node->setParent ($Parent);
      
      if (is_array ($Attributes))
        foreach ($Attributes as $Key=>$Value)
          $Node->setAttribute ($Key, $Value);
      
      // Return the node
      return $Node;
    }
    // }}}
    
    // {{{ __construct
    /**
     * Create a new XML-Node
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
    
    // {{{ __clone
    /**
     * Magic function: This node was cloned
     * 
     * @access friendly
     * @return void
     **/
    function __clone () { $this->___clone (); }
    private function ___clone () {
      // Create a copy of our child-nodes
      $Children = $this->childNodes;
      
      // Overwrite all children with a clone
      foreach ($Children as $ID=>$Child) {
        $this->childNodes [$ID] = clone $Child;
        $Child->parentNode = $this;
      }
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
        return $this->_nodeReady;
      
      $rd = ($this->_nodeReady != $Set) && !$this->isOpen ();
      $this->_nodeReady = $Set;
      
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
        return $this->_nodeOpen;
      
      $rd = !$Set && ($this->_nodeOpen != $Set) && $this->isReady ();
      $this->_nodeOpen = $Set;
      
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
      $this->_nodeForceOpen = true;
    }
    // }}}
    
    // {{{ cast
    /**
     * Cast this XML-Tag into a new subclass
     * 
     * @param string $Class
     * 
     * @access public
     * @return object
     **/
    public function cast ($Class) {
      // Check if destination-class exists and is valid
      if (!class_exists ($Class) ||
          !is_subclass_of ($Class, __CLASS__))
        return false;
      
      // Create new destination
      $Clone = new $Class;
      
      // Set parsing of clone to ready
      $Clone->_nodeReady = true;
      $Clone->_nodeOpen = false;
      $Clone->_nodeForceOpen = $this->_nodeForceOpen;
      
      // The clone does not have a parent (as the parent does not know him)
      $Clone->parentNode = null;
      
      // Copy our values
      $Clone->Name = $this->Name;
      $Clone->Namespace = $this->Namespace;
      $Clone->Namespaces = $this->Namespaces;
      $Clone->Attributes = $this->Attributes;
      $Clone->Language = $this->Language;
      $Clone->Value = $this->Value;
      $Clone->childNodes = $this->childNodes;
      $Clone->Index = $this->Index;
      
      // Raise internal callback
      $Clone->___clone ();
      
      return $Clone;
    }
    // }}}
    
    // {{{ getName
    /**
     * Retrive the name of this node
     * 
     * @access public
     * @return string
     **/
    public function getName () {
      return $this->Name;
    }
    // }}}
    
    // {{{ getLocalName
    /**
     * Retrive the local name of this node
     * 
     * @access public
     * @return string
     **/
    public function getLocalName () {
      return $this->localName;
    }
    // }}}
    
    // {{{ setName
    /**
     * Set Name of this node
     * 
     * @param string $Name
     * 
     * @access protected
     * @return void
     **/
    protected function setName ($Name) {
      $this->Name = $Name;
      
      if (($p = strpos ($Name, ':')) !== false) {
        $this->Prefix = substr ($Name, 0, $p);
        $this->localName = substr ($Name, $p + 1);
      } else {
        $this->localName = $Name;
        $this->Prefix = null;
      }
    }
    // }}}
    
    // {{{ getPrefix
    /**
     * Retrive the namespace-prefix for this node
     * 
     * @access public
     * @return string
     **/
    public function getPrefix () {
      return $this->Prefix;
    }
    // }}}
    
    // {{{ getParent
    /**
     * Retrive the parent node of this one
     * 
     * @access public
     * @return qcEvents_Socket_Stream_XML_Node
     **/
    public function getParent () {
      return $this->parentNode;   
    }
    // }}}
    
    // {{{ setParent
    /**
     * Set our parent tag
     * 
     * @param qcEvents_Socket_Stream_XML_Node $Parent
     * 
     * @access public
     * @return bool
     **/
    public function setParent (qcEvents_Socket_Stream_XML_Node $Parent) {
      // Remove ourself from old parent
      if (is_object ($this->parentNode))
        $this->parentNode->removeChild ($this);
      
      // Append ourself to new parent
      $this->parentNode = $Parent;
      $this->parentNode->addChild ($this);
      
      return true;
    }
    // }}}
    
    // {{{ unsetParent
    /**
     * Remove our parent
     * 
     * @access public
     * @return void
     **/
    public function unsetParent () {
      // Remove ourself from old parent
      if (is_object ($this->parentNode))
        $this->parentNode->removeChild ($this);
      
      $this->parentNode = null;
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
      if ($Sub === null) {
        if ($this->Namespace !== null)
          return $this->Namespace;
        
        if (($p = strpos ($this->Name, ':')) !== null) {
          $Prefix = substr ($this->Name, 0, $p);
          
          if (isset ($this->Namespaces [$Prefix]))
            return $this->Namespaces [$Prefix];
          
          $P = $this;
          
          while ($P = $P->getParent ())
            if (($NS = $P->getNamespace ($Prefix)) !== null)
              return $NS;
        }
        
        return null;
      }
      
      if (!isset ($this->Namespaces [$Sub]))
        return null;
      
      return $this->Namespaces [$Sub];
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
        $this->Namespace = strval ($Namespace);
      else
        $this->Namespaces [$Sub] = strval ($Namespace);
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
      return $this->Language;
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
      $this->Language = strval ($Lang);
    }
    // }}}
    
    // {{{ haveValue
    /**
     * Check if this node has a value assigned
     * 
     * @access public
     * @return bool
     **/
    public function haveValue () {
      return ($this->Value !== null);
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
      return $this->Value;
    }
    // }}}
    
    // {{{ setValue
    /**
     * Set some opaque data as value for this node
     * 
     * @param string $Value
     * 
     * @access public
     * @return void
     **/
    public function setValue ($Value) {
      if (is_object ($Value) || is_array ($Value))
        return trigger_error ('Trying to set complex as a value for ' . $this->getName ());
      
      $this->Value = $Value;
    }
    // }}}
    
    // {{{ unsetValue
    /**
     * Remove any value from this node
     * 
     * @access public
     * @return void
     **/
    public function unsetValue () {
      $this->Value = null;
    }
    // }}}
    
    // {{{ haveAttribute
    /**
     * Check if there is a specific attribute assigned to this node
     * 
     * @param string $Name
     * 
     * @access public
     * @return bool
     **/
    public function haveAttribute ($Name) {
      return isset ($this->Attributes [$Name]);
    }
    // }}}
    
    // {{{ getAttribute
    /**
     * Retrive an attribute from this node
     * 
     * @param string $Name
     * @param mixed $Default (optional)
     * 
     * @access public
     * @return mixed
     **/
    public function getAttribute ($Name, $Default = null) {
      if (isset ($this->Attributes [$Name]))
        return $this->Attributes [$Name];
      
      return $Default;
    }
    // }}}
    
    // {{{ getAttributes
    /**
     * Retrive all attributes from this node
     * 
     * @access public
     * @return array
     **/
    public function getAttributes () {
      return $this->Attributes;
    }
    // }}}
    
    // {{{ setAttribute
    /**
     * Set an attribute of this node
     * 
     * @param string $Name
     * @param mixed $Value
     * 
     * @access public
     * @return void
     **/
    public function setAttribute ($Name, $Value) {
      // Check if this is a namespace, not an attribute
      if ($Name == 'xmlns')
        return $this->setNamespace ($Value);
      elseif (substr ($Name, 0, 6) == 'xmlns:')
        return $this->setNamespace ($Value, substr ($Name, 6));
      
      // Check if this is a language, not an attribute
      elseif ($Name == 'xml:lang')
        return $this->setLanguage ($Value);
      
      // Set the attribute
      $this->Attributes [$Name] = strval ($Value);
    }
    // }}}
    
    // {{{ unsetAttribute
    /**
     * Remove an attribute from this node
     * 
     * @param string $Name
     * 
     * @access public
     * @return void
     **/
    public function unsetAttribute ($Name) {
      unset ($this->Attributes [$Name]);
    }
    // }}}
    
    // {{{ haveChildren
    /**
     * Check if we have children (with a given name)
     * 
     * @param string $Name (optinal)
     * 
     * @access public
     * @return bool
     **/
    public function haveChildren ($Name = null) {
      // Check wheter to check if we have any child
      if ($Name === null)
        return (count ($this->childNodes) > 0);
      
      // Check wheter to check if we have child-nodes by a given name
      return (isset ($this->Index [$Name]) && (count ($this->Index [$Name]) > 0));
    }
    // }}}
    
    // {{{ getChildren
    /**
     * Retrive a list of all child-nodes (with a given name)
     * 
     * @param string $Name (optional)
     * 
     * @access public
     * @return array
     **/
    public function getChildren ($Name = null) {
      if ($Name === null)
        return $this->childNodes;
      
      if (!isset ($this->Index [$Name]))
        return array ();
      
      $out = array ();
      
      foreach ($this->Index [$Name] as $ID)
        $out [] = $this->childNodes [$ID];
      
      return $out;
    }
    // }}}
    
    // {{{ createChild
    /**
     * Create a new XML-Node as child of this one
     * 
     * @param string $Name
     * @param array $Attributes (optional)
     * @param string $Value (optional)
     * @param string $Class (optional)
     * @param bool $Unique (optional)
     * 
     * @access public
     * @return object
     **/
    public function createChild ($Name, $Attributes = null, $Value = null, $Class = null, $Unique = false) {
      // Check wheter to auto-detect class
      if ($Class === null) {
        $Class = get_class ($this);
        
        while (($Class !== false) && defined ($Class . '::NODE_NAME'))
          $Class = get_parent_class ($Class);
        
        if ($Class === false)
          $Class = __CLASS__;
      }
      
      // Create new node
      if (!is_object ($Node = $Class::createXMLNode ($Name, $Attributes, $Value, $this)))
        return false;
      
      // Check if to remove all other nodes with this name
      if ($Unique) {
        $this->removeChildren ($Name);
        $this->addChild ($Node);
      }
      
      return $Node;
    }
    // }}}
    
    // {{{ addChild
    /**
     * Append a node to our collection
     * 
     * @param qcEvents_Socket_Stream_XML_Node $Node
     * 
     * @access public
     * @return void
     **/
    public function addChild (qcEvents_Socket_Stream_XML_Node $Node) {
      // Retrive name of new tag
      $Name = $Node->getName ();
      
      // Append the node
      if (isset ($this->Index [$Name]))
        $this->Index [$Name][] = $this->nextChild;
      else
        $this->Index [$Name] = array ($this->nextChild);
      
      $this->childNodes [$this->nextChild++] = $Node;
    }
    // }}}
    
    // {{{ setChild
    /**
     * Add a node to our collection and make sure that this is the only one of its type
     * 
     * @param qcEvents_Socket_Stream_XML_Node $Node
     * 
     * @access public
     * @return void
     **/
    public function setChild (qcEvents_Socket_Stream_XML_Node $Node) {
      // Retrive name of new tag
      $Name = $Node->getName ();
      
      // Remove all tags by this name
      $this->removeChildren ($Name);
      
      // Append this tag
      return $this->addChild ($Node);
    }
    // }}}
    
    // {{{ removeChildren
    /**
     * Remove all nodes by a given name from our collection
     * 
     * @param string $Name (optional)
     * 
     * @access public
     * @return void
     **/
    public function removeChildren ($Name = null) {
      if ($Name === null) {
        $this->childNodes = array ();
        $this->Index = array ();
        $this->nextChild = 0;
      } elseif (isset ($this->Index [$Name])) {
        foreach ($this->Index [$Name] as $ID)
          unset ($this->childNodes [$ID]);
        
        unset ($this->Index [$Name]);
      }
    }
    // }}}
    
    // {{{ removeChild
    /**
     * Remove a given node from our collection
     * 
     * @param qcEvents_Socket_Stream_XML_Node $Node
     * 
     * @access public
     * @return bool
     **/
    public function removeChild (qcEvents_Socket_Stream_XML_Node $Node) {
      // Retrive name of tag
      $Name = $Node->getName ();
      
      // Check if the node-name is known here
      if (!isset ($this->Index [$Name]))
        return false;
      
      // Remove all nodes
      $Found = false;
      
      foreach ($this->Index [$Name] as $p=>$ID)
        if ($this->childNodes [$ID] === $Node) {
          unset ($this->childNodes [$ID]);
          unset ($this->Index [$Name][$p]);
          
          $Found = true;
        }
      
      if (count ($this->Index [$Name]) == 0)
        unset ($this->Index [$Name]);
      
      return $Found;
    }
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
      $buf = '<' . ($tN = $this->getName ()) . ' ';
      
      // Append XML-Namespace
      if ($this->Namespace != null)
        $buf .= 'xmlns="' . $this->Namespace . '" ';
      
      if (count ($this->Namespaces) > 0)
        foreach ($this->Namespaces as $Key=>$NS)
          $buf .= 'xmlns:' . $Key . '="' . $NS . '" ';
      
      // Append XML-Language
      if ($this->Language !== null)
        $buf .= 'xml:lang="' . $this->Language . '" ';
      
      // Append attributes
      if (is_array ($Attribs = $this->getAttributes ()))
        foreach ($Attribs as $Name=>$Value)
          if ($Value !== null)
            $buf .= $Name . '="' . addslashes ($Value) . '" ';
      
      // Handle any subsequent data
      if ($this->haveChildren () || $this->haveValue ()) {
        $buf = rtrim ($buf) . '>';
        
        // Append a value
        if ($this->haveValue ()) {
          $xml = $this->getValue ();
          $xml = str_replace ('&', '&amp;', $xml);
          $xml = str_replace (array ('<', '>'), array ('&lt;', '&gt;'), $xml);
          $buf .= $xml;
        
        // Append a selection of subtags
        } elseif (is_array ($Children = $this->getChildren ()))
          foreach ($Children as $Child)
            $buf .= $Child->toString ();
        
        if (!$this->_nodeForceOpen)
          $buf .= '</' . $tN . '>';
      
      // Just close the tag
      } else
        $buf .= ($this->_nodeForceOpen ? '' : '/') . '>';
      
      return $buf;
    }
    // }}}
    
    
    // {{{ parseCompleted
    /** 
     * Callback: The node was parsed completly
     * 
     * @access protected
     * @return void
     **/
    protected function parseCompleted () { }
    // }}}
  }

?>