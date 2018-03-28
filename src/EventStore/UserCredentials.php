<?php

declare(strict_types=1);

namespace Prooph\EventStore;

final class UserCredentials
{
    private $username;
    private $password;

    public function __construct(string $username, string $password)
    {
        if (empty($username)) {
            throw new \InvalidArgumentException('Username cannot be empty');
        }

        if (empty($password)) {
            throw new \InvalidArgumentException('Password cannot be empty');
        }

        $this->username = $username;
        $this->password = $password;
    }

    public function username(): string
    {
        return $this->username;
    }

    public function password(): string
    {
        return $this->password;
    }
}
