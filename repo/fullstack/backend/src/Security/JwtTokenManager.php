<?php

declare(strict_types=1);

namespace App\Security;

use App\Entity\User;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\Constraint\StrictValidAt;
use Lcobucci\Clock\SystemClock;
use App\Exception\AuthenticationException;

class JwtTokenManager
{
    private string $secret;
    private int $accessTokenTtl;
    private int $refreshTokenTtl;
    private Configuration $config;

    public function __construct()
    {
        $this->secret = $_ENV['JWT_SECRET'] ?? throw new \RuntimeException('JWT_SECRET environment variable is required');
        $this->accessTokenTtl = (int) ($_ENV['JWT_ACCESS_TOKEN_TTL'] ?? 900);
        $this->refreshTokenTtl = (int) ($_ENV['JWT_REFRESH_TOKEN_TTL'] ?? 1209600);

        $this->config = Configuration::forSymmetricSigner(
            new Sha256(),
            InMemory::plainText($this->secret)
        );
    }

    public function createAccessToken(User $user): string
    {
        $now = new \DateTimeImmutable();

        $token = $this->config->builder()
            ->relatedTo($user->getId())
            ->withClaim('org', $user->getOrganizationId())
            ->withClaim('role', $user->getRole()->value)
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify("+{$this->accessTokenTtl} seconds"))
            ->getToken($this->config->signer(), $this->config->signingKey());

        return $token->toString();
    }

    public function createRefreshToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function hashRefreshToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function parseAccessToken(string $jwt): array
    {
        try {
            $token = $this->config->parser()->parse($jwt);

            $constraints = [
                new SignedWith($this->config->signer(), $this->config->signingKey()),
                new StrictValidAt(SystemClock::fromUTC()),
            ];

            $this->config->validator()->assert($token, ...$constraints);

            return [
                'user_id' => $token->claims()->get('sub'),
                'organization_id' => $token->claims()->get('org'),
                'role' => $token->claims()->get('role'),
                'issued_at' => $token->claims()->get('iat'),
            ];
        } catch (\Throwable $e) {
            throw new AuthenticationException('Invalid or expired token');
        }
    }

    public function getAccessTokenTtl(): int
    {
        return $this->accessTokenTtl;
    }

    public function getRefreshTokenTtl(): int
    {
        return $this->refreshTokenTtl;
    }
}
