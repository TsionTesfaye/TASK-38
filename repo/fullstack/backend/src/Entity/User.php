<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Enum\UserRole;
use App\Repository\UserRepository;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'users')]
#[ORM\Index(columns: ['organization_id'], name: 'IDX_users_organization')]
class User implements \JsonSerializable, UserInterface
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    private Organization $organization;

    /**
     * GLOBAL UNIQUENESS (design decision):
     *
     * Username is globally unique, NOT scoped to (organization_id, username).
     *
     * Reason: The login flow accepts only username + password — no organization
     * identifier is provided. The system resolves the user and their org from
     * the username alone via findByUsername(). Global uniqueness is REQUIRED
     * for unambiguous user resolution.
     *
     * If the system later supports org-scoped login (e.g., subdomain routing
     * or an org_code in the login payload), this constraint should change to
     * (organization_id, username) via a migration.
     */
    #[ORM\Column(type: 'string', length: 180, unique: true)]
    private string $username;

    #[ORM\Column(name: 'password_hash', type: 'string', length: 255)]
    private string $passwordHash;

    #[ORM\Column(name: 'display_name', type: 'string', length: 255)]
    private string $displayName;

    #[ORM\Column(type: 'string', length: 30, enumType: UserRole::class)]
    private UserRole $role;

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'is_frozen', type: 'boolean', options: ['default' => false])]
    private bool $isFrozen = false;

    #[ORM\Column(name: 'password_changed_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $passwordChangedAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, Organization $organization, string $username, string $passwordHash, string $displayName, UserRole $role)
    {
        $this->id = $id;
        $this->organization = $organization;
        $this->username = $username;
        $this->passwordHash = $passwordHash;
        $this->displayName = $displayName;
        $this->role = $role;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getOrganizationId(): string { return $this->organization->getId(); }
    public function getUsername(): string { return $this->username; }
    public function getPasswordHash(): string { return $this->passwordHash; }
    public function setPasswordHash(string $hash): void { $this->passwordHash = $hash; $this->passwordChangedAt = new \DateTimeImmutable(); $this->updatedAt = new \DateTimeImmutable(); }
    public function getDisplayName(): string { return $this->displayName; }
    public function setDisplayName(string $name): void { $this->displayName = $name; $this->updatedAt = new \DateTimeImmutable(); }
    public function getRole(): UserRole { return $this->role; }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $active): void { $this->isActive = $active; $this->updatedAt = new \DateTimeImmutable(); }
    public function isFrozen(): bool { return $this->isFrozen; }
    public function setIsFrozen(bool $frozen): void { $this->isFrozen = $frozen; $this->updatedAt = new \DateTimeImmutable(); }
    public function getPasswordChangedAt(): ?\DateTimeImmutable { return $this->passwordChangedAt; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'display_name' => $this->displayName,
            'role' => $this->role->value,
            'is_active' => $this->isActive,
            'is_frozen' => $this->isFrozen,
            'organization_id' => $this->getOrganizationId(),
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }

    // ─── UserInterface ──────────────────────────────────────────────

    public function getRoles(): array
    {
        return ['ROLE_USER'];
    }

    public function getUserIdentifier(): string
    {
        return $this->id;
    }

    public function eraseCredentials(): void
    {
        // No plaintext credentials stored in memory
    }
}
