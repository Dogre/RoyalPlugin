<?php

namespace Dog\Helper;

/**
 * A helper lib to convert account ids to logins and vice versa.
 * All credits to Beu.
 */

class Account
{
    public static function toAccountId(string $login)
    {
        $login = str_pad($login, 24, "=", STR_PAD_RIGHT);

        $login = str_replace("_", "/", str_replace("-", "+", $login));
        $login = base64_decode($login);
        $accountid = vsprintf("%s%s-%s-%s-%s-%s%s%s", str_split(bin2hex($login), 4));

        return $accountid;
    }

    public static function fromAccountId(string $accountId)
    {
        $accountId = str_replace("-", "", $accountId);
        $login = "";
        foreach (str_split($accountId, 2) as $pair) {
            $login .= chr(hexdec($pair));
        }
        $login = base64_encode($login);
        $login = str_replace("+", "-", str_replace("/", "_", $login));
        $login = trim($login, "=");

        return $login;
    }
}
