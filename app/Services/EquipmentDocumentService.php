<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Validator;
use App\Repositories\EquipmentDocumentRepository;
use App\Repositories\EquipmentRepository;
use App\Security\Auth;

/**
 * Saisie des documents à échéance d'un engin. Un renouvellement = un nouveau document (l'ancien reste
 * dans l'historique) ; la modification sert à corriger une saisie.
 * Fichier joint optionnel (PDF ou image) stocké hors de la racine web, type déterminé par le contenu.
 */
final class EquipmentDocumentService
{
    public const MAX_BYTES = 10 * 1024 * 1024;
    private const TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private readonly string $dir;

    public function __construct(
        private readonly Database $db,
        private readonly EquipmentRepository $equipment,
        private readonly EquipmentDocumentRepository $documents,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
        ?string $dir = null,
    ) {
        $this->dir = $dir ?? dirname(__DIR__, 2) . '/storage/uploads/documents';
    }

    /** @param array{tmp_name?: string, name?: string, size?: int, error?: int}|null $file entrée de $_FILES (optionnelle) */
    public function add(int $equipmentId, array $input, ?array $file = null): int
    {
        $data = $this->validate($input);
        $upload = $this->checkFile($file);

        return $this->withFile($upload, function (?array $stored) use ($equipmentId, $data): int {
            return $this->db->transaction(function () use ($equipmentId, $data, $stored): int {
                $equipment = $this->equipment->findForUpdate($equipmentId) ?? throw HttpException::notFound();
                $id = $this->documents->create(['equipment_id' => $equipmentId, 'created_by' => $this->auth->user()?->id] + $data);
                if ($stored !== null) {
                    $this->documents->setFile($id, $stored);
                }
                $this->logger->log('document_add', 'equipment', $equipmentId, (int) $equipment['site_id'], null,
                    $data + ($stored ? ['file' => $stored['original_name']] : []), $equipment['code'] . ' : ' . $this->summary($data));

                return $id;
            });
        });
    }

    /** Correction ; $removeFile retire le fichier joint, un nouveau fichier remplace l'ancien. */
    public function update(int $equipmentId, int $documentId, array $input, ?array $file = null, bool $removeFile = false): void
    {
        $data = $this->validate($input);
        $upload = $this->checkFile($file);
        $previousFile = null;
        $this->withFile($upload, function (?array $stored) use ($equipmentId, $documentId, $data, $removeFile, &$previousFile): void {
            $this->db->transaction(function () use ($equipmentId, $documentId, $data, $stored, $removeFile, &$previousFile): void {
                $equipment = $this->equipment->findForUpdate($equipmentId) ?? throw HttpException::notFound();
                $before = $this->owned($equipmentId, $documentId);
                $this->documents->update($documentId, $data);
                $after = $data;
                if ($stored !== null || ($removeFile && $before['file_name'] !== null)) {
                    $this->documents->setFile($documentId, $stored);
                    $previousFile = $before['file_name'];
                    $after['file'] = $stored['original_name'] ?? null;
                    $before['file'] = $before['original_name'];
                }
                $this->logger->log('document_update', 'equipment', $equipmentId, (int) $equipment['site_id'],
                    array_intersect_key($before, $after), $after, $equipment['code'] . ' : ' . $this->summary($data));
            });
        });
        $this->unlink($previousFile);
    }

    public function delete(int $equipmentId, int $documentId): void
    {
        $file = $this->db->transaction(function () use ($equipmentId, $documentId): ?string {
            $equipment = $this->equipment->findForUpdate($equipmentId) ?? throw HttpException::notFound();
            $before = $this->owned($equipmentId, $documentId);
            $this->documents->delete($documentId);
            $this->logger->log('document_delete', 'equipment', $equipmentId, (int) $equipment['site_id'],
                array_intersect_key($before, array_flip(['document_type_id', 'issuer', 'reference', 'issued_at', 'expires_at', 'note', 'original_name'])), null,
                $equipment['code'] . ' : ' . $this->summary($before));

            return $before['file_name'];
        });
        $this->unlink($file);
    }

