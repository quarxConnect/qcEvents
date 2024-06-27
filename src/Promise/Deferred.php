<?php

  /**
   * quarxConnect Events - Deferred Execution
   * Copyright (C) 2019-2024 Bernd Holzmueller <bernd@quarxconnect.de>
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
   * along with this program. If not, see <http://www.gnu.org/licenses/>.
   **/

  declare (strict_types=1);

  namespace quarxConnect\Events\Promise;

  use Closure;
  use InvalidArgumentException;

  use quarxConnect\Events\Base;
  use quarxConnect\Events\Promise;

  class Deferred {
    /**
     * Promise for this deferred execution
     *
     * @var Promise|null
     **/
    private Promise|null $deferredPromise = null;

    /**
     * Resolve-Function of promise
     *
     * @var Closure|array|null
     **/
    private Closure|array|null $resolveFunction = null;

    /**
     * Reject-Function of promise
     *
     * @var Closure|array|null
     **/
    private Closure|array|null $rejectFunction = null;

    // {{{ __construct
    /**
     * Create a new deferred execution
     * 
     * @param Base|Promise|null $baseOrPromise (optional) An event-base to bind a new promise to or a pre-setup promise (requires $Resolve and $Reject)
     * @param callable $resolveFunction (optional) If $BaseOrPromise, this is a callable to resolve the promise
     * @param callable $rejectFunction (optional) If $BaseOrPromise, this is a callable to reject the promise
     *
     * @access friendly
     * @return void
     **/
    public function __construct (Base|Promise $baseOrPromise = null, callable $resolveFunction = null, callable $rejectFunction = null)
    {
      // Check if a promise was given
      if ($baseOrPromise instanceof Promise) {
        // Make sure the promise was given with fulfillment-functions
        if (
          !$resolveFunction ||
          !$rejectFunction
        )
          throw new InvalidArgumentException ('Promise requires specification of resolve- and reject-functions');

        // Assign the promise
        $this->deferredPromise = $baseOrPromise;
        $this->resolveFunction = $resolveFunction;
        $this->rejectFunction = $rejectFunction;
      // Check if nothing or an event-base was given
      } else
        // Create a new promise
        $this->deferredPromise = new Promise (
          function (callable $resolveFunction, callable $rejectFunction): void
          {
            // Check whether to resolve/reject directly
            if (is_array ($this->resolveFunction))
              call_user_func_array ($resolveFunction, $this->resolveFunction);

            if (is_array ($this->rejectFunction))
              call_user_func_array ($rejectFunction, $this->rejectFunction);

            // Store the callbacks
            $this->resolveFunction = $resolveFunction;
            $this->rejectFunction = $rejectFunction;
          },
          $baseOrPromise
        );
    }
    // }}}

    // {{{ getPromise
    /**
     * Retrieve the promise for this deferred execution
     *
     * @access public
     * @return Events\Promise
     **/
    public function getPromise (): Promise
    {
      return $this->deferredPromise;
    }
    // }}}

    // {{{ resolve
    /**
     * Resolve this deferred execution
     *
     * @param ...
     *
     * @access public
     * @return void
     **/
    public function resolve (): void
    {
      // Check if we may do it directly
      if ($this->resolveFunction instanceof Closure)
        call_user_func_array ($this->resolveFunction, func_get_args ());

      // Store for later execution
      else
        $this->resolveFunction = func_get_args ();
    }
    // }}}

    // {{{ reject
    /**
     * Reject this deferred execution
     *
     * @param ...
     *
     * @access public
     * @return void
     **/
    public function reject (): void
    {
      // Check if we may do it directly
      if ($this->rejectFunction instanceof Closure)
        call_user_func_array ($this->rejectFunction, func_get_args ());

      // Store for later execution
      else
        $this->rejectFunction = func_get_args ();
    }
    // }}}
  }
