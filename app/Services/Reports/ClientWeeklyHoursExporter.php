<?php

namespace App\Services\Reports;

use Carbon\Carbon;
use Dompdf\Dompdf;
use Dompdf\Options;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Illuminate\Support\Str;

class ClientWeeklyHoursExporter
{
    public function export(array $report, string $directory, bool $draft): array
    {
        if (! $draft && ! $report['safe_to_send_client']) {
            throw new \RuntimeException('El reporte tiene advertencias; solo puede exportarse con --draft.');
        }
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new \RuntimeException('No se pudo crear el directorio de reportes.');
        }
        $suffix = $draft ? '_DRAFT' : '';
        $pdfPath = $directory.'/time_activity_report_'.$report['from'].'_'.$report['to'].$suffix.'.pdf';
        $xlsxPath = $directory.'/client_weekly_hours_'.$report['from'].'_'.$report['to'].$suffix.'.xlsx';

        $this->writePdf($report, $pdfPath);
        $this->writeXlsx($report, $xlsxPath);

        $campaignPdfs = [];
        foreach ($report['campaigns'] as $campaign) {
            $name = strtoupper(Str::slug($campaign['name'], '_'));
            $campaignPath = $directory.'/time_activity_report_'.$name.'_'.$report['from'].'_'.$report['to'].$suffix.'.pdf';
            $this->writePdf([
                ...$report,
                'campaigns' => [$campaign],
                'grand_total_seconds' => $campaign['total_seconds'],
            ], $campaignPath);
            $campaignPdfs[$campaign['name']] = $campaignPath;
        }

