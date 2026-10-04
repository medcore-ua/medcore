<?php

/*
 *
 *                      _
 *   _ __ ___   ___  __| | ___ ___  _ __ ___       _   _  __ _
 *  | '_ ` _ \ / _ \/ _` |/ __/ _ \| '__/ _ \_____| | | |/ _` |
 *  | | | | | |  __/ (_| | (_| (_) | | |  __/_____| |_| | (_| |
 *  |_| |_| |_|\___|\__,_|\___\___/|_|  \___|      \__,_|\__,_|
 *
 * This program is free software: you can redistribute and/or modify
 * it under the terms of the CSSM Unlimited License v2.0.
 *
 * This license permits unlimited use, modification, and distribution
 * for any purpose while maintaining authorship attribution.
 *
 * The software is provided "as is" without warranty of any kind.
 *
 * @author MedCore Ukraine
 * @link https://medcore.pp.ua/
 *
 *
 */

namespace App\Domain\Dashboard;

use App\Domain\Appointment\AppointmentRepository;
use App\Domain\Billing\InvoiceRepository;
use App\Domain\Inventory\InventoryItemRepository;
use App\Domain\Kpi\KpiRepository;
use App\Domain\Kpi\KpiResultRepository;
use App\Domain\LabOrder\LabOrderRepository;
use App\Domain\Patient\PatientRepository;
use DateTime;

class DashboardService
{
    private KpiRepository $kpiRepository;
    private KpiResultRepository $kpiResultRepository;
    private InvoiceRepository $invoiceRepository;
    private PatientRepository $patientRepository;
    private AppointmentRepository $appointmentRepository;
    private LabOrderRepository $labOrderRepository;
    private InventoryItemRepository $inventoryItemRepository;

    public function __construct(
        KpiRepository $kpiRepository,
        KpiResultRepository $kpiResultRepository,
        InvoiceRepository $invoiceRepository,
        PatientRepository $patientRepository,
        AppointmentRepository $appointmentRepository,
        LabOrderRepository $labOrderRepository,
        InventoryItemRepository $inventoryItemRepository
    ) {
        $this->kpiRepository = $kpiRepository;
        $this->kpiResultRepository = $kpiResultRepository;
        $this->invoiceRepository = $invoiceRepository;
        $this->patientRepository = $patientRepository;
        $this->appointmentRepository = $appointmentRepository;
        $this->labOrderRepository = $labOrderRepository;
        $this->inventoryItemRepository = $inventoryItemRepository;
    }

    /**
     * Fetches the latest KPI results and chart data for the dashboard.
     *
     * @return array An associative array containing kpi_results and chart_data.
     */
    public function getDashboardData() : array
    {
        $dashboardKpis = [];
        $kpiDefinitions = $this->kpiRepository->findActiveKpiDefinitions();

        foreach ($kpiDefinitions as $definition) {
            $latestResult = $this->kpiResultRepository->findLatestResult($definition['id'], $definition['period'] ?? 'day');
            if (! $latestResult) {
                continue;
            }

            $previousResult = $this->kpiResultRepository->findResultForPreviousPeriod(
                $definition['id'],
                $latestResult['period_end'],
                'day'
            );

            $dashboardKpis[] = [
                'definition' => $definition,
                'latest_value' => (float)$latestResult['calculated_value'],
                'period_start' => $latestResult['period_start'],
                'period_end' => $latestResult['period_end'],
                'trend' => $this->calculateTrend($latestResult['calculated_value'], $previousResult['calculated_value'] ?? null)
            ];
        }

        // Prepare data for the revenue chart
        $endDate = new DateTime();
        $startDate = (new DateTime())->modify('-6 days');
        $rawChartData = $this->invoiceRepository->getDailyRevenueForPeriod($startDate->format('Y-m-d'), $endDate->format('Y-m-d'));

        // Format data for Chart.js
        $chartData = [
            'labels' => [],
            'data' => [],
        ];
        $revenueByDate = array_column($rawChartData, 'total_revenue', 'date');

        for ($i = 0; $i < 7; $i++) {
            $date = (new DateTime())->modify("-$i days")->format('Y-m-d');
            $chartData['labels'][] = $date;
            $chartData['data'][] = (float)($revenueByDate[$date] ?? 0.0);
        }
        // Reverse to show oldest date first
        $chartData['labels'] = array_reverse($chartData['labels']);
        $chartData['data'] = array_reverse($chartData['data']);

        $startPeriod = (new DateTime())->modify('-6 days')->format('Y-m-d');
        $endPeriod = (new DateTime())->format('Y-m-d');

        $quickStats = [
            'patients_total' => $this->patientRepository->countAll(),
            'appointments_today' => $this->appointmentRepository->countScheduledByDate((new DateTime())->format('Y-m-d')),
            'revenue_7d' => $this->invoiceRepository->sumRevenueForPeriod($startPeriod, $endPeriod),
            'lab_pending' => $this->labOrderRepository->countByStatus(['ordered', 'in_progress']),
            'low_stock' => $this->inventoryItemRepository->countItemsBelowMinStock(),
        ];

        return [
            'kpis' => $dashboardKpis,
            'revenueChart' => $chartData,
            'quickStats' => $quickStats,
        ];
    }

    private function calculateTrend(float $currentValue, ?float $previousValue) : ?string
    {
        if (null === $previousValue || 0.0 === $previousValue) {
            return null; // No previous data or no change if previous was zero
        }

        $percentageChange = (($currentValue - $previousValue) / $previousValue) * 100;

        if ($percentageChange > 0) {
            return sprintf('+%.1f%%', $percentageChange);
        } elseif ($percentageChange < 0) {
            return sprintf('%.1f%%', $percentageChange);
        } else {
            return '0.0%';
        }
    }
}
