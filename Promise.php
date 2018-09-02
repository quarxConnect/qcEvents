<?PHP

  /**
   * qcEvents - Promise
   * Copyright (C) 2018 Bernd Holzmueller <bernd@quarxconnect.de>
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
  
  class qcEvents_Promise {
    /* Has this promise been done already */
    const DONE_NONE = 0;
    const DONE_FULLFILL = 1;
    const DONE_REJECT = 2;
    const DONE_EXCEPTION = 3;
    
    private $done = qcEvents_Promise::DONE_NONE;
    
    /* Result-data of this promise */
    private $result = null;
    
    /* Registered callbacks */
    private $callbacks = array (
      qcEvents_Promise::DONE_NONE      => array (),
      qcEvents_Promise::DONE_FULLFILL  => array (),
      qcEvents_Promise::DONE_REJECT    => array (),
      qcEvents_Promise::DONE_EXCEPTION => array (),
    );
    
    // {{{ resolve
    /**
     * Create a resolved promise
     * 
     * @param ...
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public static function resolve () {
      $args = func_get_args ();
      
      return new static (function ($resolve) use ($args) {
        call_user_func_array ($resolve, $args);
      });
    }
    // }}}
    
    // {{{ reject
    /**
     * Create a rejected promise
     * 
     * @param ...
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public static function reject () {
      $args = func_get_args ();
      
      return new static (function ($resolve, $reject) use ($args) {
        call_user_func_array ($reject, $args);
      });
    }
    // }}}
    
    // {{{ __construct
    /**
     * Create a new promise
     * 
     * @param callable $Callback
     + 
     * @access friendly
     * @return void
     **/
    function __construct (callable $Callback) {
      try {
        call_user_func (
          $Callback,
          function () { $this->finish ($this::DONE_FULLFILL, func_get_args ()); },
          function () { $this->finish ($this::DONE_REJECT, func_get_args ()); }
        );
      } catch (Exception $E) {
        $this->finish ($this::DONE_EXCEPTION, array ($E));
      }
    }
    // }}}
    
    // {{{ finish
    /**
     * Finish this promise
     * 
     * @param enum $done
     * @param array $result
     * 
     * @access private
     * @return void
     **/
    private function finish ($done, $result) {
      // Check if we are already done
      if ($this->done > $this::DONE_NONE)
        return;
      
      // Store the result
      $this->done = $done;
      $this->result = $result;
      
      // Invoke handlers
      if (count ($this->callbacks [$done]) > 0)
        foreach ($this->callbacks [$done] as $callback)
          call_user_func_array ($callback, $result);
      
      foreach ($this->callbacks [$this::DONE_NONE] as $callback)
          call_user_func ($callback);
      
      // Reset callbacks
      $this->callbacks = array (
        qcEvents_Promise::DONE_NONE      => array (),
        qcEvents_Promise::DONE_FULLFILL  => array (),
        qcEvents_Promise::DONE_REJECT    => array (),
        qcEvents_Promise::DONE_EXCEPTION => array (),
      );
    }
    // }}}
    
    // {{{ then
    /**
     * Register callbacks for promise-fullfillment
     * 
     * @param callable $resolve (optional)
     * @param callable $reject (optional)
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function then (callable $resolve = null, callable $reject = null) {
      // Check if we are not already done
      if ($this->done == $this::DONE_NONE) {
        if ($resolve)
          $this->callbacks [$this::DONE_FULLFILL][] = $resolve;
        
        if ($reject)
          $this->callbacks [$this::DONE_REJECT][] = $reject;
      
      // Check if we were fullfilled
      } elseif (($this->done == $this::DONE_FULLFILL) && $resolve)
        call_user_func_array ($resolve, $this->result);
      
      // Check if we were rejected
      elseif (($this->done == $this::DONE_REJECT) && $reject)
        call_user_func_array ($reject, $this->result);
      
      // Return a promise
      # TODO: This is not an equivalent to the spec!
      return $this;
    }
    // }}}
    
    // {{{ catch
    /**
     * Register a callback for exception-handling
     * 
     * @param callable $callback
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function catch (callable $callback) {
      // Check if we are not already done
      if ($this->done == $this::DONE_NONE)
        $this->callbacks [$this::DONE_EXCEPTION][] = $callback;
      
      // Check if we are done in the right state
      elseif ($this->done == $this::DONE_EXCEPTION)
        call_user_func_array ($callback, $this->result);
      
      // Return a promise
      # TODO: This is not an equivalent to the spec!
      return $this;
    }
    // }}}
    
    // {{{ finally
    /**
     * Register a callback that is always fired when the promise has settled
     * 
     * @param callable $callback
     * 
     * @access public
     * @return qcEvents_Promise
     **/
    public function finally (callable $callback) {
      // Check if we are not already done
      if ($this->done == $this::DONE_NONE)
        $this->callbacks [$this::DONE_NONE][] = $callback;
      
      // Check if we are done in the right state
      else
        call_user_func ($callback);
      
      // Return a promise
      # TODO: This is not an equivalent to the spec!
      return $this;
    }
    // }}}
  }

?>