    /** Fichier joint d'un document de cet engin, dans le périmètre. @return array{path: string, mime: string, name: string, file_name: string}|null */
    public function file(int $equipmentId, int $documentId): ?array
    {
        $document = $this->documents->find($documentId);
        if ($document === null || (int) $document['equipment_id'] !== $equipmentId || $document['file_name'] === null) {
            return null;
        }
        $path = $this->dir . '/' . basename((string) $document['file_name']);
        if (!is_file($path)) {
            return null;
        }
        $extension = self::TYPES[$document['mime']] ?? 'bin';
        $name = $document['original_name'] ?: $document['equipment_code'] . '-' . $document['type_code'] . '.' . $extension;

        return ['path' => $path, 'mime' => (string) $document['mime'], 'name' => $name, 'file_name' => (string) $document['file_name']];
    }

    private function validate(array $input): array
    {
        $data = Validator::validate($input, [
            'document_type_id' => 'required|int',
            'issuer' => 'string|max:120',
            'reference' => 'string|max:80',
            'issued_at' => 'date',
            'expires_at' => 'required|date',
            'note' => 'string|max:255',
        ]);
        if (!in_array($data['document_type_id'], array_map('intval', array_column($this->documents->types($data['document_type_id']), 'id')), true)) {
            throw Validator::fail('document_type_id', 'in');
        }
        if ($data['issued_at'] !== null && $data['issued_at'] > $data['expires_at']) {
            throw Validator::fail('expires_at', 'document_dates');
        }

        return $data;
    }

    /**
     * Contrôle du fichier envoyé (aucun fichier = null).
     *
     * @return array{tmp: string, mime: string, original_name: ?string}|null
     */
    private function checkFile(?array $file): ?array
    {
        if ($file === null || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK || ($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > self::MAX_BYTES) {
            throw Validator::fail('document_file', 'document_file_size', ['max' => (string) (self::MAX_BYTES / 1024 / 1024)]);
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if (PHP_SAPI !== 'cli' && !is_uploaded_file($tmp)) {
            throw Validator::fail('document_file', 'document_file_type');
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($tmp);
        if (!isset(self::TYPES[$mime]) || (str_starts_with($mime, 'image/') && @getimagesize($tmp) === false)) {
            throw Validator::fail('document_file', 'document_file_type');
        }

        return ['tmp' => $tmp, 'mime' => $mime, 'original_name' => mb_substr(basename((string) ($file['name'] ?? '')), 0, 190) ?: null];
    }

    /**
     * Enregistre le fichier puis exécute $operation ; en cas d'échec, le fichier écrit est supprimé.
     *
     * @template T
     * @param callable(?array): T $operation
     * @return T
     */
    private function withFile(?array $upload, callable $operation): mixed
    {
        if ($upload === null) {
            return $operation(null);
        }
        if (!is_dir($this->dir) && !mkdir($this->dir, 0775, true) && !is_dir($this->dir)) {
            throw new \RuntimeException('Stockage des documents indisponible');
        }
        $name = bin2hex(random_bytes(16)) . '.' . self::TYPES[$upload['mime']];
        $target = $this->dir . '/' . $name;
        $moved = PHP_SAPI === 'cli' ? copy($upload['tmp'], $target) : move_uploaded_file($upload['tmp'], $target);
        if (!$moved) {
            throw new \RuntimeException('Enregistrement du document impossible');
        }
        try {
            return $operation(['file_name' => $name, 'original_name' => $upload['original_name'], 'mime' => $upload['mime'], 'size_bytes' => (int) filesize($target)]);
        } catch (\Throwable $e) {
            @unlink($target);
            throw $e;
        }
    }

    private function unlink(?string $fileName): void
    {
        if ($fileName !== null && $fileName !== '') {
            @unlink($this->dir . '/' . basename($fileName));
        }
    }

    /** Document de cet engin, dans le périmètre de l'utilisateur. */
    private function owned(int $equipmentId, int $documentId): array
    {
        $document = $this->documents->find($documentId);
        if ($document === null || (int) $document['equipment_id'] !== $equipmentId) {
            throw HttpException::notFound();
        }

        return $document;
    }

    private function summary(array $data): string
    {
        $types = array_column($this->documents->types((int) $data['document_type_id']), null, 'id');
        $type = $types[(int) $data['document_type_id']] ?? null;

        return trim(($type ? doc_type_label($type) : '') . ' ' . ($data['reference'] ?? ''))
            . ($data['expires_at'] ? ' → ' . format_date($data['expires_at']) : '');
    }
}
