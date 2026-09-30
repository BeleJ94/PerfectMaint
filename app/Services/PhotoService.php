<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\EquipmentPhotoRepository;
use App\Repositories\EquipmentRepository;
use App\Security\Auth;

/**
 * Photos réelles des engins. Fichiers stockés hors de la racine web (storage/uploads),
 * servis uniquement via un contrôleur qui vérifie le périmètre de sites.
 * Le type est déterminé par le contenu (finfo + getimagesize), jamais par l'extension fournie.
 */
final class PhotoService
{
    public const MAX_BYTES = 8 * 1024 * 1024;
    public const MAX_PER_EQUIPMENT = 24;
    private const TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private readonly string $dir;

    public function __construct(
        private readonly Database $db,
        private readonly EquipmentRepository $equipment,
        private readonly EquipmentPhotoRepository $photos,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
        ?string $dir = null,
    ) {
        $this->dir = $dir ?? dirname(__DIR__, 2) . '/storage/uploads/equipment';
    }

    /** @param array{tmp_name?: string, name?: string, size?: int, error?: int}|null $file entrée de $_FILES */
    public function upload(int $equipmentId, ?array $file, ?string $caption): int
    {
        $caption = $caption !== null ? mb_substr(trim($caption), 0, 150) : null;
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw Validator::fail('photo', 'required');
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw Validator::fail('photo', 'photo_size', ['max' => (string) (self::MAX_BYTES / 1024 / 1024)]);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
            throw Validator::fail('photo', 'photo_type');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        $info = @getimagesize($tmp);
        if (!isset(self::TYPES[$mime]) || $info === false || ($info['mime'] ?? '') !== $mime) {
            throw Validator::fail('photo', 'photo_type');
        }

        return $this->db->transaction(function () use ($equipmentId, $file, $tmp, $mime, $info, $caption): int {
            $row = $this->equipment->findForUpdate($equipmentId) ?? throw HttpException::notFound();
            $count = $this->photos->count($equipmentId);
            if ($count >= self::MAX_PER_EQUIPMENT) {
                throw Validator::fail('photo', 'photo_limit', ['max' => (string) self::MAX_PER_EQUIPMENT]);
            }
            $name = bin2hex(random_bytes(16)) . '.' . self::TYPES[$mime];
            if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
                throw new \RuntimeException('Stockage des photos indisponible');
            }
            $target = $this->dir . '/' . $name;
            $moved = PHP_SAPI === 'cli' ? copy($tmp, $target) : move_uploaded_file($tmp, $target);
            if (!$moved) {
                throw new \RuntimeException('Enregistrement de la photo impossible');
            }
            $id = $this->photos->create([
                'equipment_id' => $equipmentId,
                'file_name' => $name,
                'original_name' => mb_substr(basename((string) ($file['name'] ?? '')), 0, 190) ?: null,
                'mime' => $mime,
                'size_bytes' => (int) filesize($target),
                'width' => min(65535, (int) $info[0]),
                'height' => min(65535, (int) $info[1]),
                'caption' => $caption ?: null,
                'is_primary' => $count === 0 ? 1 : 0,
                'uploaded_by' => $this->auth->user()?->id,
            ]);
            $this->logger->log('photo_add', 'equipment', $equipmentId, (int) $row['site_id'], null, ['photo_id' => $id, 'caption' => $caption], $row['code']);

            return $id;
        });
    }

    public function setPrimary(int $equipmentId, int $photoId): void
    {
        $photo = $this->photos->find($equipmentId, $photoId) ?? throw HttpException::notFound();
        $this->equipment->scope()->assertAllows((int) $photo['site_id']);
        $this->photos->setPrimary($equipmentId, $photoId);
    }

    public function delete(int $equipmentId, int $photoId): void
    {
        $this->db->transaction(function () use ($equipmentId, $photoId): void {
            $photo = $this->photos->find($equipmentId, $photoId) ?? throw HttpException::notFound();
            $this->photos->delete($photoId);
            if ((int) $photo['is_primary'] === 1) {
                $next = $this->photos->forEquipment($equipmentId)[0]['id'] ?? null;
                if ($next !== null) {
                    $this->photos->setPrimary($equipmentId, (int) $next);
                }
            }
            $this->logger->log('photo_delete', 'equipment', $equipmentId, (int) $photo['site_id'], ['photo_id' => $photoId], null, $photo['equipment_code']);
            @unlink($this->dir . '/' . basename((string) $photo['file_name']));
        });
    }

    /** Chemin disque d'une photo accessible (null si hors périmètre ou fichier absent). */
    public function path(int $equipmentId, int $photoId): ?array
    {
        $photo = $this->photos->find($equipmentId, $photoId);
        if ($photo === null) {
            return null;
        }
        $path = $this->dir . '/' . basename((string) $photo['file_name']);

        return is_file($path) ? ['path' => $path, 'mime' => $photo['mime'], 'file_name' => $photo['file_name']] : null;
    }
}
