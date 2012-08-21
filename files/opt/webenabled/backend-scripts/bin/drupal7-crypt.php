#!/opt/webenabled/config/os/pathnames/bin/php -q
<?php
// $Id: password.inc,v 1.4 2008/12/20 18:24:33 dries Exp $

/**
 * Returns a string of highly randomized bytes (over the full 8-bit range).
 *
 * This function is better than simply calling mt_rand() or any other built-in
 * PHP function because it can return a long string of bytes (compared to < 4
 * bytes normally from mt_rand()) and uses the best available pseudo-random source.
 *
 * @param $count
 *   The number of characters (bytes) to return in the string.
 */
function drupal_random_bytes($count)  {
  static $random_state;
  // We initialize with the somewhat random PHP process ID on the first call.
  if (empty($random_state)) {
    $random_state = getmypid();
  }
  $output = '';
  // /dev/urandom is available on many *nix systems and is considered the best
  // commonly available pseudo-random source.
  if ($fh = @fopen('/dev/urandom', 'rb')) {
    $output = fread($fh, $count);
    fclose($fh);
  }
  // If /dev/urandom is not available or returns no bytes, this loop will
  // generate a good set of pseudo-random bytes on any system.
  // Note that it may be important that our $random_state is passed
  // through md5() prior to being rolled into $output, that the two md5()
  // invocations are different, and that the extra input into the first one -
  // the microtime() - is prepended rather than appended. This is to avoid
  // directly leaking $random_state via the $output stream, which could
  // allow for trivial prediction of further "random" numbers.
  while (strlen($output) < $count) {
    $random_state = md5(microtime() . mt_rand() . $random_state);
    $output .= md5(mt_rand() . $random_state, TRUE);
  }
  return substr($output, 0, $count);
}

/**
 * @file
 * Secure password hashing functions for user authentication.
 *
 * Based on the Portable PHP password hashing framework.
 * @see http://www.openwall.com/phpass/
 *
 * An alternative or custom version of this password hashing API may be
 * used by setting the variable password_inc to the name of the PHP file
 * containing replacement user_hash_password(), user_check_password(), and
 * user_needs_new_hash() functions.
 */

/**
 * The standard log2 number of iterations for password stretching. This should
 * increase by 1 at least every other Drupal version in order to counteract
 * increases in the speed and power of computers available to crack the hashes.
 */
define('DRUPAL_HASH_COUNT', 14);

/**
 * The minimum allowed log2 number of iterations for password stretching.
 */
define('DRUPAL_MIN_HASH_COUNT', 7);

/**
 * The maximum allowed log2 number of iterations for password stretching.
 */
define('DRUPAL_MAX_HASH_COUNT', 30);

/**
 * Returns a string for mapping an int to the corresponding base 64 character.
 */
function _password_itoa64() {
  return './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
}

/**
 * Encode bytes into printable base 64 using the *nix standard from crypt().
 *
 * @param $input
 *   The string containing bytes to encode.
 * @param $count
 *   The number of characters (bytes) to encode.
 *
 * @return
 *   Encoded string
 */
function _password_base64_encode($input, $count)  {
  $output = '';
  $i = 0;
  $itoa64 = _password_itoa64();
  do {
    $value = ord($input[$i++]);
    $output .= $itoa64[$value & 0x3f];
    if ($i < $count) {
      $value |= ord($input[$i]) << 8;
    }
    $output .= $itoa64[($value >> 6) & 0x3f];
    if ($i++ >= $count) {
      break;
    }
    if ($i < $count) {
      $value |= ord($input[$i]) << 16;
    }
    $output .= $itoa64[($value >> 12) & 0x3f];
    if ($i++ >= $count) {
      break;
    }
    $output .= $itoa64[($value >> 18) & 0x3f];
  } while ($i < $count);

  return $output;
}

/**
 * Generates a random base 64-encoded salt prefixed with settings for the hash.
 *
 * Proper use of salts may defeat a number of attacks, including:
 *  - The ability to try candidate passwords against multiple hashes at once.
 *  - The ability to use pre-hashed lists of candidate passwords.
 *  - The ability to determine whether two users have the same (or different)
 *    password without actually having to guess one of the passwords.
 *
 * @param $count_log2
 *   Integer that determines the number of iterations used in the hashing
 *   process. A larger value is more secure, but takes more time to complete.
 *
 * @return
 *   A 12 character string containing the iteration count and a random salt.
 */
