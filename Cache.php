<?PHP

  /**
   * qcEvents - Runtime Cache
   * Copyright (C) 2015 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  require_once ('qcEvents/Interface/Timer.php');
  require_once ('qcEvents/Trait/Timer.php');
  require_once ('qcEvents/Trait/Parented.php');
  require_once ('qcEvents/Hookable.php');
  
  /**
   * Cache
   * -----
   * Simple Key-Value-Cache with TTL
   * 
   * @class qcEvents_Cache
   * @extends qcEvents_Hookable
   * @package qcEvents
   * @revision 03
   **/
  class qcEvents_Cache extends qcEvents_Hookable implements qcEvents_Interface_Timer {
    use qcEvents_Trait_Timer, qcEvents_Trait_Parented;
    
    /* Callbacks */
    private $lookupFunc = null;
    private $expireFunc = null;
    
    /* Values on this cache */
    private $Values = array ();
    
    /* TTL for keys on this cache */
    private $TTLs = array ();
    
    /* Default TTLs for Keys on this cache */
    private $defaultTTL = 60;
    
    // {{{ __construct
    /**
     * Create a new key-value-cache
     * 
     * @param qcEvents_Base $Base
     * @param callable $lookupFunction
     * @param callable $expireFunction 
     * 
     * The lookup-function-callback will be called in the form of
     * 
     *   function (qcEvents_Cache $Cache, string $Key, int $TTL, callable $Callback, mixed $Private = null) { }
     * 
     * The callback is excepted to be called in the form of
     * 
     *   function (qcEvents_Cache $Cache, string $Key, mixed $Value, int $TTL, mixed $Private) { }
     * 
     * If no value for the requested $Key was found, $Value as to be NULL.
     * 
     * The expire-function-callback will be called in the form of
     * 
     *   bool function (qcEvents_Cache $Cache, string $Key, mixed $Value) { }
     * 
     * If the callback returns FALSE, the $Key will be removed from cache, otherwise it will be
     * renewed for the specified TTL for the $Key.
     * 
     * @access friendly
     * @return void
     **/
    function __construct (qcEvents_Base $Base, callable $lookupFunction, callable $expireFunction = null) {
      $this->setEventBase ($Base);
      $this->lookupFunc = $lookupFunction;
      $this->expireFunc = $expireFunction;
    }
    // }}}
    
    // {{{ lookupKey
    /**
     * Lookup a key on this cache
     * 
     * @param string $Key
     * @param callable $Callback
     * @param mixed $Private
     * 
     * The callback will be raised in the form of
     * 
     *   function (qcEvents_Cache $Cache, string $Key, mixed $Value, mixed $Private) { }
     * 
     * @access public
     * @return bool
     **/
    public function lookupKey ($Key, callable $Callback, $Private = null) {
      // Check if the key is already known
      if ($this->haveKey ($Key)) {
        $this->___raiseCallback ($Callback, $Key, $this->getKey ($Key), $Private);
        
        return true;
      }
      
      // Forward to lookup-function
      if ($this->___raiseCallback ($this->lookupFunc, $Key, $this->defaultTTL, function (qcEvents_Cache $Cache, $cKey, $Value, $TTL) use ($Key, $Callback, $Private) {
        // Just make sure the parameters are value
        if (($Cache !== $this) || ($Key !== $cKey))
          return;
        
        // Store the value if one was found
        if ($Value !== null)
          $this->setKey ($Key, $Value, $TTL);
        
        // Fire the callback
        $this->___raiseCallback ($Callback, $Key, $Value, $Private);
      }) === false) {
        $this->___raiseCallback ($Callback, $Key, null, $Private);
        
        return false;
      }
      
      return true;
    }
    // }}}
    
    // {{{ haveKey
    /**
     * Check if a given key is known on this cache
     * 
     * @param string $Key
     * 
     * @access public
     * @return bool
     **/
    public function haveKey ($Key) {
      return isset ($this->Values [(string)$Key]);
    }
    // }}}
    
    // {{{ getKey
    /**
     * Retrive the value from a given key
     * 
     * @param string $Key
     * 
     * @access public
     * @return mixed
     **/
    public function getKey ($Key) {
      if (isset ($this->Values [(string)$Key]))
        return $this->Values [(string)$Key];
    }
    // }}}
    
    // {{{ setKey
    /**
     * Set a new value for a given key
     * 
     * @param string $Key
     * @param mixed $Value
     * 
     * @access public
     * @return void
     **/
    public function setKey ($Key, $Value, $TTL = null) {
      $Existing = isset ($this->Values [(string)$Key]);
      $TTL = ($TTL !== null ? $TTL : ($Existing ? $this->TTLs [(string)$Key] : $this->defaultTTL));
      
      // Enqueue an expiry-timer
      if ($Existing)
        $this->clearTimer ($this->TTLs [(string)$Key], false, array ($this, 'expireKey'), array ($Key, $this->TTLs [(string)$Key]));
      
      $this->addTimer ($TTL, false, array ($this, 'expireKey'), array ($Key, $TTL));
      
      // Store the new value
      $this->Values [(string)$Key] = $Value;
      $this->TTLs [(string)$Key] = $TTL;
      
      // Fire some callbacks
      if (!$Existing)
        $this->___callback ('keyAdded', $Key, $Value, $TTL);
      else
        $this->___callback ('keyChanged', $Key, $Value, $TTL);
    }
    // }}}
    
    // {{{ unsetKey
    /**
     * Remove a key from this cache
     * 
     * @param string $Key
     * 
     * @access public
     * @return void
     **/
    public function unsetKey ($Key) {
      if (!isset ($this->Values [(string)$Key]))
        return;
      
      $this->clearTimer ($this->TTLs [(string)$Key], false, array ($this, 'expireKey'), array ($Key, $this->TTLs [$Key]));
      unset ($this->Values [(string)$Key], $this->TTLs [(string)$Key]);
      
      $this->__callback ('keyRemoved', $Key);
    }
    // }}}
    
    // {{{ expireKey
    /**
     * Callback: Try to renew a key on this cache
     * 
     * @param array $Private
     * 
     * @access public
     * @return void
     **/
    public function expireKey ($Private) {
      // Sanatize private
      if (!is_array ($Private) || (count ($Private) !== 2))
        return;
      
      // Unpack private
      $Key = $Private [0];
      $TTL = $Private [1];
      
      // Check if the key is cached
      if (!isset ($this->Values [(string)$Key]))
        return;
      
      // Check if there is an expire-function set
      if ($this->expireFunc === null)
        return $this->unsetKey ($Key);
      
      // Call the expire-function
      if ($this->___raiseCallback ($this->expireFunc, $Key, $this->Values [(string)$Key]) === false)
        return $this->unsetKey ($Key);
      
      // Requeue the timer
      $this->addTimer ($TTL, false, array ($this, 'expireKey'), array ($Key, $TTL));
    }
    // }}}
    
    // {{{ prune
    /**
     * Remove all key-values from this cache
     * 
     * @access public
     * @return void
     **/
    public function prune () {
      foreach (array_keys ($this->Values) as $Key)
        $this->unsetKey ($Key);
    }
    // }}}
    
    // {{{ keyAdded
    /**
     * Callback: A new key was added to cache
     * 
     * @param string $Key
     * @param mixed $Value
     * @param int $TTL
     * 
     * @access protected
     * @return void
     **/
    protected function keyAdded ($Key, $Value, $TTL) { }
    // }}}
    
    // {{{ keyChanged
    /**
     * Callback: A key on the cache was changed
     * 
     * @param string $Key
     * @param mixed $Value
     * @param int $TTL
     * 
     * @access protected
     * @return void
     **/
    protected function keyChanged ($Key, $Value, $TTL) { }
    // }}}
    
    // {{{ keyRemoved
    /**
     * Callback: A key was removed from cache
     * 
     * @param string $Key
     * 
     * @access private
     * @return void
     **/
    protected function keyRemoved ($Key) { }
    // }}}
  }

?>