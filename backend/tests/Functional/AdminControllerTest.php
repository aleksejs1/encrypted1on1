<?php

namespace App\Tests\Functional;

use App\Tests\Support\ApiTestCase;

class AdminControllerTest extends ApiTestCase
{
    public function testListUsersRequires401WhenNotAuthenticated(): void
    {
        $client = static::createClient();
        $result = $this->jsonRequest($client, 'GET', '/api/admin/users');

        self::assertSame(401, $result['status']);
    }

    public function testListUsersRequires403ForANonAdmin(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('admin-non-admin'));

        $result = $this->jsonRequest($client, 'GET', '/api/admin/users');

        self::assertSame(403, $result['status']);
        self::assertSame('Admin only.', $result['json']['error']);
    }

    public function testListUsersSucceedsForAnAdmin(): void
    {
        $client = static::createClient();
        $admin = $this->activateUser($client, $this->uniqueEmail('admin-list'), admin: true);

        $result = $this->jsonRequest($client, 'GET', '/api/admin/users');

        self::assertSame(200, $result['status']);
        $ids = array_column($result['json'], 'id');
        self::assertContains($admin['id'], $ids);
    }

    public function testSetBlockedTogglesTheFlag(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('admin-blocker'), admin: true);

        $other = $this->secondClient();
        $target = $this->activateUser($other, $this->uniqueEmail('admin-target'));

        $block = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$target['id']}/blocked", ['blocked' => true]);
        self::assertSame(200, $block['status']);
        self::assertTrue($block['json']['isBlocked']);

        $unblock = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$target['id']}/blocked", ['blocked' => false]);
        self::assertFalse($unblock['json']['isBlocked']);
    }

    public function testSetBlockedRejectsBlockingYourself(): void
    {
        $client = static::createClient();
        $admin = $this->activateUser($client, $this->uniqueEmail('admin-self-block'), admin: true);

        $result = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$admin['id']}/blocked", ['blocked' => true]);

        self::assertSame(400, $result['status']);
        self::assertSame('You cannot block your own account.', $result['json']['error']);
    }

    public function testSetAdminGrantsTheAdminFlag(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('admin-granter'), admin: true);

        $other = $this->secondClient();
        $target = $this->activateUser($other, $this->uniqueEmail('admin-grantee'));
        self::assertFalse($target['isAdmin']);

        $result = $this->jsonRequest($client, 'PUT', "/api/admin/users/{$target['id']}/admin", ['isAdmin' => true]);

        self::assertSame(200, $result['status']);
        self::assertTrue($result['json']['isAdmin']);
    }

    public function testSetBlockedReturns404ForAnUnknownUser(): void
    {
        $client = static::createClient();
        $this->activateUser($client, $this->uniqueEmail('admin-unknown-target'), admin: true);

        $result = $this->jsonRequest($client, 'PUT', '/api/admin/users/00000000-0000-0000-0000-000000000000/blocked', ['blocked' => true]);

        self::assertSame(404, $result['status']);
    }
}
