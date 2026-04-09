<?php

declare(strict_types=1);

namespace App\DTO\Response;

use App\Entity\AuditLog;

readonly class AuditLogResponse
{
    public function __construct(
        public string $id,
        public string $actor_username_snapshot,
        public string $action_code,
        public string $object_type,
        public string $object_id,
        public string $created_at,
    ) {}

    public static function fromEntity(AuditLog $log): self
    {
        $objectId = $log->getObjectId();
        $maskedObjectId = strlen($objectId) > 4
            ? str_repeat('*', strlen($objectId) - 4) . substr($objectId, -4)
            : $objectId;

        return new self(
            id: $log->getId(),
            actor_username_snapshot: $log->getActorUsernameSnapshot(),
            action_code: $log->getActionCode(),
            object_type: $log->getObjectType(),
            object_id: $maskedObjectId,
            created_at: $log->getCreatedAt()->format(\DateTimeInterface::ATOM),
        );
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'actor_username_snapshot' => $this->actor_username_snapshot,
            'action_code' => $this->action_code,
            'object_type' => $this->object_type,
            'object_id' => $this->object_id,
            'created_at' => $this->created_at,
        ];
    }
}
