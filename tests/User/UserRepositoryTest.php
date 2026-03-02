<?php

declare(strict_types=1);

namespace Tests\User;

use PHPUnit\Framework\TestCase;
use Prosper202\User\InMemoryUserRepository;
use RuntimeException;

final class UserRepositoryTest extends TestCase
{
    private function makeRepo(): InMemoryUserRepository
    {
        return new InMemoryUserRepository();
    }

    private function createTestUser(InMemoryUserRepository $repo, string $name = 'testuser'): int
    {
        return $repo->create([
            'fname' => 'Test',
            'lname' => 'User',
            'name' => $name,
            'password' => 'secret123',
            'email' => "$name@example.com",
            'timezone' => 'UTC',
        ]);
    }

    // --- CRUD ---

    public function testCreateReturnsSequentialIds(): void
    {
        $repo = $this->makeRepo();

        $id1 = $this->createTestUser($repo, 'user1');
        $id2 = $this->createTestUser($repo, 'user2');

        self::assertSame(1, $id1);
        self::assertSame(2, $id2);
    }

    public function testCreateStoresUserData(): void
    {
        $repo = $this->makeRepo();
        $id = $this->createTestUser($repo);

        $user = $repo->findById($id);

        self::assertNotNull($user);
        self::assertSame('Test', $user['user_fname']);
        self::assertSame('User', $user['user_lname']);
        self::assertSame('testuser', $user['user_name']);
        self::assertSame('testuser@example.com', $user['user_email']);
        self::assertSame('UTC', $user['user_timezone']);
        self::assertSame(0, $user['user_deleted']);
    }

    public function testCreateInitializesPreferences(): void
    {
        $repo = $this->makeRepo();
        $id = $this->createTestUser($repo);

        $prefs = $repo->getPreferences($id);

        self::assertNotNull($prefs);
        self::assertSame($id, $prefs['user_id']);
    }

    public function testFindByIdReturnsNullForNonexistent(): void
    {
        $repo = $this->makeRepo();

        self::assertNull($repo->findById(999));
    }

    public function testListReturnsUsersWithTotal(): void
    {
        $repo = $this->makeRepo();
        $this->createTestUser($repo, 'user1');
        $this->createTestUser($repo, 'user2');
        $this->createTestUser($repo, 'user3');

        $result = $repo->list(0, 10);

        self::assertSame(3, $result['total']);
        self::assertCount(3, $result['rows']);
    }

    public function testListRespectsPagination(): void
    {
        $repo = $this->makeRepo();
        $this->createTestUser($repo, 'user1');
        $this->createTestUser($repo, 'user2');
        $this->createTestUser($repo, 'user3');

        $result = $repo->list(1, 1);

        self::assertSame(3, $result['total']);
        self::assertCount(1, $result['rows']);
    }

    public function testUpdateModifiesFields(): void
    {
        $repo = $this->makeRepo();
        $id = $this->createTestUser($repo);

        $repo->update($id, ['user_fname' => 'Updated', 'user_email' => 'new@example.com']);

        $user = $repo->findById($id);
        self::assertSame('Updated', $user['user_fname']);
        self::assertSame('new@example.com', $user['user_email']);
        self::assertSame('User', $user['user_lname']); // unchanged
    }

    public function testUpdateThrowsWhenNoFields(): void
    {
        $repo = $this->makeRepo();
        $id = $this->createTestUser($repo);

        $this->expectException(RuntimeException::class);
        $repo->update($id, []);
    }

    // --- Soft Delete ---

    public function testSoftDeleteHidesUser(): void
    {
        $repo = $this->makeRepo();
        $id = $this->createTestUser($repo);

        $repo->softDelete($id);

        self::assertNull($repo->findById($id));
    }

    public function testSoftDeleteExcludesFromList(): void
    {
        $repo = $this->makeRepo();
        $id1 = $this->createTestUser($repo, 'user1');
        $this->createTestUser($repo, 'user2');

        $repo->softDelete($id1);

        $result = $repo->list(0, 10);
        self::assertSame(1, $result['total']);
    }

    // --- Roles ---

    public function testAssignAndRemoveRole(): void
    {
        $repo = $this->makeRepo();
        $id = $this->createTestUser($repo);

        $repo->assignRole($id, 1);
        $repo->assignRole($id, 2);

        self::assertCount(2, $repo->userRoles);

        $repo->removeRole($id, 1);

        self::assertCount(1, $repo->userRoles);
        self::assertSame(2, $repo->userRoles[0]['role_id']);
    }

    public function testAssignRoleIsIdempotent(): void
    {
        $repo = $this->makeRepo();
        $id = $this->createTestUser($repo);

        $repo->assignRole($id, 1);
        $repo->assignRole($id, 1); // duplicate

        self::assertCount(1, $repo->userRoles);
    }

    public function testListRolesReturnsConfiguredRoles(): void
    {
        $repo = $this->makeRepo();
        $repo->roles = [
            ['role_id' => 1, 'role_name' => 'admin'],
            ['role_id' => 2, 'role_name' => 'user'],
        ];

        $roles = $repo->listRoles();
        self::assertCount(2, $roles);
        self::assertSame('admin', $roles[0]['role_name']);
    }

    // --- API Keys ---

    public function testCreateAndDeleteApiKey(): void
    {
        $repo = $this->makeRepo();
        $userId = $this->createTestUser($repo);

        $apiKey = $repo->createApiKey($userId, 'test-key');
        self::assertNotEmpty($apiKey);

        $keys = $repo->listApiKeys($userId);
        self::assertCount(1, $keys);
        self::assertSame($apiKey, $keys[0]['api_key']);
        self::assertSame($userId, $keys[0]['user_id']);

        $repo->deleteApiKey($apiKey, $userId);

        self::assertCount(0, $repo->listApiKeys($userId));
    }

    public function testDeleteApiKeyRequiresCorrectUser(): void
    {
        $repo = $this->makeRepo();
        $userId = $this->createTestUser($repo);
        $apiKey = $repo->createApiKey($userId, 'key');

        $repo->deleteApiKey($apiKey, 9999); // wrong user

        self::assertCount(1, $repo->listApiKeys($userId), 'Key should not be deleted by wrong user');
    }

    public function testApiKeysAreIsolatedByUser(): void
    {
        $repo = $this->makeRepo();
        $u1 = $this->createTestUser($repo, 'user1');
        $u2 = $this->createTestUser($repo, 'user2');

        $repo->createApiKey($u1, 'key1');
        $repo->createApiKey($u2, 'key2');

        self::assertCount(1, $repo->listApiKeys($u1));
        self::assertCount(1, $repo->listApiKeys($u2));
    }

    // --- Preferences ---

    public function testUpdateAndGetPreferences(): void
    {
        $repo = $this->makeRepo();
        $id = $this->createTestUser($repo);

        $repo->updatePreferences($id, [
            'user_pref_limit' => 50,
            'user_tracking_domain' => 'track.example.com',
        ]);

        $prefs = $repo->getPreferences($id);
        self::assertSame(50, $prefs['user_pref_limit']);
        self::assertSame('track.example.com', $prefs['user_tracking_domain']);
    }

    public function testUpdatePreferencesThrowsWhenNoValidFields(): void
    {
        $repo = $this->makeRepo();
        $id = $this->createTestUser($repo);

        $this->expectException(RuntimeException::class);
        $repo->updatePreferences($id, ['invalid_field' => 'value']);
    }

    public function testGetPreferencesReturnsNullForNonexistentUser(): void
    {
        $repo = $this->makeRepo();

        self::assertNull($repo->getPreferences(999));
    }
}
