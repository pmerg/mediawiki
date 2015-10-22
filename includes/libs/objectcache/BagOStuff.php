<?php
/**
 * Copyright © 2003-2004 Brion Vibber <brion@pobox.com>
 * https://www.mediawiki.org/
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 * @ingroup Cache
 */

/**
 * @defgroup Cache Cache
 */

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * interface is intended to be more or less compatible with
 * the PHP memcached client.
 *
 * backends for local hash array and SQL table included:
 * @code
 *   $bag = new HashBagOStuff();
 *   $bag = new SqlBagOStuff(); # connect to db first
 * @endcode
 *
 * @ingroup Cache
 */
abstract class BagOStuff implements LoggerAwareInterface {
	/** @var array[] Lock tracking */
	protected $locks = array();

	/** @var integer */
	protected $lastError = self::ERR_NONE;

	/** @var string */
	protected $keyspace = 'local';

	/** @var LoggerInterface */
	protected $logger;

	/** @var bool */
	private $debugMode = false;

	/** Possible values for getLastError() */
	const ERR_NONE = 0; // no error
	const ERR_NO_RESPONSE = 1; // no response
	const ERR_UNREACHABLE = 2; // can't connect
	const ERR_UNEXPECTED = 3; // response gave some error

	/** Bitfield constants for get()/getMulti() */
	const READ_LATEST = 1; // use latest data for replicated stores
	const READ_VERIFIED = 2; // promise that caller can tell when keys are stale
	/** Bitfield constants for set()/merge() */
	const WRITE_SYNC = 1; // synchronously write to all locations for replicated stores

	public function __construct( array $params = array() ) {
		if ( isset( $params['logger'] ) ) {
			$this->setLogger( $params['logger'] );
		} else {
			$this->setLogger( new NullLogger() );
		}

		if ( isset( $params['keyspace'] ) ) {
			$this->keyspace = $params['keyspace'];
		}
	}

	/**
	 * @param LoggerInterface $logger
	 * @return null
	 */
	public function setLogger( LoggerInterface $logger ) {
		$this->logger = $logger;
	}

	/**
	 * @param bool $bool
	 */
	public function setDebug( $bool ) {
		$this->debugMode = $bool;
	}

	/**
	 * Get an item with the given key, regenerating and setting it if not found
	 *
	 * If the callback returns false, then nothing is stored.
	 *
	 * @param string $key
	 * @param int $ttl Time-to-live (seconds)
	 * @param callable $callback Callback that derives the new value
	 * @param integer $flags Bitfield of BagOStuff::READ_* constants [optional]
	 * @return mixed The cached value if found or the result of $callback otherwise
	 * @since 1.27
	 */
	final public function getWithSetCallback( $key, $ttl, $callback, $flags = 0 ) {
		$value = $this->get( $key, $flags );

		if ( $value === false ) {
			if ( !is_callable( $callback ) ) {
				throw new InvalidArgumentException( "Invalid cache miss callback provided." );
			}
			$value = call_user_func( $callback );
			if ( $value !== false ) {
				$this->set( $key, $value, $ttl );
			}
		}

		return $value;
	}

	/**
	 * Get an item with the given key
	 *
	 * If the key includes a determistic input hash (e.g. the key can only have
	 * the correct value) or complete staleness checks are handled by the caller
	 * (e.g. nothing relies on the TTL), then the READ_VERIFIED flag should be set.
	 * This lets tiered backends know they can safely upgrade a cached value to
	 * higher tiers using standard TTLs.
	 *
	 * @param string $key
	 * @param integer $flags Bitfield of BagOStuff::READ_* constants [optional]
	 * @param integer $oldFlags [unused]
	 * @return mixed Returns false on failure and if the item does not exist
	 */
	public function get( $key, $flags = 0, $oldFlags = null ) {
		// B/C for ( $key, &$casToken = null, $flags = 0 )
		$flags = is_int( $oldFlags ) ? $oldFlags : $flags;

		return $this->doGet( $key, $flags );
	}

