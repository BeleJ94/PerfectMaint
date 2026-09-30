<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\I18n;
use App\Core\ValidationException;
use App\Core\Validator;
use App\Repositories\PartRepository;
use App\Security\Auth;

/**
 * Mouvements de stock : entrée, sortie (consommation), ajustement d'inventaire.
 * Chaque mouvement verrouille la ligne de stock : pas de stock négatif, pas de course.
 */
final class StockService
{
    public function __construct(
        private readonly Database $db,
        private readonly PartRepository $parts,
        private readonly ActivityLogger $logger,
        private readonly Auth $auth,
    ) {
    }

    /**
     * @param 'in'|'out'|'adjust' $type  pour 'adjust', $quantity est le nouveau stock compté
     * @return float stock après mouvement
     */
    public function move(int $partId, int $siteId, string $type, float $quantity, ?string $comment = null, ?int $workOrderId = null, ?int $equipmentId = null): float
    {
        if (!in_array($type, ['in', 'out', 'adjust'], true)) {
            throw new \InvalidArgumentException("Type de mouvement inconnu : $type");
        }
        if ($quantity < 0 || ($type !== 'adjust' && $quantity <= 0)) {
            throw ValidationException::withMessage('quantity', I18n::current()->get('validation.positive'));
        }
        $this->parts->scope()->assertAllows($siteId);
        $part = $this->parts->find($partId) ?? throw HttpException::notFound();

        return $this->db->transaction(function () use ($part, $partId, $siteId, $type, $quantity, $comment, $workOrderId, $equipmentId): float {
            $stock = $this->parts->lockStock($partId, $siteId);
            $before = (float) $stock['quantity'];
            $after = match ($type) {
                'in' => $before + $quantity,
                'out' => $before - $quantity,
                'adjust' => $quantity,
            };
            if ($after < 0) {
                throw ValidationException::withMessage('quantity', I18n::current()->get('stock.insufficient', [
                    'available' => rtrim(rtrim(number_format($before, 2, '.', ''), '0'), '.'),
                    'unit' => $part['unit'],
                ]));
            }
            $this->parts->setQuantity($partId, $siteId, $after);
            $delta = $type === 'adjust' ? $after - $before : $quantity;
            $this->parts->addMovement($partId, $siteId, $type, $delta, $after, $workOrderId, $comment, $this->auth->user()?->id, $equipmentId);
            $this->logger->log(
                'stock_' . $type,
                'part',
                $partId,
                $siteId,
                ['quantity' => $before],
                ['quantity' => $after, 'work_order_id' => $workOrderId, 'comment' => $comment],
                $part['reference'] . ' : ' . $before . ' → ' . $after
            );

            return $after;
        });
    }

    public function moveFromInput(int $partId, array $input): float
    {
        $data = Validator::validate($input, [
            'site_id' => 'required|int',
            'type' => 'required|in:in,adjust',
            'quantity' => 'required|numeric|min:0|max:1000000',
            'comment' => 'string|max:255',
        ]);

        return $this->move($partId, $data['site_id'], $data['type'], $data['quantity'], $data['comment']);
    }

    public function updateSettings(int $partId, array $input): void
    {
        $data = Validator::validate($input, [
            'site_id' => 'required|int',
            'min_quantity' => 'required|numeric|min:0|max:1000000',
            'location' => 'string|max:60',
        ]);
        $this->db->transaction(function () use ($partId, $data): void {
            $stock = $this->parts->lockStock($partId, $data['site_id']);
            $this->parts->setStockSettings($partId, $data['site_id'], $data['min_quantity'], $data['location']);
            $this->logger->log(
                'stock_settings',
                'part',
                $partId,
                $data['site_id'],
                ['min_quantity' => $stock['min_quantity'], 'location' => $stock['location']],
                ['min_quantity' => $data['min_quantity'], 'location' => $data['location']],
            );
        });
    }
}
