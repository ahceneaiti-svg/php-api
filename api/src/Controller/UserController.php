<?php

namespace App\Controller;

use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class UserController
{
    public function __construct(private readonly UserRepository $users)
    {
    }

    #[Route('/health/live', name: 'health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }

    #[Route('/health', name: 'health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        $ok = $this->users->ping();

        return new JsonResponse(
            ['status' => $ok ? 'ok' : 'degraded', 'redis' => $ok],
            $ok ? Response::HTTP_OK : Response::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    #[Route('/api/users', name: 'users_list', methods: ['GET'])]
    public function list(): JsonResponse
    {
        return new JsonResponse($this->users->findAll());
    }

    #[Route('/api/users', name: 'users_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $body = $this->decode($request);

        if (null !== $error = $this->validate($body)) {
            return new JsonResponse(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (null !== $this->users->findByEmail($body['email'])) {
            return new JsonResponse(['error' => 'email already exists'], Response::HTTP_CONFLICT);
        }

        $user = $this->users->create(trim($body['name']), trim($body['email']));

        return new JsonResponse($user, Response::HTTP_CREATED);
    }

    #[Route('/api/users/{id}', name: 'users_show', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function show(int $id): JsonResponse
    {
        $user = $this->users->find($id);

        return $user
            ? new JsonResponse($user)
            : new JsonResponse(['error' => 'not found'], Response::HTTP_NOT_FOUND);
    }

    #[Route('/api/users/{id}', name: 'users_update', methods: ['PUT', 'PATCH'], requirements: ['id' => '\d+'])]
    public function update(int $id, Request $request): JsonResponse
    {
        $user = $this->users->find($id);
        if (null === $user) {
            return new JsonResponse(['error' => 'not found'], Response::HTTP_NOT_FOUND);
        }

        $body = $this->decode($request);
        if (null !== $error = $this->validate($body)) {
            return new JsonResponse(['error' => $error], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $clash = $this->users->findByEmail($body['email']);
        if (null !== $clash && $clash->id !== $user->id) {
            return new JsonResponse(['error' => 'email already exists'], Response::HTTP_CONFLICT);
        }

        $user = $this->users->update($user, trim($body['name']), trim($body['email']));

        return new JsonResponse($user);
    }

    #[Route('/api/users/{id}', name: 'users_delete', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function delete(int $id): Response
    {
        $user = $this->users->find($id);
        if (null === $user) {
            return new JsonResponse(['error' => 'not found'], Response::HTTP_NOT_FOUND);
        }

        $this->users->delete($user);

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Request $request): array
    {
        $data = json_decode($request->getContent() ?: '{}', true);

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function validate(array $body): ?string
    {
        if (empty($body['name']) || !\is_string($body['name'])) {
            return 'name is required';
        }

        if (empty($body['email']) || !\is_string($body['email']) || !filter_var($body['email'], \FILTER_VALIDATE_EMAIL)) {
            return 'a valid email is required';
        }

        return null;
    }
}
