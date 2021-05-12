<?php
// namespace common\services;
namespace pixium\documentable\models;

use Exception;
use \pixium\documentable\interfaces\HasherInterface;

/**
 * @author lionel.aimerie@pixiumdigital (2020-05-31)
 */
class Hasher implements HasherInterface
{
    const USE_RANDOM_HASH = false; // false = make the hash unique to allow image caching per url
    const PREFIX = 'X';
    const QUIET = true; // return null instead of throwing exceptions
    // RANDOM[14]+MEANINGFUL(X)+RANDOM[18-X] : total 32chars long
//
    /**
     * Cypher id into reversible
     * @param integer $id
     * @return string hex-hash
     */
    public static function id2h($seed = 0)
    {
        //
        // 123
        $seed = (int) $seed;
        $cryptokey = self::USE_RANDOM_HASH
            ? md5(random_bytes(64))
            : md5($seed); // => 32c
        $d = (hexdec($cryptokey[0]) % 15) + 1;
        $hexseed = dechex($seed); // hexadecimal representation of seed [65536 => 4bytes]
        $hexlen = strlen($hexseed); // length of hexseed
        // hide number of 1/2 bytes required to code the seed
        $m = hexdec(substr($cryptokey, $d, 2)) & ~0x3c | ($hexlen << 2);
        $hexm = str_pad(dechex($m), 2, '0', STR_PAD_LEFT);
        // 1/2byte checksum ($d + $m + $seed)
        $cs = (hexdec($cryptokey[0]) + $m + $seed) % 0x10;
        $hexcs = dechex($cs);
        $res = substr($cryptokey, 0, $d).$hexm.$hexseed.$hexcs;
        $res = $res.substr($cryptokey, -(32 - strlen($res)));
        return self::PREFIX.$res;
    }

    /**
     * reverse cypher
     * hash to id
     * @param string hash
     * @return mixed id or null if not available
     * @throws Exception if format is wrong or fake hash
     */
    public static function h2id($hash)
    {
        $hash = self::isHash($hash);
        if (false === $hash) {
            if (self::QUIET) {
                return null;
            }
            throw new Exception("Hashed - hash of invalid format - [{$hash}]");
        }

        if (null !== $hash && $hash[0]) {
            if ((strlen($hash) != 32) // test if right length
        || !ctype_xdigit($hash) // test if hex
        ) {
            }
        }
        $d = (hexdec($hash[0]) % 15) + 1;
        $hexm = substr($hash, $d, 2);
        $m = hexdec($hexm);
        $seedlen = ($m & 0x3c) >> 2;
        $hexseed = substr($hash, $d + 2, $seedlen);
        $seed = hexdec($hexseed);
        $cs2match = hexdec($hash[$d + 2 + $seedlen]);
        //echo "d:${d} seed.len=${seedlen} seed=${seed}\n";
        $cs = (hexdec($hash[0]) + $m + $seed) % 0x10;
        //echo ($cs2match == $cs ? "cs match" : "no-match!") . "\n";
        if ($cs2match != $cs) {
            if (self::QUIET) {
                return null;
            }
            throw new Exception("Hashed - bad hash - [{$hash}]");
        }

        return $seed;
    }

    /**
     * ret
     * @param $v test value
     * @return string|false false if the format isn't good (worng prefix r size)
     */
    public static function isHash($v)
    {
        $len = strlen(self::PREFIX);
        if ((null === $v)
        || (strlen($v) < $len + 32)
        || (self::PREFIX != substr($v, 0, $len))
        ) {
            return false;
        }
        $meaningful = substr($v, $len);
        if (!ctype_xdigit($meaningful)) {
            return false;
        }
        return $meaningful;
    }
}