function _password_generate_salt($count_log2) {
  $output = '$P$';
  // Minimum log2 iterations is DRUPAL_MIN_HASH_COUNT.
  $count_log2 = max($count_log2, DRUPAL_MIN_HASH_COUNT);
  // Maximum log2 iterations is DRUPAL_MAX_HASH_COUNT.
  // We encode the final log2 iteration count in base 64.
  $itoa64 = _password_itoa64();
  $output .= $itoa64[min($count_log2, DRUPAL_MAX_HASH_COUNT)];
  // 6 bytes is the standard salt for a portable phpass hash.
  $output .= _password_base64_encode(drupal_random_bytes(6), 6);
  return $output;
}

/**
 * Hash a password using a secure stretched hash.
 *
 * By using a salt and repeated hashing the password is "stretched". Its
 * security is increased because it becomes much more computationally costly
 * for an attacker to try to break the hash by brute-force computation of the
 * hashes of a large number of plain-text words or strings to find a match.
 *
 * @param $password
 *   The plain-text password to hash.
 * @param $setting
 *   An existing hash or the output of _password_generate_salt().
 *
 * @return
 *   A string containing the hashed password (and salt) or FALSE on failure.
 */
function _password_crypt($password, $setting)  {
  // The first 12 characters of an existing hash are its setting string.
  $setting = substr($setting, 0, 12);

  if (substr($setting, 0, 3) != '$P$') {
    return FALSE;
  }
  $count_log2 = _password_get_count_log2($setting);
  // Hashes may be imported from elsewhere, so we allow != DRUPAL_HASH_COUNT
  if ($count_log2 < DRUPAL_MIN_HASH_COUNT || $count_log2 > DRUPAL_MAX_HASH_COUNT) {
    return FALSE;
  }
  $salt = substr($setting, 4, 8);
  // Hashes must have an 8 character salt.
  if (strlen($salt) != 8) {
    return FALSE;
  }

  // We must use md5() or sha1() here since they are the only cryptographic
  // primitives always available in PHP 5. To implement our own low-level
  // cryptographic function in PHP would result in much worse performance and
  // consequently in lower iteration counts and hashes that are quicker to crack
  // (by non-PHP code).

  $count = 1 << $count_log2;

  $hash = md5($salt . $password, TRUE);
  do {
    $hash = md5($hash . $password, TRUE);
  } while (--$count);

  $output =  $setting . _password_base64_encode($hash, 16);
  // _password_base64_encode() of a 16 byte MD5 will always be 22 characters.
  return (strlen($output) == 34) ? $output : FALSE;
}

/**
 * Parse the log2 iteration count from a stored hash or setting string.
 */
function _password_get_count_log2($setting) {
  $itoa64 = _password_itoa64();
  return strpos($itoa64, $setting[3]);
}

/**
 * Hash a password using a secure hash.
 *
 * @param $password
 *   A plain-text password.
 * @param $count_log2
 *   Optional integer to specify the iteration count. Generally used only during
 *   mass operations where a value less than the default is needed for speed.
 *
 * @return
 *   A string containing the hashed password (and a salt), or FALSE on failure.
 */
function user_hash_password($password, $count_log2 = 0) {
  if (empty($count_log2)) {
    // Use the standard iteration count.
    $count_log2 = variable_get('password_count_log2', DRUPAL_HASH_COUNT);
  }
  return _password_crypt($password, _password_generate_salt($count_log2));
}

/**
 * Check whether a user's hashed password needs to be replaced with a new hash.
 *
 * This is typically called during the login process when the plain text
 * password is available. A new hash is needed when the desired iteration count
 * has changed through a change in the variable password_count_log2 or
 * DRUPAL_HASH_COUNT or if the user's password hash was generated in an update
 * like user_update_7000().
 *
 * Alternative implementations of this function might use other criteria based
 * on the fields in $account.
 *
 * @param $account
 *   A user object with at least the fields from the {users} table.
 *
 * @return
 *   TRUE or FALSE.
 */
function user_needs_new_hash($account) {
  // Check whether this was an updated password.
  if ((substr($account->pass, 0, 3) != '$P$') || (strlen($account->pass) != 34)) {
    return TRUE;
  }
  // Check whether the iteration count used differs from the standard number.
  return (_password_get_count_log2($account->pass) != variable_get('password_count_log2', DRUPAL_HASH_COUNT));
}

$f = STDIN;
$password = fgets($f);
$value = _password_crypt($password, _password_generate_salt(DRUPAL_HASH_COUNT));
echo("$value\n");
?>
