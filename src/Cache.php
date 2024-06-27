<?php

  /**
   * quarxConnect Events - Key/Value Cache with TTL
   * Copyright (C) 2015-2023 Bernd Holzmueller <bernd@quarxconnect.de>
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

  declare (strict_types=1);

  namespace quarxConnect\Events;

  use quarxConnect\Events\Cache\Event\KeyAdded;
  use quarxConnect\Events\Cache\Event\KeyChanged;
  use quarxConnect\Events\Cache\Event\KeyExpired;
  use quarxConnect\Events\Cache\Event\KeyRemoved;
  use quarxConnect\Events\Cache\Exception\MissingCallback;
  use quarxConnect\Events\Cache\Exception\NotFound;

  use Throwable;

  class Cache extends Emitter {
    use Feature\Based;

    /* Callbacks */
    private $lookupFunction = null;
    private $expireFunction = null;

    /* Values on this cache */
    private $cachedValues = [ ];

    /* Timers for keys on this cache */
    private $expireTimers = [ ];

    /* Default TTLs for Keys on this cache */
    private $defaultTTL = 60.0;

    // {{{ __construct
    /**
     * Create a new key-value-cache
     *
     * @param Base $eventBase
     * @param callable $lookupFunction (optional)
     * @param callable $expireFunction (optional)
     *
     * The lookup-function-callback will be called in the form of
     *
     *   function (string $lookupKey, int $TTL): mixed|Promise
     *
     * The expire-function-callback will be called in the form of
     *
     *   function (string $lookupKey, mixed $Value): bool|Promise
     *
     * If the callback returns or resolves to false, the $Key will be removed from cache, otherwise it will be
     * renewed for the specified TTL for the $Key.
     *
     * @access friendly
     * @return void
     **/
    function __construct (
      Base $eventBase,
      callable $lookupFunction = null,
      callable $expireFunction = null
    ) {
      $this->setEventBase ($eventBase);

      $this->lookupFunction = $lookupFunction;
      $this->expireFunction = $expireFunction;
    }
    // }}}

    // {{{ lookup
    /**
     * Lookup a key on this cache
     *
     * @param string $lookupKey
     *
     * @access public
     * @return Promise
     **/
    public function lookup (string $lookupKey): Promise {
      // Check if the key is already known
      if ($this->have ($lookupKey))
        return Promise::resolve (
          $this->get ($lookupKey)
        );

      // Check if there is a lookup-function to ask
      if (!$this->lookupFunction)
        return Promise::reject (
          new MissingCallback ('Could not lookup key', $lookupKey)
        );

      // Forward to lookup-function
      try {
        $lookupResult = call_user_func (
          $this->lookupFunction,
          $lookupKey,
          $this->defaultTTL
        );

        if ($lookupResult instanceof Promise)
          return $lookupResult->then (
            function (mixed $lookupResult, float $customTTL = null)
            use ($lookupKey): mixed {
              // Store the key here
              $this->set ($lookupKey, $lookupResult, $customTTL);

              // Just forward
              return $lookupResult;
            },
            fn (Throwable $lookupException) => $lookupException instanceof NotFound ? $lookupException : new NotFound ($lookupKey . ' was not found', $lookupKey, $lookupException)
          );

        // Store the key here
        $this->set ($lookupKey, $lookupResult, $this->defaultTTL);

        // Just forward
        return Promise::resolve ($lookupResult);
      } catch (Throwable $lookupError) {
        if (!($lookupError instanceof NotFound))
          $lookupError = new NotFound ('Exception during lookup', $lookupKey, $lookupError);

        return Promise::reject ($lookupError);
      }
    }
    // }}}

    // {{{ have
    /**
     * Check if a given key is known on this cache
     *
     * @param string $lookupKey
     *
     * @access public
     * @return bool
     **/
    public function have (string $lookupKey): bool {
      return isset ($this->cachedValues [$lookupKey]);
    }
    // }}}

    // {{{ get
    /**
     * Retrieve the value from a given key
     *
     * @param string $lookupKey
     *
     * @access public
     * @return mixed
     * 
     * @throws NotFound
     **/
    public function get (string $lookupKey): mixed {
      if (!isset ($this->cachedValues [$lookupKey]))
        throw new NotFound ($lookupKey . ' was not found', $lookupKey);

      return $this->cachedValues [$lookupKey];
    }
    // }}}

    // {{{ set
    /**
     * Set a new value for a given key
     *
     * @param string $lookupKey
     * @param mixed $lookupValue
     * @param float $customTTL (optional)
     *
     * @access public
     * @return Promise
     **/
    public function set (string $lookupKey, mixed $lookupValue, float $customTTL = null): Promise {
      // Check if the key is known
      $isExisting = isset ($this->cachedValues [$lookupKey]);

      if ($isExisting) {
        // Make sure we have a valid TTL
        if ($customTTL !== null)
          $this->expireTimers [$lookupKey]->setInterval ($customTTL);

        // Restart the timer
        $this->expireTimers [$lookupKey]->restart ();
      } else {
        // Make sure we have a valid TTL
        if ($customTTL === null)
          $customTTL = $this->defaultTTL;

        // Enqueue an expiry-timer
        $this->expireTimers [$lookupKey] = $this->getEventBase ()->addTimeout ($customTTL);
        $this->expireTimers [$lookupKey]->then (
          function () use ($lookupKey): void {
            // Check if the key is cached
            if (!isset ($this->cachedValues [$lookupKey])) {
              // Check for a stale timer and clean up (should never happen though)
              if (isset ($this->expireTimers [$lookupKey])) {
                $this->expireTimers [$lookupKey]->cancel ();

                unset ($this->expireTimers [$lookupKey]);
              }

              return;
            }

            // Check if there is an expire-function set
            if ($this->expireFunction === null) {
              $this->expireKey ($lookupKey);
              
              return;
            }

            try {
              // Call the expire-function
              $expireResult = call_user_func ($this->expireFunction, $lookupKey, $this->cachedValues [$lookupKey]);

              // Check if the value should be kept
              if ($expireResult === true)
                $this->expireTimers [$lookupKey]->restart ();

              // Wait for a promise to either renew the lease or to expire the key
              elseif ($expireResult instanceof Promise)
                $expireResult->then (
                  fn () => $this->expireTimers [$lookupKey]->restart (),
                  fn () => $this->expireKey ($lookupKey)
                );
              
              // Just expire in all other cases
              else
                $this->expireKey ($lookupKey);
            } catch (Throwable $callbackException) {
              $this->expireKey ($lookupKey);
            }
          }
        );
      }

      // Store the new value (and keep the old one for a moment)
      $oldValue = $this->cachedValues [$lookupKey] ?? null;
      $this->cachedValues [$lookupKey] = $lookupValue;

      // Dispatch events for this
      /* If one of these events fail, the key will be removed again */
      if (!$isExisting)
        $eventPromise = $this->dispatch (new KeyAdded ($lookupKey, $lookupValue));
      else
        $eventPromise = Promise::resolve ();

      return $eventPromise->then (
        fn () => $this->dispatch (new KeyChanged ($lookupKey, $lookupValue, $oldValue)),
        fn (Throwable $eventException) => $this->unset ($lookupKey)->then (
          fn () => throw $eventException
        )
      )->then (
        fn () => null
      );
    }
    // }}}

    // {{{ expireKey
    /**
     * Attempt to remove an expired key from our cache
     *
     * @param string $lookupKey
     *
     * @access private
     * @return void
     **/
    private function expireKey (string $lookupKey): void {
      // Make sure the key is valid
      if (!$this->have ($lookupKey))
        return;

      // Dispatch Event first and remove unless it fails
      $this->dispatch (
        new KeyExpired ($lookupKey, $this->get ($lookupKey))
      )->then (
        fn () => $this->unset ($lookupKey)
      );
    }
    
    // {{{ unset
    /**
     * Remove a cached value
     * 
     * @param string $lookupKey
     * 
     * @access public
     * @return Promise
     **/
    public function unset (string $lookupKey): Promise {
      // Make sure the key is still known
      if (!isset ($this->cachedValues [$lookupKey]))
        return Promise::resolve ();
      
      // Stop the timer
      $this->expireTimers [$lookupKey]->cancel ();
      
      // Remove the value
      $oldValue = $this->cachedValues [$lookupKey];

      unset (
        $this->cachedValues [$lookupKey],
        $this->expireTimers [$lookupKey]
      );
      
      // Dispatch events
      return $this->dispatch (
        new KeyRemoved ($lookupKey, $oldValue)
      );
    }
    // }}}
    
    // {{{ prune
    /**
     * Remove all key-values from this cache
     * 
     * @access public
     * @return void
     **/
    public function prune (): Promise {
      $allPromises = [];

      foreach (array_keys ($this->cachedValues) as $lookupKey)
        $allPromises [] = $this->unset ($lookupKey);
      
      return Promise::all ($allPromises);
    }
    // }}}
  }