	/**
	 * @param string $key
	 * @param integer $flags Bitfield of BagOStuff::READ_* constants [optional]
	 * @return mixed Returns false on failure and if the item does not exist
	 */
	abstract protected function doGet( $key, $flags = 0 );

	/**
	 * @note: This method is only needed if merge() uses mergeViaCas()
	 *
	 * @param string $key
	 * @param mixed $casToken
	 * @param integer $flags Bitfield of BagOStuff::READ_* constants [optional]
	 * @return mixed Returns false on failure and if the item does not exist
	 * @throws Exception
	 */
	protected function getWithToken( $key, &$casToken, $flags = 0 ) {
		throw new Exception( __METHOD__ . ' not implemented.' );
	}

	/**
	 * Set an item
	 *
	 * @param string $key
	 * @param mixed $value
	 * @param int $exptime Either an interval in seconds or a unix timestamp for expiry
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants
	 * @return bool Success
	 */
	abstract public function set( $key, $value, $exptime = 0, $flags = 0 );

	/**
	 * Delete an item
	 *
	 * @param string $key
	 * @return bool True if the item was deleted or not found, false on failure
	 */
	abstract public function delete( $key );

	/**
	 * Merge changes into the existing cache value (possibly creating a new one).
	 * The callback function returns the new value given the current value
	 * (which will be false if not present), and takes the arguments:
	 * (this BagOStuff, cache key, current value).
	 *
	 * @param string $key
	 * @param callable $callback Callback method to be executed
	 * @param int $exptime Either an interval in seconds or a unix timestamp for expiry
	 * @param int $attempts The amount of times to attempt a merge in case of failure
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants
	 * @return bool Success
	 * @throws InvalidArgumentException
	 */
	public function merge( $key, $callback, $exptime = 0, $attempts = 10, $flags = 0 ) {
		if ( !is_callable( $callback ) ) {
			throw new InvalidArgumentException( "Got invalid callback." );
		}

		return $this->mergeViaLock( $key, $callback, $exptime, $attempts, $flags );
	}

	/**
	 * @see BagOStuff::merge()
	 *
	 * @param string $key
	 * @param callable $callback Callback method to be executed
	 * @param int $exptime Either an interval in seconds or a unix timestamp for expiry
	 * @param int $attempts The amount of times to attempt a merge in case of failure
	 * @return bool Success
	 */
	protected function mergeViaCas( $key, $callback, $exptime = 0, $attempts = 10 ) {
		do {
			$this->clearLastError();
			$casToken = null; // passed by reference
			$currentValue = $this->getWithToken( $key, $casToken, BagOStuff::READ_LATEST );
			if ( $this->getLastError() ) {
				return false; // don't spam retries (retry only on races)
			}

			// Derive the new value from the old value
			$value = call_user_func( $callback, $this, $key, $currentValue );

			$this->clearLastError();
			if ( $value === false ) {
				$success = true; // do nothing
			} elseif ( $currentValue === false ) {
				// Try to create the key, failing if it gets created in the meantime
				$success = $this->add( $key, $value, $exptime );
			} else {
				// Try to update the key, failing if it gets changed in the meantime
				$success = $this->cas( $casToken, $key, $value, $exptime );
			}
			if ( $this->getLastError() ) {
				return false; // IO error; don't spam retries
			}
		} while ( !$success && --$attempts );

		return $success;
	}

	/**
	 * Check and set an item
	 *
	 * @param mixed $casToken
	 * @param string $key
	 * @param mixed $value
	 * @param int $exptime Either an interval in seconds or a unix timestamp for expiry
	 * @return bool Success
	 * @throws Exception
	 */
	protected function cas( $casToken, $key, $value, $exptime = 0 ) {
		throw new Exception( "CAS is not implemented in " . __CLASS__ );
	}

