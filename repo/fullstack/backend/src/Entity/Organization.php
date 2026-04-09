<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use App\Repository\OrganizationRepository;

#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organizations')]
class Organization implements \JsonSerializable
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Column(type: 'string', length: 50, unique: true)]
    private string $code;

    #[ORM\Column(type: 'string', length: 255)]
    private string $name;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    private bool $isActive = true;

    #[ORM\Column(name: 'default_currency', type: 'string', length: 3, options: ['default' => 'USD'])]
    private string $defaultCurrency = 'USD';

    #[ORM\Column(name: 'created_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    public function __construct(string $id, string $code, string $name, string $defaultCurrency = 'USD')
    {
        $this->id = $id;
        $this->code = $code;
        $this->name = $name;
        $this->defaultCurrency = $defaultCurrency;
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): string { return $this->id; }
    public function getCode(): string { return $this->code; }
    public function getName(): string { return $this->name; }
    public function setName(string $name): void { $this->name = $name; $this->updatedAt = new \DateTimeImmutable(); }
    public function isActive(): bool { return $this->isActive; }
    public function setIsActive(bool $active): void { $this->isActive = $active; $this->updatedAt = new \DateTimeImmutable(); }
    public function getDefaultCurrency(): string { return $this->defaultCurrency; }
    public function setDefaultCurrency(string $currency): void { $this->defaultCurrency = $currency; $this->updatedAt = new \DateTimeImmutable(); }
    public function getCreatedAt(): \DateTimeImmutable { return $this->createdAt; }
    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'is_active' => $this->isActive,
            'default_currency' => $this->defaultCurrency,
            'created_at' => $this->createdAt->format(\DateTimeInterface::ATOM),
        ];
    }
}
