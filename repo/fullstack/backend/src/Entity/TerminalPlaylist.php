<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\TerminalPlaylistRepository;

#[ORM\Entity(repositoryClass: TerminalPlaylistRepository::class)]
#[ORM\Table(name: 'terminal_playlists')]
#[ORM\Index(columns: ['organization_id', 'location_group'], name: 'IDX_terminal_playlists_org_location')]
class TerminalPlaylist implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\ManyToOne(targetEntity: Organization::class)]
    #[ORM\JoinColumn(name: 'organization_id', referencedColumnName: 'id', nullable: false)]
    private Organization $organization;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(name: 'location_group', type: 'string', length: 255)]
    private string $locationGroup;

    #[ORM\Column(name: 'schedule_rule', type: 'string', length: 500)]
    private string $scheduleRule;

    #[ORM\Column(name: 'is_active', type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, Organization $organization, string $name, string $locationGroup, string $scheduleRule)
    {
        $this->id = $id;
        $this->organization = $organization;
        $this->name = $name;
        $this->locationGroup = $locationGroup;
        $this->scheduleRule = $scheduleRule;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getOrganization(): Organization { return $this->organization; }
    public function getOrganizationId(): string { return $this->organization->getId(); }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; $this->updatedAt = new \DateTimeImmutable(); }
    public function getLocationGroup(): string { return $this->locationGroup; }
    public function getScheduleRule(): string { return $this->scheduleRule; }
    public function setScheduleRule(string $rule): void { $this->scheduleRule = $rule; $this->updatedAt = new \DateTimeImmutable(); }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $active): void { $this->isActive = $active; $this->updatedAt = new \DateTimeImmutable(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->getOrganizationId(),
            'name' => $this->name,
            'location_group' => $this->locationGroup,
            'schedule_rule' => $this->scheduleRule,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
            'updated_at' => $this->updatedAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