	/**
	 * @see BagOStuff::merge()
	 *
	 * @param string $key
	 * @param callable $callback Callback method to be executed
	 * @param int $exptime Either an interval in seconds or a unix timestamp for expiry
	 * @param int $attempts The amount of times to attempt a merge in case of failure
	 * @param int $flags Bitfield of BagOStuff::WRITE_* constants
	 * @return bool Success
	 */
	protected function mergeViaLock( $key, $callback, $exptime = 0, $attempts = 10, $flags = 0 ) {
		if ( !$this->lock( $key, 6 ) ) {
			return false;
		}

		$this->clearLastError();
		$currentValue = $this->get( $key, BagOStuff::READ_LATEST );
		if ( $this->getLastError() ) {
			$success = false;
		} else {
			// Derive the new value from the old value
			$value = call_user_func( $callback, $this, $key, $currentValue );
			if ( $value === false ) {
				$success = true; // do nothing
			} else {
				$success = $this->set( $key, $value, $exptime, $flags ); // set the new value
			}
		}

		if ( !$this->unlock( $key ) ) {
			// this should never happen
			trigger_error( "Could not release lock for key '$key'." );
		}

		return $success;
	}

	/**
	 * Acquire an advisory lock on a key string
	 *
	 * Note that if reentry is enabled, duplicate calls ignore $expiry
	 *
	 * @param string $key
	 * @param int $timeout Lock wait timeout; 0 for non-blocking [optional]
	 * @param int $expiry Lock expiry [optional]; 1 day maximum
	 * @param string $rclass Allow reentry if set and the current lock used this value
	 * @return bool Success
	 */
	public function lock( $key, $timeout = 6, $expiry = 6, $rclass = '' ) {
		// Avoid deadlocks and allow lock reentry if specified
		if ( isset( $this->locks[$key] ) ) {
			if ( $rclass != '' && $this->locks[$key]['class'] === $rclass ) {
				++$this->locks[$key]['depth'];
				return true;
			} else {
				return false;
			}
		}

		$expiry = min( $expiry ?: INF, 86400 );

		$this->clearLastError();
		$timestamp = microtime( true ); // starting UNIX timestamp
		if ( $this->add( "{$key}:lock", 1, $expiry ) ) {
			$locked = true;
		} elseif ( $this->getLastError() || $timeout <= 0 ) {
			$locked = false; // network partition or non-blocking
		} else {
			$uRTT = ceil( 1e6 * ( microtime( true ) - $timestamp ) ); // estimate RTT (us)
			$sleep = 2 * $uRTT; // rough time to do get()+set()

			$attempts = 0; // failed attempts
			do {
				if ( ++$attempts >= 3 && $sleep <= 5e5 ) {
					// Exponentially back off after failed attempts to avoid network spam.
					// About 2*$uRTT*(2^n-1) us of "sleep" happen for the next n attempts.
					$sleep *= 2;
				}
				usleep( $sleep ); // back off
				$this->clearLastError();
				$locked = $this->add( "{$key}:lock", 1, $expiry );
				if ( $this->getLastError() ) {
					$locked = false; // network partition
					break;
				}
			} while ( !$locked && ( microtime( true ) - $timestamp ) < $timeout );
		}

		if ( $locked ) {
			$this->locks[$key] = array( 'class' => $rclass, 'depth' => 1 );
		}

		return $locked;
	}

	/**
	 * Release an advisory lock on a key string
	 *
	 * @param string $key
	 * @return bool Success
	 */
	public function unlock( $key ) {
		if ( isset( $this->locks[$key] ) && --$this->locks[$key]['depth'] <= 0 ) {
			unset( $this->locks[$key] );

			return $this->delete( "{$key}:lock" );
		}

		return true;
	}

