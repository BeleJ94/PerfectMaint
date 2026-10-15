<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\AlertRepository;
use App\Repositories\EquipmentDocumentRepository;
use App\Repositories\EquipmentRepository;
use App\Repositories\ManagementRepository;
use App\Repositories\ProcurementRepository;
use App\Repositories\SiteRepository;
use DateTimeImmutable;

/** Pilotage : alertes coût et échéances, planning d'activités (jour / semaine / mois), rapports de direction. */
final class ManagementController extends Controller
{
    public const PERIODS = [7, 30, 90, 365];

    public function __construct(
        ControllerContext $ctx,
        private readonly AlertRepository $alerts,
        private readonly ManagementRepository $management,
        private readonly EquipmentRepository $equipment,
        private readonly ProcurementRepository $procurement,
        private readonly SiteRepository $sites,
        private readonly EquipmentDocumentRepository $documents,
    ) {
        parent::__construct($ctx);
    }

    public function alerts(Request $request): Response
    {
        $siteId = $this->siteFilter($request);
        $summary = $this->procurement->summary($siteId);

        return $this->render('management/alerts', [
            'title' => t('nav.alerts'),
            'sites' => $this->sites->listActive(),
            'siteId' => $siteId,
            'wear' => $this->alerts->prematureWear($siteId),
            'overuse' => $this->alerts->lubricantOveruse($siteId),
            'contracts' => $this->equipment->contractsEnding(30, $siteId),
            'documents' => $this->documents->expiring($siteId),
            'stockOut' => (int) $summary['out_count'],
            'stockLow' => (int) $summary['low_count'],
        ]);
    }

    public function planning(Request $request): Response
    {
        $view = in_array($request->string('view'), ['day', 'week', 'month'], true) ? (string) $request->string('view') : 'week';
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $request->string('date')) ?: new DateTimeImmutable('today');
        [$from, $to] = match ($view) {
            'day' => [$date, $date],
            'week' => [$date->modify('monday this week'), $date->modify('monday this week')->modify('+6 days')],
            'month' => [
                $date->modify('first day of this month')->modify('monday this week'),
                $date->modify('last day of this month')->modify('sunday this week'),
            ],
        };
        $step = ['day' => '+1 day', 'week' => '+1 week', 'month' => '+1 month'][$view];
        $siteId = $this->siteFilter($request);
        $events = $this->management->events($from->format('Y-m-d'), $to->format('Y-m-d'), $siteId);
        $byDay = [];
        foreach ($events as $event) {
            $byDay[$event['date']][] = $event;
        }

        return $this->render('management/planning', [
            'title' => t('nav.planning'),
            'sites' => $this->sites->listActive(),
            'siteId' => $siteId,
            'mode' => $view,
            'date' => $date,
            'from' => $from,
            'to' => $to,
            'prev' => $date->modify(str_replace('+', '-', $step))->format('Y-m-d'),
            'next' => $date->modify($step)->format('Y-m-d'),
            'events' => $events,
            'byDay' => $byDay,
            'overdue' => $this->management->overdueCount(date('Y-m-d'), $siteId),
        ]);
    }

    public function reports(Request $request): Response
    {
        $days = in_array($request->int('days'), self::PERIODS, true) ? (int) $request->int('days') : 30;
        $from = date('Y-m-d H:i:s', strtotime("-$days days"));
        $siteId = $this->siteFilter($request);
        $rows = $this->management->siteReport($from);
        if ($siteId !== null) {
            $rows = array_values(array_filter($rows, static fn (array $r) => (int) $r['id'] === $siteId));
        }
        $totals = ['fleet' => 0, 'available' => 0, 'down' => 0, 'breakdowns' => 0, 'closed' => 0, 'open_wo' => 0, 'downtime_hours' => 0.0, 'consumption_value' => 0.0, 'lubricant_qty' => 0.0, 'usage_failures' => 0];
        foreach ($rows as $row) {
            foreach ($totals as $key => $_) {
                $totals[$key] += (float) $row[$key];
            }
        }

        return $this->render('management/reports', [
            'title' => t('nav.reports'),
            'sites' => $this->sites->listActive(),
            'siteId' => $siteId,
            'days' => $days,
            'rows' => $rows,
            'totals' => $totals,
            'causes' => $this->management->causes($from, $siteId),
            'top' => $this->management->topEquipment($from, $siteId),
            'operators' => $this->management->operatorFailures($from, $siteId),
            'generatedAt' => date('Y-m-d H:i'),
        ]);
    }
}
