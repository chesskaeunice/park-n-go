<?php
declare(strict_types=1);
namespace ParkNGo\Services\Admin;

/** Thrown when an authenticated administrator lacks the explicit permission a sensitive action needs. */
final class PermissionDenied extends \RuntimeException
{
    public function __construct(public readonly string $permission)
    {
        parent::__construct('Your administrator account does not have the ' . AdminPermissions::label($permission) . ' permission.');
    }
}
