<?php

namespace Webong\LaravelGouge\Tests\Feature\A2a;

use App\Models\Server\User;
use App\Models\Server\UserRelation;
use App\TokenAbility;
use Figurate\AccountManager\Models\Account;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use Webong\LaravelGouge\Support\AgentAbilities;
use Webong\LaravelGouge\Support\AgentUsers;

class RobotUserProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_subject_user_can_provision_a_robot_user_with_default_protocol_abilities(): void
    {
        $person = $this->makeUser(User::TypeSubject);

        Sanctum::actingAs($person, []);

        $response = $this->postJson('/api/users', [
            'name' => 'Remote Planner',
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.type', AgentUsers::Robot)
            ->assertJsonPath('data.user.name', 'Remote Planner')
            ->assertJsonPath('data.abilities', AgentAbilities::defaults());

        $robotId = (int) $response->json('data.user.id');
        $robot = User::query()->findOrFail($robotId);

        $this->assertSame(AgentUsers::Robot, $robot->type);
        $this->assertFalse($robot->canAccessMarketplace());
        $this->assertTrue($robot->canUseInteractiveTransport());
        $this->assertDatabaseMissing('identities', [
            'user_id' => $robot->id,
        ]);
        $this->assertDatabaseHas('user_relations', [
            'source_user_id' => $person->id,
            'target_user_id' => $robot->id,
            'type' => UserRelation::TypeCreator,
            'unlinked_at' => null,
        ]);
        $this->assertDatabaseHas('user_relations', [
            'source_user_id' => $person->id,
            'target_user_id' => $robot->id,
            'type' => UserRelation::TypeOwner,
            'unlinked_at' => null,
        ]);

        $tokenId = explode('|', (string) $response->json('data.token'))[0] ?? null;
        $token = PersonalAccessToken::query()->findOrFail((int) $tokenId);

        $this->assertSame(AgentAbilities::defaults(), $token->abilities);
    }

    public function test_a_subject_user_can_provision_a_robot_for_an_owned_account(): void
    {
        $person = $this->makeUser(User::TypeSubject);
        $account = Account::query()->create([
            'name' => 'Operations Workspace',
            'status' => 'active',
        ]);
        $account->users()->attach($person->id, [
            'type' => 'owner',
            'is_primary' => true,
            'linked_at' => now(),
        ]);

        Sanctum::actingAs($person, []);

        $response = $this->postJson('/api/users', [
            'name' => 'Workspace Robot',
            'account_uuid' => $account->uuid,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.type', AgentUsers::Robot)
            ->assertJsonPath('data.user.name', 'Workspace Robot');

        $robotId = (int) $response->json('data.user.id');
        $robot = User::query()->findOrFail($robotId);

        $this->assertDatabaseHas('user_relations', [
            'source_user_id' => $person->id,
            'target_user_id' => $robot->id,
            'type' => UserRelation::TypeCreator,
            'unlinked_at' => null,
        ]);
        $this->assertDatabaseHas('account_users', [
            'account_id' => $account->id,
            'user_id' => $robot->id,
            'type' => AgentUsers::Robot,
            'is_primary' => true,
            'unlinked_at' => null,
        ]);

        $ownerRelation = UserRelation::query()
            ->where('source_user_id', $person->id)
            ->where('target_user_id', $robot->id)
            ->where('type', UserRelation::TypeOwner)
            ->firstOrFail();

        $this->assertNotNull($ownerRelation->unlinked_at);
    }

    public function test_a_subject_user_keeps_personal_robot_ownership_when_requesting_an_inaccessible_account(): void
    {
        $person = $this->makeUser(User::TypeSubject);
        $foreignAccount = Account::query()->create([
            'name' => 'Foreign Workspace',
            'status' => 'active',
        ]);

        Sanctum::actingAs($person, []);

        $response = $this->postJson('/api/users', [
            'name' => 'Fallback Robot',
            'account_uuid' => $foreignAccount->uuid,
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.user.type', AgentUsers::Robot)
            ->assertJsonPath('data.user.name', 'Fallback Robot');

        $robotId = (int) $response->json('data.user.id');

        $this->assertDatabaseHas('user_relations', [
            'source_user_id' => $person->id,
            'target_user_id' => $robotId,
            'type' => UserRelation::TypeCreator,
            'unlinked_at' => null,
        ]);
        $this->assertDatabaseHas('user_relations', [
            'source_user_id' => $person->id,
            'target_user_id' => $robotId,
            'type' => UserRelation::TypeOwner,
            'unlinked_at' => null,
        ]);
        $this->assertDatabaseMissing('account_users', [
            'account_id' => $foreignAccount->id,
            'user_id' => $robotId,
            'type' => AgentUsers::Robot,
            'unlinked_at' => null,
        ]);
    }

    public function test_a_widget_user_cannot_provision_a_robot_user_without_account_access(): void
    {
        $widgetUser = $this->makeUser(User::TypeWidget);

        Sanctum::actingAs($widgetUser, [TokenAbility::Compose->value]);

        $this->postJson('/api/users', [
            'name' => 'Blocked Robot',
        ])->assertForbidden();
    }

    public function test_an_account_linked_widget_user_cannot_provision_a_robot_user(): void
    {
        $widgetUser = $this->makeUser(User::TypeWidget, 'widget-owner@example.com');
        $account = Account::query()->create([
            'name' => 'Widget Owner',
            'status' => 'active',
        ]);

        $widgetUser->accounts()->attach($account->id, [
            'type' => 'widget',
            'is_primary' => true,
            'linked_at' => now(),
        ]);

        Sanctum::actingAs($widgetUser, []);

        $this->postJson('/api/users', [
            'name' => 'Linked Widget Robot',
        ])->assertForbidden();
    }

    protected function makeUser(string $type, ?string $email = null): User
    {
        return User::query()->create([
            'name' => ucfirst($type).' User',
            'email' => $email ?? fake()->unique()->safeEmail(),
            'password' => 'password',
            'type' => $type,
            'status' => 'active',
        ]);
    }
}
