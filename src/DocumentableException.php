<?php
namespace pixium\documentable;

use Exception;

/**
 * Description of FriendlyException
 *
 * @author fosales
 */
class DocumentableException extends Exception
{
    const DEXC_NO_SUCH_ATTRIBUTE = 100;
    const DEXC_NOT_DOCUMENTABLE = 101;
    const STD_MSGS = [
        self::DEXC_NO_SUCH_ATTRIBUTE => 'No Such Attribute',
        self::DEXC_NOT_DOCUMENTABLE => 'Not a Documentable model',
    ];

    public function __construct($code, $message = null)
    {
        parent::__construct($message ?? self::STD_MSGS[$code] ?? 'Unspecified reason', $code);
    }
}