        return ['pdf' => $pdfPath, 'xlsx' => $xlsxPath, 'campaign_pdfs' => $campaignPdfs];
    }

    public static function hours(int $seconds): string
    {
        $seconds = max($seconds, 0);

        return sprintf('%d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }

    private function writePdf(array $report, string $path): void
    {
        $logoPath = public_path('images/dosys-logo.jpg');
        $logo = is_file($logoPath) ? 'data:image/jpeg;base64,'.base64_encode(file_get_contents($logoPath)) : null;
        $html = view('reports.client-weekly-hours', compact('report', 'logo'))->render();
        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);
        $pdf = new Dompdf($options);
        $pdf->setPaper('legal', 'landscape');
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->render();
        file_put_contents($path, $pdf->output());
    }

    private function writeXlsx(array $report, string $path): void
    {
        $book = new Spreadsheet;
        $summary = $book->getActiveSheet();
        $summary->setTitle('ALL');
        $summary->fromArray([
            ['Time & Activity Report'],
            ['DOSYS BPO LLC', $report['from'].' - '.$report['to']],
            ['Grand total client billable hours', $report['grand_total_seconds'] / 86400],
            ['Avg. Activity', '-'],
            ['Total spent', '-'],
            ['Safe to send client', $report['safe_to_send_client'] ? 'YES' : 'NO'],
        ], null, 'A1');
        $summary->getStyle('B3')->getNumberFormat()->setFormatCode('[h]:mm');
        $row = 8;
        foreach ($report['campaigns'] as $campaign) {
            $summary->setCellValue('A'.$row, $campaign['name']);
            $summary->setCellValue('B'.$row, $campaign['total_seconds'] / 86400);
            $summary->getStyle('B'.$row)->getNumberFormat()->setFormatCode('[h]:mm');
            $row++;
        }

        $used = ['ALL', 'AUDIT', 'SCHEDULE_AUDIT', 'WARNINGS'];
        foreach ($report['campaigns'] as $campaign) {
            $title = mb_substr(preg_replace('/[\\\\\/?*\[\]:]/', '-', $campaign['name']), 0, 31);
            $base = $title;
            for ($index = 2; in_array($title, $used, true); $index++) {
                $title = mb_substr($base, 0, 28).'-'.$index;
            }
            $used[] = $title;
            $sheet = $book->createSheet();
            $sheet->setTitle($title);
            $sheet->setCellValue('A1', 'Time & Activity Report');
            $sheet->setCellValue('A2', $campaign['name'].' | '.$report['from'].' - '.$report['to']);
            $line = 5;
            foreach ($report['weeks'] as $week) {
                $members = array_values(array_filter($week['members'], fn (array $member): bool => $member['campaign'] === $campaign['name']));
                if (! $members) {
                    continue;
                }
                $sheet->setCellValue('A'.$line, $week['start'].' - '.$week['end']);
                $line++;
                $headings = ['Project', 'Member'];
                for ($offset = 0; $offset < 7; $offset++) {
                    $headings[] = Carbon::parse($week['start'])->addDays($offset)->format('D, M d, Y');
                }
                $headings[] = '';
                $sheet->fromArray($headings, null, 'A'.$line);
                $sheet->getStyle('A'.$line.':J'.$line)->getFont()->setBold(true);
                $line++;
                foreach ($members as $member) {
                    $values = [$member['campaign'], $member['employee']];
                    foreach ($member['daily'] as $date => $seconds) {
                        $values[] = $date > $report['to'] || $date < $report['from'] ? null
                            : ($seconds === null ? null : $seconds / 86400);
                    }
                    $values[] = $member['total_seconds'] / 86400;
                    $sheet->fromArray($values, null, 'A'.$line);
                    $sheet->getStyle('C'.$line.':J'.$line)->getNumberFormat()->setFormatCode('[h]:mm');
                    $line++;
                }
                $line += 2;
            }
            $sheet->getColumnDimension('A')->setWidth(23);
            $sheet->getColumnDimension('B')->setWidth(40);
            foreach (range('C', 'J') as $column) {
                $sheet->getColumnDimension($column)->setWidth(19);
            }
        }

        $audit = $book->createSheet();
        $audit->setTitle('AUDIT');
        $audit->fromArray(['Week Start', 'Week End', 'Campaign', 'Employee', 'Date', 'Expected Regular',
            'Approved OT', 'Base Client Hours', 'Expected Trackable', 'Activity Source', 'Desktop Activity',
            'Justified Time', 'Remaining Gap', 'Rule Applied', 'Client Billable Hours', 'Comment/Reason', 'Review Status'], null, 'A1');
        $audit->getStyle('A1:Q1')->getFont()->setBold(true);
        foreach ($report['days'] as $index => $day) {
            $line = $index + 2;
            $audit->fromArray([
                $day['week_start'], $day['week_end'], $day['campaign'], $day['employee'], $day['date'],
                $day['expected_regular_seconds'] / 86400, $day['approved_ot_seconds'] / 86400,
                $day['base_seconds'] / 86400, $day['expected_trackable_seconds'] / 86400,
                $day['activity_source'], $day['desktop_activity_seconds'] === null ? null : $day['desktop_activity_seconds'] / 86400,
                $day['justified_seconds'] / 86400, $day['remaining_gap_seconds'] / 86400,
                $day['rule'], $day['billable_seconds'] / 86400, $day['comment'], $day['review_status'],
            ], null, 'A'.$line);
            foreach (['F', 'G', 'H', 'I', 'K', 'L', 'M', 'O'] as $column) {
                $audit->getStyle($column.$line)->getNumberFormat()->setFormatCode('[h]:mm');
            }
        }
        $audit->freezePane('F2');
        $audit->setAutoFilter('A1:Q'.max(count($report['days']) + 1, 2));

        $scheduleAudit = $book->createSheet();
        $scheduleAudit->setTitle('SCHEDULE_AUDIT');
        $scheduleAudit->fromArray(['Week Start', 'Campaign', 'Employee', 'Template', 'Weekly Ordinary', 'Weekly Preassigned OT',
            'Mon Regular', 'Tue Regular', 'Wed Regular', 'Thu Regular', 'Fri Regular', 'Sat Regular', 'Sun Regular',
            'Mon Assigned OT', 'Tue Assigned OT', 'Wed Assigned OT', 'Thu Assigned OT', 'Fri Assigned OT', 'Sat Assigned OT', 'Sun Assigned OT'], null, 'A1');
        $scheduleAudit->getStyle('A1:T1')->getFont()->setBold(true);
        $dayLookup = collect($report['days'])->keyBy(fn (array $day): string => $day['employee_id'].'|'.$day['date']);
        $line = 2;
        foreach ($report['weeks'] as $week) {
            foreach ($week['members'] as $member) {
                $days = [];
                foreach (array_keys($member['daily']) as $date) {
                    $days[] = $dayLookup->get($member['employee_id'].'|'.$date);
                }
                $first = collect($days)->first(fn (?array $day): bool => $day !== null);
                $scheduleAudit->fromArray([
                    $week['start'], $member['campaign'], $member['employee'], $first['schedule_name'] ?? '',
                    $member['expected_weekly_regular_seconds'] / 86400, $member['expected_weekly_ot_seconds'] / 86400,
                    ...array_map(fn (?array $day) => $day === null ? null : $day['expected_regular_seconds'] / 86400, $days),
                    ...array_map(fn (?array $day) => $day === null ? null : $day['approved_ot_seconds'] / 86400, $days),
                ], null, 'A'.$line);
                $scheduleAudit->getStyle('E'.$line.':T'.$line)->getNumberFormat()->setFormatCode('[h]:mm');
                $line++;
            }
        }
        $scheduleAudit->freezePane('G2');
        $scheduleAudit->setAutoFilter('A1:T'.max($line - 1, 2));

        $warnings = $book->createSheet();
        $warnings->setTitle('WARNINGS');
        $warnings->fromArray(['Code', 'Employee', 'Date', 'Message'], null, 'A1');
        foreach ($report['warnings'] as $index => $warning) {
            $warnings->fromArray(array_values($warning), null, 'A'.($index + 2));
        }
        foreach (range('A', 'D') as $column) {
            $warnings->getColumnDimension($column)->setAutoSize(true);
        }
        $book->setActiveSheetIndex(0);
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
    }
}
