<?php
namespace pixium\documentable\interfaces;

interface HasherInterface
{
    /**
     * @param int $seed (id to convert to a hash)
     * @return string hash
     */
    public static function id2h($seed = 0);

    /**
     * @param string $hash (hash to convert to a id)
     * @return int id
     * @throws Exception if format is wrong or fake hash
     */
    public static function h2id($hash);

    /**
     * @param $v test value
     * @return string|false false if the format isn't good (worng prefix r size)
     */
    public static function isHash($v);
}
