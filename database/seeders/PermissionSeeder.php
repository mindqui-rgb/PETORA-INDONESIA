<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Permission;
use App\Domain\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (Permission::cases() as $permission) {
            DB::table('permissions')->insertOrIgnore([
                'id' => (string) Str::uuid(),
                'code' => $permission->value,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        foreach ($this->rolePermissions() as $role => $permissions) {
            foreach ($permissions as $permission) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role' => $role,
                    'permission_id' => DB::table('permissions')
                        ->where('code', $permission->value)
                        ->value('id'),
                ]);
            }
        }
    }

    /** @return array<string, list<Permission>> */
    private function rolePermissions(): array
    {
        return [
            Role::Owner->value => Permission::cases(),
            Role::Doctor->value => [
                Permission::InventoryRead,
                Permission::AppointmentManage,
                Permission::VisitManage,
                Permission::MedicalRecordRead,
                Permission::MedicalRecordWrite,
                Permission::MedicalRecordSign,
                Permission::MedicalRecordAmend,
                Permission::PrescriptionWrite,
                Permission::MedicationDispense,
                Permission::HospitalizationManage,
                Permission::HospitalizationObserve,
                Permission::HospitalizationPublishUpdate,
            ],
            Role::Admin->value => [
                Permission::ProductManage,
                Permission::InventoryRead,
                Permission::InventoryCount,
                Permission::InventoryTransferCreate,
                Permission::InventoryTransferReceive,
                Permission::PurchaseManage,
                Permission::PosSell,
                Permission::PosDiscountApply,
                Permission::PosReturnRequest,
                Permission::CashShiftManage,
                Permission::PaymentReview,
                Permission::PaymentConfirm,
                Permission::StockReviewResolve,
                Permission::FulfillmentManage,
                Permission::RefundRequest,
                Permission::CustomerManage,
                Permission::PetManage,
                Permission::ScheduleManage,
                Permission::AppointmentManage,
                Permission::HospitalizationManage,
                Permission::GroomingManage,
                Permission::ServiceInvoiceManage,
                Permission::FinanceExpenseCreate,
                Permission::SalesReportRead,
            ],
            Role::Customer->value => [],
        ];
    }
}
