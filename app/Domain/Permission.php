<?php

declare(strict_types=1);

namespace App\Domain;

enum Permission: string
{
    case BranchManage = 'branch.manage';
    case UserManage = 'user.manage';
    case SettingsManage = 'settings.manage';
    case ProductManage = 'product.manage';
    case PriceManage = 'price.manage';
    case InventoryRead = 'inventory.read';
    case InventoryReadCost = 'inventory.read_cost';
    case InventoryAdjust = 'inventory.adjust';
    case InventoryCount = 'inventory.count';
    case InventoryTransferCreate = 'inventory.transfer.create';
    case InventoryTransferApprove = 'inventory.transfer.approve';
    case InventoryTransferReceive = 'inventory.transfer.receive';
    case PurchaseManage = 'purchase.manage';
    case PosSell = 'pos.sell';
    case PosDiscountApply = 'pos.discount.apply';
    case PosDiscountOverride = 'pos.discount.override';
    case PosReturnRequest = 'pos.return.request';
    case CashShiftManage = 'cash_shift.manage';
    case PaymentReview = 'payment.review';
    case PaymentConfirm = 'payment.confirm';
    case StockReviewResolve = 'stock_review.resolve';
    case FulfillmentManage = 'fulfillment.manage';
    case RefundRequest = 'refund.request';
    case RefundApprove = 'refund.approve';
    case RefundComplete = 'refund.complete';
    case CustomerManage = 'customer.manage';
    case PetManage = 'pet.manage';
    case ScheduleManage = 'schedule.manage';
    case AppointmentManage = 'appointment.manage';
    case VisitManage = 'visit.manage';
    case MedicalRecordRead = 'medical_record.read';
    case MedicalRecordWrite = 'medical_record.write';
    case MedicalRecordSign = 'medical_record.sign';
    case MedicalRecordAmend = 'medical_record.amend';
    case PrescriptionWrite = 'prescription.write';
    case MedicationDispense = 'medication.dispense';
    case HospitalizationManage = 'hospitalization.manage';
    case HospitalizationObserve = 'hospitalization.observe';
    case HospitalizationPublishUpdate = 'hospitalization.publish_update';
    case GroomingManage = 'grooming.manage';
    case ServiceInvoiceManage = 'service_invoice.manage';
    case ServiceInvoiceDiscount = 'service_invoice.discount';
    case ServiceInvoiceVoid = 'service_invoice.void';
    case FinanceCashRead = 'finance.cash.read';
    case FinanceExpenseCreate = 'finance.expense.create';
    case FinanceExpenseApprove = 'finance.expense.approve';
    case FinanceReportRead = 'finance.report.read';
    case FinancePeriodClose = 'finance.period.close';
    case SalesReportRead = 'report.sales.read';
    case AuditRead = 'audit.read';

    public function requiresClinician(): bool
    {
        return in_array($this, [
            self::MedicalRecordWrite,
            self::MedicalRecordSign,
            self::MedicalRecordAmend,
            self::PrescriptionWrite,
            self::MedicationDispense,
            self::HospitalizationObserve,
            self::HospitalizationPublishUpdate,
        ], true);
    }

    public function requiresAssignment(Role $role): bool
    {
        if ($role === Role::Doctor) {
            return in_array($this, [
                self::AppointmentManage,
                self::VisitManage,
                self::MedicalRecordWrite,
                self::MedicalRecordSign,
                self::MedicalRecordAmend,
                self::PrescriptionWrite,
                self::HospitalizationPublishUpdate,
            ], true);
        }

        return $role === Role::Owner && in_array($this, [
            self::MedicalRecordSign,
            self::MedicalRecordAmend,
        ], true);
    }

    public function requiresMfa(): bool
    {
        return in_array($this, [
            self::PaymentConfirm,
            self::RefundApprove,
            self::RefundComplete,
            self::SettingsManage,
            self::MedicalRecordSign,
            self::MedicalRecordAmend,
            self::UserManage,
            self::FinanceCashRead,
            self::FinanceExpenseCreate,
            self::FinanceExpenseApprove,
            self::FinanceReportRead,
            self::FinancePeriodClose,
        ], true);
    }
}