	/**
	 * Get a lightweight exclusive self-unlocking lock
	 *
	 * Note that the same lock cannot be acquired twice.
	 *
	 * This is useful for task de-duplication or to avoid obtrusive
	 * (though non-corrupting) DB errors like INSERT key conflicts
	 * or deadlocks when using LOCK IN SHARE MODE.
	 *
	 * @param string $key
	 * @param int $timeout Lock wait timeout; 0 for non-blocking [optional]
	 * @param int $expiry Lock expiry [optional]; 1 day maximum
	 * @param string $rclass Allow reentry if set and the current lock used this value
	 * @return ScopedCallback|null Returns null on failure
	 * @since 1.26
	 */
	final public function getScopedLock( $key, $timeout = 6, $expiry = 30, $rclass = '' ) {
		$expiry = min( $expiry ?: INF, 86400 );

		if ( !$this->lock( $key, $timeout, $expiry, $rclass ) ) {
			return null;
		}

		$lSince = microtime( true ); // lock timestamp
		// PHP 5.3: Can't use $this in a closure
		$that = $this;
		$logger = $this->logger;

		return new ScopedCallback( function() use ( $that, $logger, $key, $lSince, $expiry ) {
			$latency = .050; // latency skew (err towards keeping lock present)
			$age = ( microtime( true ) - $lSince + $latency );
			if ( ( $age + $latency ) >= $expiry ) {
				$logger->warning( "Lock for $key held too long ($age sec)." );
				return; // expired; it's not "safe" to delete the key
			}
			$that->unlock( $key );
		} );
	}

	/**
	 * Delete all objects expiring before a certain date.
	 * @param string $date The reference date in MW format
	 * @param callable|bool $progressCallback Optional, a function which will be called
	 *     regularly during long-running operations with the percentage progress
	 *     as the first parameter.
	 *
	 * @return bool Success, false if unimplemented
	 */
	public function deleteObjectsExpiringBefore( $date, $progressCallback = false ) {
		// stub
		return false;
	}

	/**
	 * Get an associative array containing the item for each of the keys that have items.
	 * @param array $keys List of strings
	 * @param integer $flags Bitfield; supports READ_LATEST [optional]
	 * @return array
	 */
	public function getMulti( array $keys, $flags = 0 ) {
		$res = array();
		foreach ( $keys as $key ) {
			$val = $this->get( $key );
			if ( $val !== false ) {
				$res[$key] = $val;
			}
		}
		return $res;
	}

	/**
	 * Batch insertion
	 * @param array $data $key => $value assoc array
	 * @param int $exptime Either an interval in seconds or a unix timestamp for expiry
	 * @return bool Success
	 * @since 1.24
	 */
	public function setMulti( array $data, $exptime = 0 ) {
		$res = true;
		foreach ( $data as $key => $value ) {
			if ( !$this->set( $key, $value, $exptime ) ) {
				$res = false;
			}
		}
		return $res;
	}

	/**
	 * @param string $key
	 * @param mixed $value
	 * @param int $exptime
	 * @return bool Success
	 */
	public function add( $key, $value, $exptime = 0 ) {
		if ( $this->get( $key ) === false ) {
			return $this->set( $key, $value, $exptime );
		}
		return false; // key already set
	}

	/**
	 * Increase stored value of $key by $value while preserving its TTL
	 * @param string $key Key to increase
	 * @param int $value Value to add to $key (Default 1)
	 * @return int|bool New value or false on failure
	 */
	public function incr( $key, $value = 1 ) {
		if ( !$this->lock( $key ) ) {
			return false;
		}
		$n = $this->get( $key );
		if ( $this->isInteger( $n ) ) { // key exists?
			$n += intval( $value );
			$this->set( $key, max( 0, $n ) ); // exptime?
		} else {
			$n = false;
		}
		$this->unlock( $key );

		return $n;
	}

	/**
	 * Decrease stored value of $key by $value while preserving its TTL
	 * @param string $key
	 * @param int $value
	 * @return int|bool New value or false on failure
	 */
	public function decr( $key, $value = 1 ) {
		return $this->incr( $key, - $value );
	}

