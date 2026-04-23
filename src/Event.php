<?php

declare(strict_types=1);

namespace Bk203\Vici;

/**
 * Named constants for the server-issued VICI events.
 *
 * @see https://github.com/strongswan/strongswan/blob/master/src/libcharon/plugins/vici/README.md
 */
final class Event
{
    public const string LOG = 'log';
    public const string CONTROL_LOG = 'control-log';
    public const string LIST_SA = 'list-sa';
    public const string LIST_POLICY = 'list-policy';
    public const string LIST_CONN = 'list-conn';
    public const string LIST_CERT = 'list-cert';
    public const string LIST_AUTHORITY = 'list-authority';
    public const string IKE_UPDOWN = 'ike-updown';
    public const string IKE_REKEY = 'ike-rekey';
    public const string IKE_UPDATE = 'ike-update';
    public const string CHILD_UPDOWN = 'child-updown';
    public const string CHILD_REKEY = 'child-rekey';
    public const string ALERT = 'alert';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        return [
            self::LOG,
            self::CONTROL_LOG,
            self::LIST_SA,
            self::LIST_POLICY,
            self::LIST_CONN,
            self::LIST_CERT,
            self::LIST_AUTHORITY,
            self::IKE_UPDOWN,
            self::IKE_REKEY,
            self::IKE_UPDATE,
            self::CHILD_UPDOWN,
            self::CHILD_REKEY,
            self::ALERT,
        ];
    }

    private function __construct()
    {
    }
}
