<?php

namespace App\Repository;

use App\Model\User;
use App\Redis\RedisFactory;

/**
 * User persistence backed by Redis.
 *
 * Layout (keys are additionally prefixed with "user-api:" by the client):
 *   user:seq              -> INCR counter for ids
 *   users                 -> SET of existing ids
 *   user:{id}             -> HASH with the user fields
 *   user:email:{email}    -> STRING id, used as a unique index on email
 */
class UserRepository
{
    private const SEQ = 'user:seq';
    private const INDEX = 'users';
    private const EMAIL_INDEX = 'user:email:';

    private ?\Redis $redis = null;

    public function __construct(private readonly RedisFactory $factory)
    {
    }

    public function ping(): bool
    {
        try {
            return false !== $this->redis()->ping();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return User[]
     */
    public function findAll(): array
    {
        $ids = $this->redis()->sMembers(self::INDEX) ?: [];
        sort($ids, SORT_NUMERIC);

        $users = [];
        foreach ($ids as $id) {
            $user = $this->find((int) $id);
            if (null !== $user) {
                $users[] = $user;
            }
        }

        return $users;
    }

    public function find(int $id): ?User
    {
        $data = $this->redis()->hGetAll('user:'.$id);

        return $data ? User::fromArray($data) : null;
    }

    public function findByEmail(string $email): ?User
    {
        $id = $this->redis()->get(self::EMAIL_INDEX.strtolower($email));

        return $id ? $this->find((int) $id) : null;
    }

    public function create(string $name, string $email): User
    {
        $id = (int) $this->redis()->incr(self::SEQ);
        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);

        $user = new User($id, $name, strtolower($email), $now, $now);
        $this->persist($user);

        return $user;
    }

    public function update(User $user, string $name, string $email): User
    {
        $email = strtolower($email);

        if ($email !== $user->email) {
            $this->redis()->del(self::EMAIL_INDEX.$user->email);
        }

        $user->name = $name;
        $user->email = $email;
        $user->updatedAt = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $this->persist($user);

        return $user;
    }

    public function delete(User $user): void
    {
        $this->redis()->del('user:'.$user->id);
        $this->redis()->sRem(self::INDEX, (string) $user->id);
        $this->redis()->del(self::EMAIL_INDEX.$user->email);
    }

    /**
     * Wipes the whole Redis database. Dev/seed use only.
     */
    public function flushAll(): void
    {
        $this->redis()->flushDB();
    }

    private function persist(User $user): void
    {
        $this->redis()->hMSet('user:'.$user->id, $user->toArray());
        $this->redis()->sAdd(self::INDEX, (string) $user->id);
        $this->redis()->set(self::EMAIL_INDEX.$user->email, (string) $user->id);
    }

    private function redis(): \Redis
    {
        return $this->redis ??= $this->factory->create();
    }
}