	/**
	 * Increase stored value of $key by $value while preserving its TTL
	 *
	 * This will create the key with value $init and TTL $ttl if not present
	 *
	 * @param string $key
	 * @param int $ttl
	 * @param int $value
	 * @param int $init
	 * @return bool
	 * @since 1.24
	 */
	public function incrWithInit( $key, $ttl, $value = 1, $init = 1 ) {
		return $this->incr( $key, $value ) ||
			$this->add( $key, (int)$init, $ttl ) || $this->incr( $key, $value );
	}

	/**
	 * Get the "last error" registered; clearLastError() should be called manually
	 * @return int ERR_* constant for the "last error" registry
	 * @since 1.23
	 */
	public function getLastError() {
		return $this->lastError;
	}

	/**
	 * Clear the "last error" registry
	 * @since 1.23
	 */
	public function clearLastError() {
		$this->lastError = self::ERR_NONE;
	}

	/**
	 * Set the "last error" registry
	 * @param int $err ERR_* constant
	 * @since 1.23
	 */
	protected function setLastError( $err ) {
		$this->lastError = $err;
	}

	/**
	 * Modify a cache update operation array for EventRelayer::notify()
	 *
	 * This is used for relayed writes, e.g. for broadcasting a change
	 * to multiple data-centers. If the array contains a 'val' field
	 * then the command involves setting a key to that value. Note that
	 * for simplicity, 'val' is always a simple scalar value. This method
	 * is used to possibly serialize the value and add any cache-specific
	 * key/values needed for the relayer daemon (e.g. memcached flags).
	 *
	 * @param array $event
	 * @return array
	 * @since 1.26
	 */
	public function modifySimpleRelayEvent( array $event ) {
		return $event;
	}

	/**
	 * @param string $text
	 */
	protected function debug( $text ) {
		if ( $this->debugMode ) {
			$this->logger->debug( "{class} debug: $text", array(
				'class' => get_class( $this ),
			) );
		}
	}

	/**
	 * Convert an optionally relative time to an absolute time
	 * @param int $exptime
	 * @return int
	 */
	protected function convertExpiry( $exptime ) {
		if ( ( $exptime != 0 ) && ( $exptime < 86400 * 3650 /* 10 years */ ) ) {
			return time() + $exptime;
		} else {
			return $exptime;
		}
	}

	/**
	 * Convert an optionally absolute expiry time to a relative time. If an
	 * absolute time is specified which is in the past, use a short expiry time.
	 *
	 * @param int $exptime
	 * @return int
	 */
	protected function convertToRelative( $exptime ) {
		if ( $exptime >= 86400 * 3650 /* 10 years */ ) {
			$exptime -= time();
			if ( $exptime <= 0 ) {
				$exptime = 1;
			}
			return $exptime;
		} else {
			return $exptime;
		}
	}

	/**
	 * Check if a value is an integer
	 *
	 * @param mixed $value
	 * @return bool
	 */
	protected function isInteger( $value ) {
		return ( is_int( $value ) || ctype_digit( $value ) );
	}

	/**
	 * Construct a cache key.
	 *
	 * @since 1.27
	 * @param string $keyspace
	 * @param array $args
	 * @return string
	 */
	public function makeKeyInternal( $keyspace, $args ) {
		$key = $keyspace . ':' . implode( ':', $args );
		return strtr( $key, ' ', '_' );
	}

	/**
	 * Make a global cache key.
	 *
	 * @since 1.27
	 * @param string ... Key component (variadic)
	 * @return string
	 */
	public function makeGlobalKey() {
		return $this->makeKeyInternal( 'global', func_get_args() );
	}

	/**
	 * Make a cache key, scoped to this instance's keyspace.
	 *
	 * @since 1.27
	 * @param string ... Key component (variadic)
	 * @return string
	 */
	public function makeKey() {
		return $this->makeKeyInternal( $this->keyspace, func_get_args() );
	}
}