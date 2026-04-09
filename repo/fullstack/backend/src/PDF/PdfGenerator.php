<?php
declare(strict_types=1);
namespace App\PDF;

use App\Entity\Bill;
use App\Entity\Payment;
use App\Entity\Refund;
use App\Enum\BillStatus;
use App\ValueObject\SensitiveId;

class PdfGenerator
{
    public function generateBillPdf(Bill $bill, array $payments = [], array $refunds = []): string
    {
        $statusLabel = match ($bill->getStatus()) {
            BillStatus::PAID => 'PAID',
            BillStatus::PARTIALLY_REFUNDED => 'PARTIALLY REFUNDED',
            BillStatus::VOIDED => 'VOIDED',
            default => strtoupper($bill->getStatus()->value),
        };

        $html = '<!DOCTYPE html><html><head><style>';
        $html .= 'body { font-family: Arial, sans-serif; margin: 40px; }';
        $html .= '.header { text-align: center; margin-bottom: 30px; }';
        $html .= '.status { font-size: 24px; font-weight: bold; padding: 10px; border: 2px solid #333; display: inline-block; }';
        $html .= 'table { width: 100%; border-collapse: collapse; margin-top: 20px; }';
        $html .= 'th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }';
        $html .= 'th { background-color: #f4f4f4; }';
        $html .= '.total { font-weight: bold; font-size: 16px; }';
        $html .= '</style></head><body>';

        $html .= '<div class="header">';
        $html .= '<h1>Invoice / Receipt</h1>';
        $html .= '<div class="status">' . htmlspecialchars($statusLabel) . '</div>';
        $html .= '</div>';

        $html .= '<table>';
        $html .= '<tr><th>Bill ID</th><td>' . htmlspecialchars(SensitiveId::mask($bill->getId())) . '</td></tr>';
        $html .= '<tr><th>Type</th><td>' . htmlspecialchars($bill->getBillType()->value) . '</td></tr>';
        $html .= '<tr><th>Currency</th><td>' . htmlspecialchars($bill->getCurrency()) . '</td></tr>';
        $html .= '<tr><th>Original Amount</th><td>' . htmlspecialchars($bill->getOriginalAmount()) . '</td></tr>';
        $html .= '<tr><th>Outstanding Amount</th><td>' . htmlspecialchars($bill->getOutstandingAmount()) . '</td></tr>';
        $html .= '<tr><th>Issued At</th><td>' . $bill->getIssuedAt()->format('Y-m-d H:i:s') . '</td></tr>';
        if ($bill->getDueAt()) {
            $html .= '<tr><th>Due At</th><td>' . $bill->getDueAt()->format('Y-m-d H:i:s') . '</td></tr>';
        }
        $html .= '</table>';

        if (!empty($payments)) {
            $html .= '<h2>Payments</h2><table>';
            $html .= '<tr><th>Date</th><th>Amount</th><th>Status</th></tr>';
            foreach ($payments as $payment) {
                $html .= '<tr>';
                $html .= '<td>' . $payment->getCreatedAt()->format('Y-m-d H:i:s') . '</td>';
                $html .= '<td>' . htmlspecialchars($payment->getAmount()) . '</td>';
                $html .= '<td>' . htmlspecialchars($payment->getStatus()->value) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</table>';
        }

        if (!empty($refunds)) {
            $html .= '<h2>Refunds</h2><table>';
            $html .= '<tr><th>Date</th><th>Amount</th><th>Reason</th></tr>';
            foreach ($refunds as $refund) {
                $html .= '<tr>';
                $html .= '<td>' . $refund->getCreatedAt()->format('Y-m-d H:i:s') . '</td>';
                $html .= '<td>' . htmlspecialchars($refund->getAmount()) . '</td>';
                $html .= '<td>' . htmlspecialchars($refund->getReason()) . '</td>';
                $html .= '</tr>';
            }
            $html .= '</table>';
        }

        $html .= '</body></html>';

        if (!class_exists(\Dompdf\Dompdf::class)) {
            throw new \RuntimeException('PDF generation requires DomPDF. Install dompdf/dompdf.');
        }

        $dompdf = new \Dompdf\Dompdf();
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        return $dompdf->output();
    }
}
