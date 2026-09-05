<?php

namespace Webong\LaravelGouge\Support\AccountManager;

use App\Models\Server\User;
use App\Models\Server\UserRelation;
use Figurate\AccountManager\Models\Account;
use Figurate\AccountManager\Models\AccountUser;
use Figurate\AccountManager\Support\AccountContextFactory;
use Illuminate\Support\Facades\DB;
use Webong\LaravelGouge\Support\AgentUsers;

class AttachRobotUserToRequestedAccount
{
    public function __construct(protected AccountContextFactory $accountContextFactory) {}

    public function __invoke(User $robot, User $creator, string $accountUuid): ?Account
    {
        $normalizedAccountUuid = trim($accountUuid);

        if (! AgentUsers::isRobot($robot) || ! $creator->isSubject() || $normalizedAccountUuid === '') {
            return null;
        }

        /** @var ?Account $account */
        $account = $this->accountContextFactory
            ->forUser($creator)
            ->activeAccounts()
            ->where('accounts.uuid', $normalizedAccountUuid)
            ->first();

        if (! $account instanceof Account) {
            return null;
        }

        DB::transaction(function () use ($account, $creator, $robot): void {
            AccountUser::query()
                ->where('user_id', $robot->id)
                ->whereNull('unlinked_at')
                ->where('account_id', '!=', $account->id)
                ->update([
                    'unlinked_at' => now(),
                    'updated_at' => now(),
                    'is_primary' => false,
                ]);

            AccountUser::query()->updateOrCreate(
                [
                    'account_id' => $account->id,
                    'user_id' => $robot->id,
                ],
                [
                    'type' => AgentUsers::Robot,
                    'is_primary' => true,
                    'linked_at' => now(),
                    'unlinked_at' => null,
                ],
            );

            UserRelation::query()
                ->where('source_user_id', $creator->id)
                ->where('target_user_id', $robot->id)
                ->where('type', UserRelation::TypeOwner)
                ->whereNull('unlinked_at')
                ->update([
                    'unlinked_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return $account;
    }
}
