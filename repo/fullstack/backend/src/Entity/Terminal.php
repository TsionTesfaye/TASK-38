<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\TerminalRepository;

#[ORM\Entity(repositoryClass: TerminalRepository::class)]
#[ORM\Table(name: 'terminals')]
#[ORM\UniqueConstraint(name: 'UNIQ_terminals_org_code', columns: ['organization_id', 'terminal_code'])]
class Terminal implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    private Organization $organization;

    #[ORM\Column(name: 'terminal_code', type: 'string', length: 100)]
    private string $terminalCode;

    #[ORM\Column(name: 'display_name', type: 'string', length: 255)]
    private string $displayName;

    #[ORM\Column(name: 'location_group', type: 'string', length: 255)]
    private string $locationGroup;

    #[ORM\Column(name: 'language_code', type: 'string', length: 10, options: ['default' => 'en'])]
    private string $languageCode = 'en';

    #[ORM\Column(name: 'accessibility_mode', type: 'boolean', options: ['default' => false])]
    private bool $accessibilityMode = false;

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'last_sync_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastSyncAt = null;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, Organization $organization, string $terminalCode, string $displayName, string $locationGroup, string $languageCode = 'en', bool $accessibilityMode = false)
    {
        $this->id = $id;
        $this->organization = $organization;
        $this->terminalCode = $terminalCode;
        $this->displayName = $displayName;
        $this->locationGroup = $locationGroup;
        $this->languageCode = $languageCode;
        $this->accessibilityMode = $accessibilityMode;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getOrganizationId(): string { return $this->organization->getId(); }
    public function getTerminalCode(): string { return $this->terminalCode; }
    public function getDisplayName(): string { return $this->displayName; }
    public function setDisplayName(string $name): void { $this->displayName = $name; $this->updatedAt = new \DateTimeImmutable(); }
    public function getLocationGroup(): string { return $this->locationGroup; }
    public function getLanguageCode(): string { return $this->languageCode; }
    public function setLanguageCode(string $code): void { $this->languageCode = $code; $this->updatedAt = new \DateTimeImmutable(); }
    public function getAccessibilityMode(): bool { return $this->accessibilityMode; }
    public function setAccessibilityMode(bool $mode): void { $this->accessibilityMode = $mode; $this->updatedAt = new \DateTimeImmutable(); }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $active): void { $this->isActive = $active; $this->updatedAt = new \DateTimeImmutable(); }
    public function getLastSyncAt(): ?\DateTimeImmutable { return $this->lastSyncAt; }
    public function setLastSyncAt(\DateTimeImmutable $at): void { $this->lastSyncAt = $at; }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->getOrganizationId(),
            'terminal_code' => $this->terminalCode,
            'display_name' => $this->displayName,
            'location_group' => $this->locationGroup,
            'language_code' => $this->languageCode,
            'accessibility_mode' => $this->accessibilityMode,
            'is_active' => $this->isActive,
            'last_sync_at' => $this->lastSyncAt?->format(\DateTimeInterface::ATOM),
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
