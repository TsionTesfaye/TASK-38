<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Bill;
use App\Entity\User;
use App\PDF\PdfGenerator;
use App\Repository\PaymentRepository;
use App\Repository\RefundRepository;
use App\Storage\LocalStorageService;
use Symfony\Component\Uid\Uuid;

class PdfService
{
    public function __construct(
        private readonly PdfGenerator $pdfGenerator,
        private readonly PaymentRepository $paymentRepo,
        private readonly RefundRepository $refundRepo,
        private readonly LocalStorageService $storage,
    ) {}

    public function generateBillPdf(Bill $bill): string
    {
        $payments = $this->paymentRepo->findByBillId($bill->getId());
        $refunds = $this->refundRepo->findByBillId($bill->getId());
        $content = $this->pdfGenerator->generateBillPdf($bill, $payments, $refunds);
        $filename = sprintf('bill_%s_%s.pdf', substr($bill->getId(), -8), date('Ymd_His'));
        $path = $this->storage->storePdf($content, $filename);
        $bill->setPdfPath($path);
        return $path;
    }

    public function regenerateBillPdf(Bill $bill): string
    {
        return $this->generateBillPdf($bill);
    }
}
