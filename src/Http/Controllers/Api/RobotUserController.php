<?php

namespace Webong\LaravelGouge\Http\Controllers\Api;

use App\Contracts\Users\UserRepository;
use App\Http\Controllers\Controller;
use App\Models\Server\User;
use App\Models\Server\UserRelation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Webong\LaravelGouge\Events\Server\Auth\RobotProvisioned;
use Webong\LaravelGouge\Http\Requests\Server\Auth\StoreRobotUserRequest;
use Webong\LaravelGouge\Support\AgentUsers;

class RobotUserController extends Controller
{
    public function __construct(protected UserRepository $userRepository) {}

    public function store(StoreRobotUserRequest $request): JsonResponse
    {
        $validated = $request->validated();
        /** @var User $creator */
        $creator = $request->user();
        $name = trim((string) $validated['name']);
        $email = isset($validated['email'])
            ? trim((string) $validated['email'])
            : 'robot-'.Str::lower((string) Str::ulid()).'@example.invalid';
        $requestedAccountUuid = isset($validated['account_uuid'])
            ? trim((string) $validated['account_uuid'])
            : null;
        $tokenName = isset($validated['token_name'])
            ? trim((string) $validated['token_name'])
            : Str::slug($name, '-').'-robot-token';
        $abilities = collect($validated['abilities'] ?? [])
            ->filter(fn (mixed $ability): bool => is_string($ability) && trim($ability) !== '')
            ->map(fn (string $ability): string => trim($ability))
            ->unique()
            ->values()
            ->all();

        [$robot, $token] = DB::transaction(function () use ($abilities, $creator, $email, $name, $tokenName): array {
            $robot = $this->userRepository->create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::random(48)),
                'type' => AgentUsers::Robot,
                'status' => 'active',
            ]);

            UserRelation::query()->updateOrCreate(
                [
                    'source_user_id' => $creator->id,
                    'target_user_id' => $robot->id,
                    'type' => UserRelation::TypeCreator,
                ],
                [
                    'payload' => null,
                    'linked_at' => now(),
                    'unlinked_at' => null,
                ],
            );

            UserRelation::query()->updateOrCreate(
                [
                    'source_user_id' => $creator->id,
                    'target_user_id' => $robot->id,
                    'type' => UserRelation::TypeOwner,
                ],
                [
                    'payload' => null,
                    'linked_at' => now(),
                    'unlinked_at' => null,
                ],
            );

            $token = $this->userRepository->issueToken($robot, $tokenName, $abilities);

            return [$robot, $token];
        });

        event(new RobotProvisioned(
            creator: $creator,
            robot: $robot,
            requestedAccountUuid: $requestedAccountUuid,
        ));

        return response()->json([
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'abilities' => $abilities,
                'user' => [
                    'id' => $robot->id,
                    'uuid' => $robot->uuid,
                    'name' => $robot->name,
                    'email' => $robot->email,
                    'type' => $robot->type,
                    'status' => $robot->status,
                ],
            ],
        ], 201);
    }
}
