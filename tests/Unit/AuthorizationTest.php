<?php

namespace Doppar\Authorizer\Tests\Unit;

use Doppar\Authorizer\Authorizer;
use PHPUnit\Framework\TestCase;

class AuthorizationTest extends TestCase
{
    private Authorizer $authorizer;

    protected function setUp(): void
    {
        $this->authorizer = new Authorizer();
    }

    public function testPolicyRegistrationAndResolution()
    {
        $policy = new class {
            public function edit($user, $model)
            {
                return $user->id === $model->owner_id;
            }
        };

        $model = new class {
            public $owner_id = 1;
        };

        $user = new class {
            public $id = 1;
        };

        $this->authorizer->authorize(get_class($model), get_class($policy));
        $this->assertSame([get_class($model) => get_class($policy)], $this->authorizer->policies());
    }

    public function testAbilityDefinitionAndChecking()
    {
        $this->authorizer->define('edit-settings', function ($user) {
            return $user->isAdmin;
        });

        $adminUser = new class {
            public $isAdmin = true;
        };
        $regularUser = new class {
            public $isAdmin = false;
        };

        $this->authorizer->resolveUserUsing(fn() => $adminUser);
        $this->assertTrue($this->authorizer->allows('edit-settings'));

        $this->authorizer->resolveUserUsing(fn() => $regularUser);
        $this->assertFalse($this->authorizer->allows('edit-settings'));
        $this->assertTrue($this->authorizer->denies('edit-settings'));
    }

    public function testTemporaryAbilities()
    {
        $called = false;
        $this->authorizer->temporary('temp-ability', function () use (&$called) {
            $called = true;
            return true;
        });

        $this->assertTrue($this->authorizer->allows('temp-ability'));
        $this->assertTrue($called);

        // Should be removed after first check
        $this->assertFalse($this->authorizer->hasAbility('temp-ability'));
    }

    public function testAbilityHierarchy()
    {
        $this->authorizer->define('admin', fn($user) => $user->isAdmin);
        $this->authorizer->inherit('admin', ['manage-users', 'manage-settings']);

        $adminUser = new class {
            public $isAdmin = true;
        };
        $this->authorizer->resolveUserUsing(fn() => $adminUser);

        $this->assertTrue($this->authorizer->allows('manage-users'));
        $this->assertTrue($this->authorizer->allows('manage-settings'));
        $this->assertSame(['manage-users', 'manage-settings'], $this->authorizer->getChildren('admin'));
    }

    public function testAbilityGroups()
    {
        $this->authorizer->group('content', ['create-post', 'edit-post', 'delete-post']);
        $this->assertTrue($this->authorizer->inGroup('content', 'edit-post'));
        $this->assertFalse($this->authorizer->inGroup('content', 'manage-users'));
    }

    public function testBeforeAndAfterCallbacks()
    {
        $beforeCalled = false;
        $afterCalled = false;

        $this->authorizer->before(function ($user, $ability) use (&$beforeCalled) {
            $beforeCalled = true;
            return $ability === 'bypass' ? true : null;
        });

        $this->authorizer->after(function ($user, $ability, $result) use (&$afterCalled) {
            $afterCalled = true;
        });

        // Before callback should allow this
        $this->assertTrue($this->authorizer->allows('bypass'));
        $this->assertTrue($beforeCalled);
        $this->assertTrue($afterCalled);

        // Reset flags
        $beforeCalled = false;
        $afterCalled = false;

        // Test with regular ability
        $this->authorizer->define('test', fn() => true);
        $this->assertTrue($this->authorizer->allows('test'));
        $this->assertTrue($beforeCalled);
        $this->assertTrue($afterCalled);
    }

    public function testPolicyAuthorization()
    {
        $policy = new class {
            public function update($user, $model)
            {
                return $user->id === $model->owner_id;
            }
        };

        $model = new class {
            public $owner_id = 1;
        };

        $user = new class {
            public $id = 1;
        };
        $otherUser = new class {
            public $id = 2;
        };

        $this->authorizer->authorize(get_class($model), get_class($policy));

        $this->authorizer->resolveUserUsing(fn() => $user);
        $this->assertTrue($this->authorizer->allows('update', $model));

        $this->authorizer->resolveUserUsing(fn() => $otherUser);
        $this->assertFalse($this->authorizer->allows('update', $model));
    }

    public function testAnyAndAllMethods()
    {
        $this->authorizer->define('ability1', fn() => true);
        $this->authorizer->define('ability2', fn() => false);
        $this->authorizer->define('ability3', fn() => true);

        $this->assertTrue($this->authorizer->any(['ability1', 'ability2']));
        $this->assertFalse($this->authorizer->any(['ability2', 'nonexistent']));

        $this->assertTrue($this->authorizer->all(['ability1', 'ability3']));
        $this->assertFalse($this->authorizer->all(['ability1', 'ability2']));
    }

    public function testHasAbilityAndGetAllAbilities()
    {
        $this->authorizer->define('defined', fn() => true);
        $this->authorizer->temporary('temp', fn() => true);
        $this->authorizer->inherit('parent', ['child']);

        $this->assertTrue($this->authorizer->hasAbility('defined'));
        $this->assertTrue($this->authorizer->hasAbility('temp'));
        $this->assertTrue($this->authorizer->hasAbility('parent'));
        $this->assertFalse($this->authorizer->hasAbility('nonexistent'));

        $allAbilities = $this->authorizer->getAllAbilities();
        $this->assertContains('defined', $allAbilities);
        $this->assertContains('temp', $allAbilities);
        $this->assertContains('parent', $allAbilities);
    }

    public function testClearMethod()
    {
        $this->authorizer->define('test', fn() => true);
        $this->authorizer->authorize('Model', 'Policy');

        $this->assertNotEmpty($this->authorizer->abilities());
        $this->assertNotEmpty($this->authorizer->policies());

        $this->authorizer->clear();

        $this->assertEmpty($this->authorizer->abilities());
        $this->assertEmpty($this->authorizer->policies());
    }

    public function testUserResolution()
    {
        $user = new class {};
        $this->authorizer->resolveUserUsing(fn() => $user);
        $this->assertSame($user, $this->authorizer->resolveUser());
    }
}
